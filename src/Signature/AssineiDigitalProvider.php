<?php

namespace GlpiPlugin\Termodocs\Signature;

use GlpiPlugin\Termodocs\Acceptance;
use GlpiPlugin\Termodocs\Document;
use Session;
use Toolbox;
use User;

/**
 * V2 signature provider: hands the document off to Assinei.digital
 * (Aliare) instead of collecting acceptance inside GLPI. initiate()
 * uploads the already-generated PDF and both parties as signers and
 * sends it for signature; Assinei then e-mails each signer a link to
 * its own hosted signing page. handleCallback() reacts to the webhook
 * Assinei fires as each participant signs/refuses (see
 * front/webhook.php), recording it the same way the internal flow does
 * (Acceptance -> Document status -> asset assignment) so nothing else
 * in the plugin needs to know which provider is in play.
 *
 * The exact webhook payload shape is not published in Assinei's docs
 * beyond the event name list, so extractEventType()/findRole() are
 * deliberately defensive (several candidate field names, logged
 * fallback) - see handleCallback()'s doc comment.
 */
class AssineiDigitalProvider implements SignatureProviderInterface
{
    public const KEY = 'assinei';

    public function getKey(): string
    {
        return self::KEY;
    }

    public function getLabel(): string
    {
        return __('Assinei.digital', 'termodocs');
    }

    public function initiate(Document $document): void
    {
        if (!AssineiConfig::isActive()) {
            $this->logAndStash($document, 'Assinei.digital não está configurado/ativo - documento ficou aguardando aceite sem ser enviado para assinatura externa. Configure em Configurar > Termodocs > Assinei.digital.');
            return;
        }

        $recipient = new User();
        $recipient_found = $recipient->getFromDB((int) $document->fields['users_id_recipient']);
        $deliverer = new User();
        $deliverer_found = $deliverer->getFromDB((int) $document->fields['users_id_deliverer']);

        foreach (['Colaborador' => [$recipient, $recipient_found], 'TI' => [$deliverer, $deliverer_found]] as $label => [$signer, $found]) {
            if (!$found || !$signer->getDefaultEmail()) {
                $this->logAndStash($document, "Participante ({$label}) sem e-mail cadastrado no GLPI - não é possível enviar para assinatura na Assinei.digital.");
                return;
            }
        }

        $pdf_base64 = $this->readPdfBase64((int) $document->fields['pdf_document_id']);
        if ($pdf_base64 === null) {
            $this->logAndStash($document, 'PDF do documento não encontrado em disco - não é possível enviar para a Assinei.digital.');
            return;
        }

        $participants = [
            ['nome' => $recipient->getFriendlyName(), 'email' => $recipient->getDefaultEmail()],
            ['nome' => $deliverer->getFriendlyName(), 'email' => $deliverer->getDefaultEmail()],
        ];

        try {
            $client = new AssineiApiClient();
            $created = $client->createDocumentWithParticipants(
                $document->fields['name'],
                $document->fields['name'] . '.pdf',
                $pdf_base64,
                $participants
            );

            $documento_id = $this->extractDocumentId($created);
            if ($documento_id === null) {
                throw new AssineiApiException('POST', 'NovoDocumento', null, json_encode($created), 'Resposta sem id de documento reconhecível.');
            }

            $client->sendForSignature($documento_id, Toolbox::getRemoteIpAddress() ?: '127.0.0.1');

            // Best-effort: matching each returned participant back to
            // recipient/deliverer by e-mail is only trustworthy right
            // now, before either party has touched anything on Assinei's
            // side - see resolveRole()'s doc comment. A failure here
            // doesn't undo the send above (the document is already out
            // for signature); it just means later matching falls back
            // to e-mail/name from the webhook or poll payload itself.
            $external_participants = $this->captureParticipantIds($client, $documento_id, $recipient, $deliverer);

            $document->update([
                'id'                    => $document->getID(),
                'external_reference'    => $documento_id,
                'external_payload'      => json_encode(['initiate_response' => $created]),
                'external_participants' => $external_participants !== null ? json_encode($external_participants) : null,
            ]);
        } catch (AssineiApiException $e) {
            $this->logAndStash($document, $e->getMessage(), [
                'method'   => $e->method,
                'url'      => $e->url,
                'status'   => $e->status_code,
                'response' => $e->raw_response,
            ]);
        }
    }

    /**
     * Called from front/webhook.php once the shared-secret check there
     * has already authenticated the request. Payload field names follow
     * the event list Assinei's docs enumerate (document_signed,
     * document_finished_signing, document_refused, ...) but their exact
     * JSON shape wasn't available without a live tenant to test against -
     * every extraction below tries a short list of plausible keys and
     * always stashes the raw payload onto the Document
     * (external_payload) so a mismatch is visible/debuggable from the
     * document itself instead of silently dropped.
     */
    public function handleCallback(array $payload): void
    {
        $documento_id = $payload['documentoId']
            ?? $payload['documento']['id']
            ?? $payload['documentId']
            ?? $payload['id']
            ?? null;

        if (!is_string($documento_id) || $documento_id === '') {
            $this->logRaw('Webhook Assinei.digital sem documentoId reconhecível.', $payload);
            return;
        }

        $document = new Document();
        if (!$document->getFromDBByCrit(['external_reference' => $documento_id])) {
            $this->logRaw("Webhook Assinei.digital referencia documentoId={$documento_id}, sem Document correspondente no GLPI.", $payload);
            return;
        }

        $document->update([
            'id'               => $document->getID(),
            'external_payload' => json_encode(['last_webhook' => $payload]),
        ]);

        $event = $payload['evento'] ?? $payload['event'] ?? $payload['tipo'] ?? $payload['eventType'] ?? '';
        $is_refusal = str_contains((string) $event, 'refus');
        $is_signed  = str_contains((string) $event, 'sign') || str_contains((string) $event, 'assinad');

        if (!$is_refusal && !$is_signed) {
            $this->logRaw("Webhook Assinei.digital com evento não reconhecido: '{$event}'.", $payload);
            return;
        }

        $participant_email = $payload['participanteEmail']
            ?? $payload['participante']['email']
            ?? $payload['email']
            ?? null;

        $participant_name = $payload['participanteNome']
            ?? $payload['participante']['nome']
            ?? $payload['nome']
            ?? ($participant_email ?? __('Desconhecido', 'termodocs'));

        $participant_id = $payload['participanteDocumentoId']
            ?? $payload['participante']['id']
            ?? $payload['participanteId']
            ?? null;

        $role = $this->resolveRole(
            $document,
            is_string($participant_email) ? $participant_email : null,
            is_string($participant_name) ? $participant_name : null,
            is_string($participant_id) ? $participant_id : null
        );
        if ($role === null) {
            $this->logRaw("Webhook Assinei.digital: participante ('{$participant_email}' / '{$participant_name}') não corresponde ao colaborador nem ao responsável de TI deste documento.", $payload);
            return;
        }

        $users_id = $this->resolveUserByEmail(is_string($participant_email) ? $participant_email : null);

        if ($is_refusal) {
            $reason = (string) ($payload['motivo'] ?? $payload['reason'] ?? $payload['justificativa'] ?? '');
            Acceptance::refuseExternal($document, $role, $users_id, (string) $participant_name, $reason, self::KEY, $documento_id, $payload);
        } else {
            Acceptance::acceptExternal($document, $role, $users_id, (string) $participant_name, self::KEY, $documento_id, $payload);
        }
    }

    /**
     * Polling fallback/complement to the webhook (see AssineiPoller's
     * cron task, "CheckSignatures") - checks GET
     * /v1/DocumentoBusca/{id}/Status for a document still
     * WAITING_ACCEPTANCE and records any participant's signature/refusal
     * found there that GLPI doesn't already have. Reuses
     * resolveRole()/resolveUserByEmail() below, the same matching
     * handleCallback() uses, so both paths stay consistent. Safe to call
     * repeatedly: Acceptance::accept/refuseExternal() are no-ops for a
     * role that's already recorded. Returns how many roles were newly
     * recorded on this call.
     */
    public function reconcile(Document $document): int
    {
        $external_reference = $document->fields['external_reference'] ?? null;
        if (!is_string($external_reference) || $external_reference === '') {
            return 0;
        }

        try {
            $response = (new AssineiApiClient())->getStatus($external_reference);
        } catch (AssineiApiException $e) {
            $this->logRaw('Poll de status falhou para documento #' . $document->getID() . ': ' . $e->getMessage(), []);
            return 0;
        }

        // Always stashed, whether or not anything actionable is found
        // below - the exact field names Assinei's tenant returns for
        // this call were never confirmed against a live signed document,
        // so this is what makes that visible (see the "Diagnóstico"
        // panel on the document's own page) without needing direct
        // access to wherever this GLPI is actually deployed.
        $document->update([
            'id'               => $document->getID(),
            'external_payload' => json_encode(['last_poll' => $response]),
        ]);

        $data = $response['data'] ?? $response;
        $participants = $data['participantesDocumento'] ?? $data['participantes'] ?? [];
        if (!is_array($participants)) {
            $this->logRaw('Poll de status: resposta sem lista de participantes reconhecível para documento #' . $document->getID() . '.', $response);
            return 0;
        }

        $changed = 0;
        foreach ($participants as $p) {
            if (!is_array($p)) {
                continue;
            }

            $email = $p['participanteEmail'] ?? $p['email'] ?? null;
            $email = is_string($email) ? $email : null;

            $signed = (bool) ($p['assinado'] ?? $p['assinou'] ?? false);
            $refused = (bool) ($p['recusado'] ?? $p['recusou'] ?? false);
            if (!$signed && !$refused) {
                continue;
            }

            $name = (string) ($p['participanteNome'] ?? $p['nome'] ?? ($email ?? __('Desconhecido', 'termodocs')));

            $participant_id = $p['participanteDocumentoId'] ?? $p['id'] ?? $p['participanteId'] ?? null;
            $participant_id = is_string($participant_id) ? $participant_id : null;

            $role = $this->resolveRole($document, $email, $name, $participant_id);
            if ($role === null) {
                $this->logRaw("Poll de status: participante ('{$email}' / '{$name}') não corresponde ao colaborador nem ao responsável de TI do documento #" . $document->getID() . '.', $p);
                continue;
            }
            if (Acceptance::hasSigned($document->getID(), $role)) {
                continue;
            }

            $users_id = $this->resolveUserByEmail($email);

            if ($refused) {
                Acceptance::refuseExternal($document, $role, $users_id, $name, '', self::KEY, $external_reference, $data);
            } else {
                Acceptance::acceptExternal($document, $role, $users_id, $name, self::KEY, $external_reference, $data);
            }
            $changed++;
        }

        return $changed;
    }

    /**
     * Matches, in order of trust: Assinei's own per-participant id
     * (captured once at send time - see initiate()'s
     * captureParticipantIds() - stable and outside either party's
     * control), then e-mail, then an exact name match. Name matching is
     * a last resort because a signer might be able to edit their own
     * display name on Assinei's hosted signing page before actually
     * signing - it's only trustworthy for the id-capture step itself,
     * immediately after initiate() creates the document and before
     * anyone has reached that page yet.
     */
    private function resolveRole(Document $document, ?string $email, ?string $name = null, ?string $participant_id = null): ?int
    {
        if ($participant_id !== null && $participant_id !== '') {
            $stored = json_decode((string) ($document->fields['external_participants'] ?? ''), true);
            if (is_array($stored)) {
                if (($stored['recipient'] ?? null) === $participant_id) {
                    return Acceptance::ROLE_RECIPIENT;
                }
                if (($stored['deliverer'] ?? null) === $participant_id) {
                    return Acceptance::ROLE_DELIVERER;
                }
            }
        }

        $recipient = new User();
        $recipient->getFromDB((int) $document->fields['users_id_recipient']);
        $deliverer = new User();
        $deliverer->getFromDB((int) $document->fields['users_id_deliverer']);

        if ($email !== null) {
            if (strcasecmp((string) $recipient->getDefaultEmail(), $email) === 0) {
                return Acceptance::ROLE_RECIPIENT;
            }
            if (strcasecmp((string) $deliverer->getDefaultEmail(), $email) === 0) {
                return Acceptance::ROLE_DELIVERER;
            }
        }

        if ($name !== null && trim($name) !== '') {
            if (strcasecmp(trim($recipient->getFriendlyName()), trim($name)) === 0) {
                return Acceptance::ROLE_RECIPIENT;
            }
            if (strcasecmp(trim($deliverer->getFriendlyName()), trim($name)) === 0) {
                return Acceptance::ROLE_DELIVERER;
            }
        }

        return null;
    }

    private function resolveUserByEmail(?string $email): int
    {
        if ($email === null || $email === '') {
            return 0;
        }

        $user = new User();
        return $user->getFromDBbyEmail($email) ? (int) $user->fields['id'] : 0;
    }

    /**
     * Response shape wasn't confirmed against a live tenant for the
     * combined NovoDocumento endpoint - the standalone "Documento"
     * endpoint's example returns a top-level `id`, others in the same
     * docs wrap the payload in `data`. Try both rather than guessing one.
     */
    private function extractDocumentId(array $response): ?string
    {
        $candidate = $response['id'] ?? $response['data']['id'] ?? $response['data'] ?? null;
        return is_string($candidate) && $candidate !== '' ? $candidate : null;
    }

    /**
     * Fetches the just-created document's participant list and matches
     * each one back to recipient/deliverer by e-mail - trustworthy only
     * at this exact moment (see resolveRole()'s doc comment) - to record
     * their Assinei-assigned ids for all future matching. Returns null
     * (rather than a partial map) on any failure: getParticipants()
     * erroring, an unrecognized response shape, or not finding both
     * parties, since a partial mapping would be worse than none (silently
     * misattributing whichever role wasn't captured).
     *
     * @return array{recipient:string,deliverer:string}|null
     */
    private function captureParticipantIds(AssineiApiClient $client, string $documento_id, User $recipient, User $deliverer): ?array
    {
        try {
            $response = $client->getParticipants($documento_id);
        } catch (AssineiApiException $e) {
            $this->logRaw('Não foi possível capturar os ids de participante para documento Assinei ' . $documento_id . ': ' . $e->getMessage(), []);
            return null;
        }

        $list = $response['data'] ?? $response;
        if (!is_array($list)) {
            return null;
        }
        // A single participant object (not wrapped in a list) would still
        // be an array in PHP, so this check alone can't tell them apart -
        // isset($list[0]) does, since a real list is always numerically
        // indexed from 0.
        if (!isset($list[0]) && !empty($list)) {
            $list = [$list];
        }

        $ids = ['recipient' => null, 'deliverer' => null];
        foreach ($list as $p) {
            if (!is_array($p)) {
                continue;
            }

            $email = $p['participanteEmail'] ?? $p['email'] ?? null;
            $id = $p['participanteDocumentoId'] ?? $p['id'] ?? $p['participanteId'] ?? null;
            if (!is_string($email) || !is_string($id) || $id === '') {
                continue;
            }

            if (strcasecmp((string) $recipient->getDefaultEmail(), $email) === 0) {
                $ids['recipient'] = $id;
            } elseif (strcasecmp((string) $deliverer->getDefaultEmail(), $email) === 0) {
                $ids['deliverer'] = $id;
            }
        }

        if ($ids['recipient'] === null || $ids['deliverer'] === null) {
            $this->logRaw('Não foi possível identificar ambos os participantes na resposta da Assinei para documento ' . $documento_id . '.', $response);
            return null;
        }

        return $ids;
    }

    private function readPdfBase64(int $glpi_documents_id): ?string
    {
        if ($glpi_documents_id <= 0) {
            return null;
        }

        $doc = new \Document();
        if (!$doc->getFromDB($glpi_documents_id) || empty($doc->fields['filepath'])) {
            return null;
        }

        $path = GLPI_DOC_DIR . '/' . $doc->fields['filepath'];
        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        return $content === false ? null : base64_encode($content);
    }

    private function logAndStash(Document $document, string $message, array $extra = []): void
    {
        $this->logRaw($message, $extra);
        $document->update([
            'id'               => $document->getID(),
            'external_payload' => json_encode(['error' => $message, 'context' => $extra]),
        ]);
        Session::addMessageAfterRedirect($message, false, ERROR);
    }

    private function logRaw(string $message, array $context): void
    {
        Toolbox::logInFile('termodocs-assinei', $message . ' ' . json_encode($context) . "\n", true);
    }
}
