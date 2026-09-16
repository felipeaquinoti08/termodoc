<?php

namespace GlpiPlugin\Termodocs;

use Document as GlpiDocument;
use GlpiPlugin\Termodocs\Signature\AssineiApiClient;
use GlpiPlugin\Termodocs\Signature\AssineiDigitalProvider;
use Session;
use User;

/**
 * Brings a document that was already signed on Assinei.digital *before*
 * this integration existed into GLPI, as a normal termodocs Document -
 * see the "aba de importação somente para o assinei" request: unlike
 * DocumentImporter (cross-instance JSON export/import of documents this
 * plugin itself generated), there is no rendered_html/content_hash to
 * recover here, only whatever Assinei's API still has on file for that
 * document id.
 *
 * Assinei has no concept of GLPI's asset inventory or of the
 * "deliverer/TI" counterpart a delivery/return term requires - only the
 * document itself and whichever participants signed it - so both are
 * always supplied by the admin doing the import, never inferred from
 * Assinei's response.
 */
class AssineiImporter
{
    /**
     * Accepts a bare UUID, a `.../documentos/{uuid}` link, or a
     * `.../assinatura/?d={uuid}&...` link (both real formats confirmed
     * against a live tenant) and returns just the documento id, or null
     * if nothing UUID-shaped could be found.
     */
    public static function parseLink(string $input): ?string
    {
        $input = trim($input);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $input)) {
            return strtolower($input);
        }

        if (preg_match('/[?&]d=([0-9a-f-]{36})/i', $input, $m)) {
            return strtolower($m[1]);
        }

        if (preg_match('#/documentos/([0-9a-f-]{36})#i', $input, $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    /**
     * Optional `e=` participant e-mail hint carried by the
     * `/assinatura/?d=...&e=...` link form - not present on the plain
     * `/documentos/{uuid}` form.
     */
    public static function parseEmailHint(string $input): ?string
    {
        if (preg_match('/[?&]e=([^&]+)/i', trim($input), $m)) {
            $email = urldecode($m[1]);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }
        return null;
    }

    /**
     * Fetches the live document from Assinei and decorates each
     * participant with whichever GLPI user matches their e-mail (if
     * any), so the wizard can pre-fill the recipient picker instead of
     * always starting empty.
     *
     * @return array{titulo:string,status:int,participants:array<int,array<string,mixed>>,any_signed:bool,raw:array<string,mixed>}
     * @throws \RuntimeException if the document id doesn't resolve
     */
    public static function lookup(string $documento_id, ?string $email_hint = null): array
    {
        $client = new AssineiApiClient();
        $response = $client->getDocument($documento_id);
        $data = $response['data'] ?? null;
        if (!is_array($data) || empty($data['id'])) {
            throw new \RuntimeException(__('Documento não encontrado no Assinei.digital.', 'termodocs'));
        }

        $participants = [];
        $any_signed = false;
        foreach ((array) ($data['participantesDocumento'] ?? []) as $p) {
            $email = (string) ($p['participanteEmail'] ?? '');
            $signed = !empty($p['assinado']);
            $any_signed = $any_signed || $signed;

            $user = null;
            if ($email !== '') {
                $candidate = new User();
                if ($candidate->getFromDBbyEmail($email)) {
                    $user = $candidate;
                }
            }

            $participants[] = [
                'nome'      => (string) ($p['participanteNome'] ?? ''),
                'email'     => $email,
                'assinado'  => $signed,
                'users_id'  => $user?->getID() ?? 0,
                'user_name' => $user?->getFriendlyName() ?? '',
            ];
        }

        // The e-mail hint from an `/assinatura/?...&e=` link identifies
        // which participant to default the recipient picker to, when a
        // document has more than one - falls back to the first
        // participant otherwise.
        $suggested_recipient = 0;
        if ($email_hint !== null) {
            foreach ($participants as $p) {
                if (strcasecmp($p['email'], $email_hint) === 0) {
                    $suggested_recipient = $p['users_id'];
                    break;
                }
            }
        }
        if ($suggested_recipient === 0 && !empty($participants)) {
            $suggested_recipient = $participants[0]['users_id'];
        }

        return [
            'titulo'              => (string) ($data['titulo'] ?? ''),
            'status'              => (int) ($data['status'] ?? 0),
            'participants'        => $participants,
            'any_signed'          => $any_signed,
            'suggested_recipient' => $suggested_recipient,
            'raw'                 => $data,
        ];
    }

    /**
     * Downloads the signed PDF and creates the termodocs Document from
     * scratch: no original rendered_html exists to recover, so a plain
     * informational placeholder stands in for it (the downloaded PDF -
     * stored as pdf_document_id, same as any normally generated document
     * - is what actually carries the legal content). Both roles are
     * recorded via Acceptance::acceptExternal() so the existing
     * recompute/asset-assignment machinery (Acceptance::recomputeDocumentStatus())
     * finalizes status=ACCEPTED and applies asset ownership exactly like
     * a real-time signature would - the recipient's signature is
     * attributed to Assinei (this is what actually happened), the
     * deliverer's is recorded as of the moment of import, since Assinei
     * never tracked a second signer for these historical documents.
     *
     * @param array<int,array{0:string,1:int}> $items [itemtype, items_id] pairs
     */
    public static function import(
        string $documento_id,
        int $operation_type,
        int $templates_id,
        int $users_id_recipient,
        int $users_id_deliverer,
        array $items
    ): Document {
        $client = new AssineiApiClient();
        $response = $client->getDocument($documento_id);
        $data = $response['data'] ?? null;
        if (!is_array($data) || empty($data['id'])) {
            throw new \RuntimeException(__('Documento não encontrado no Assinei.digital.', 'termodocs'));
        }

        $recipient = new User();
        if (!$recipient->getFromDB($users_id_recipient)) {
            throw new \RuntimeException(__('Colaborador inválido.', 'termodocs'));
        }
        $deliverer = new User();
        if (!$deliverer->getFromDB($users_id_deliverer)) {
            throw new \RuntimeException(__('Responsável (TI) inválido.', 'termodocs'));
        }

        $pdf_url = (string) ($data['urlDocumentoAssinado'] ?? $data['urlDocumento'] ?? '');
        if ($pdf_url === '') {
            throw new \RuntimeException(__('Este documento não possui um PDF disponível para download.', 'termodocs'));
        }

        $pdf_bytes = $client->downloadFile($pdf_url);
        $titulo = (string) ($data['titulo'] ?: __('Documento importado', 'termodocs'));
        $reference_code = Document::generateReferenceCode();

        $basename = 'termodocs_assinei_' . bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents(GLPI_TMP_DIR . '/' . $basename, $pdf_bytes);

        $entities_id = (int) Session::getActiveEntity();

        $glpi_document = new GlpiDocument();
        $glpi_document->add([
            'name'        => $titulo . ' [' . $reference_code . ']',
            'entities_id' => $entities_id,
            '_filename'   => [$basename],
        ]);
        $pdf_documents_id = (int) $glpi_document->getID();
        if ($pdf_documents_id <= 0) {
            throw new \RuntimeException(__('Falha ao salvar o PDF importado.', 'termodocs'));
        }

        $rendered_html = '<div class="td-page"><div class="td-content">'
            . '<p>' . __('Documento importado do Assinei.digital - o conteúdo original está disponível no PDF anexado.', 'termodocs') . '</p>'
            . '<p><strong>' . __('Título original', 'termodocs') . ':</strong> ' . htmlspecialchars($titulo) . '</p>'
            . '</div></div>';

        $date_documento = (string) ($data['dataDocumento'] ?? $data['createdAt'] ?? '');
        $date_generated = null;
        if ($date_documento !== '') {
            try {
                $date_generated = (new \DateTime($date_documento))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $date_generated = null;
            }
        }

        $document = new Document();
        $document->add([
            'plugin_termodocs_templates_id' => $templates_id,
            'name'                          => $titulo . ' [' . $reference_code . ']',
            'rendered_html'                 => $rendered_html,
            'content_hash'                  => hash('sha256', $rendered_html),
            'theme_css_snapshot'            => '',
            'signature_provider'            => AssineiDigitalProvider::KEY,
            'external_reference'            => $documento_id,
            'external_payload'              => json_encode($data),
            'users_id_recipient'            => $users_id_recipient,
            'users_id_deliverer'            => $users_id_deliverer,
            'users_id_requester'            => Session::getLoginUserID(),
            'status'                        => Document::WAITING_ACCEPTANCE,
            'pdf_document_id'               => $pdf_documents_id,
            'entities_id'                   => $entities_id,
            'date_generated'                => $date_generated ?? date('Y-m-d H:i:s'),
        ]);

        foreach ($items as [$itemtype, $items_id]) {
            (new Document_Item())->add([
                'plugin_termodocs_documents_id' => $document->getID(),
                'itemtype'                      => $itemtype,
                'items_id'                      => $items_id,
            ]);
        }

        Acceptance::acceptExternal(
            $document,
            Acceptance::ROLE_RECIPIENT,
            $users_id_recipient,
            $recipient->getFriendlyName(),
            AssineiDigitalProvider::KEY,
            $documento_id,
            $data
        );

        Acceptance::acceptExternal(
            $document,
            Acceptance::ROLE_DELIVERER,
            $users_id_deliverer,
            $deliverer->getFriendlyName(),
            AssineiDigitalProvider::KEY,
            $documento_id,
            $data
        );

        $document->getFromDB($document->getID());
        return $document;
    }
}
