<?php

namespace GlpiPlugin\Termodocs\DocumentGen;

use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\Document_Item;
use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\Placeholder\ContextResolver;
use GlpiPlugin\Termodocs\Placeholder\TemplateRenderer;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;
use Session;
use User;

class DocumentGenerator
{
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * @param array<int, array{0: string, 1: int}> $items list of [itemtype, items_id] pairs
     * @param int $users_id_deliverer the IT-side counterpart: who is
     *   delivering (Entrega) or taking custody back (Devolução). Defaults
     *   to the logged-in user but is editable in the wizard, kept separate
     *   from users_id_requester (who operated the system, for audit).
     */
    public function generate(
        DocumentTemplate $template,
        array $items,
        int $users_id_recipient,
        int $entities_id,
        int $users_id_deliverer = 0
    ): Document {
        $recipient = new User();
        $recipient->getFromDB($users_id_recipient);

        $deliverer = new User();
        $deliverer->getFromDB($users_id_deliverer ?: Session::getLoginUserID());

        $context = (new ContextResolver())->resolve(
            $items,
            $recipient,
            $deliverer,
            $entities_id,
            ['date' => date('Y-m-d'), 'name' => $template->fields['name']]
        );

        $renderer = TemplateRenderer::getInstance();
        $rendered_header = $renderer->render($template->fields['header_html'] ?? '', $context);
        $rendered_content = $renderer->render($template->fields['content_html'], $context);
        $rendered_footer = $renderer->render($template->fields['footer_html'] ?? '', $context);

        $rendered_html = $template->composeHtml($rendered_header, $rendered_content, $rendered_footer);
        $content_hash = hash('sha256', $rendered_html);

        $require_acceptance = (bool) $template->fields['require_acceptance'];
        $status = $require_acceptance ? Document::WAITING_ACCEPTANCE : Document::ACCEPTED;

        $document = new Document();
        $document->add([
            'plugin_termodocs_templates_id' => $template->getID(),
            'name'                          => $template->fields['name'] . ' - ' . $recipient->getFriendlyName() .
                ' [' . Document::generateReferenceCode() . ']',
            'rendered_html'                 => $rendered_html,
            'content_hash'                  => $content_hash,
            'theme_css_snapshot'            => $template->fields['css'] ?? '',
            'signature_provider'            => $template->fields['signature_provider'] ?? SignatureProviderManager::INTERNAL,
            'users_id_recipient'            => $users_id_recipient,
            'users_id_deliverer'            => $deliverer->getID(),
            'users_id_requester'            => Session::getLoginUserID(),
            'status'                        => $status,
            'entities_id'                   => $entities_id,
            'date_generated'                => date('Y-m-d H:i:s'),
            'date_finalized'                => $require_acceptance ? null : date('Y-m-d H:i:s'),
        ]);

        foreach ($items as [$itemtype, $items_id]) {
            (new Document_Item())->add([
                'plugin_termodocs_documents_id' => $document->getID(),
                'itemtype'                      => $itemtype,
                'items_id'                      => $items_id,
            ]);
        }

        $pdf_documents_id = (new PdfBuilder())->buildFromHtml(
            $rendered_header,
            $rendered_content,
            $rendered_footer,
            $template,
            $document->fields['name'],
            $entities_id
        );
        $document->update([
            'id'              => $document->getID(),
            'pdf_document_id' => $pdf_documents_id,
        ]);

        // External providers are no longer kicked off automatically here -
        // sending a document out (e-mailing real people through Assinei
        // or whichever provider) is deliberately a separate, explicit step
        // the admin takes from the document's own page ("Enviar para
        // assinatura" - see Document::showForm()'s can_send_signature and
        // front/document.form.php's send_signature action), so a
        // just-generated document can be reviewed first, and a failed
        // send can be retried without regenerating the whole document.
        // InternalAcceptanceProvider::initiate() was always a no-op, so
        // internal-provider documents are unaffected either way.

        return $document;
    }

    /**
     * Rebuilds the downloadable PDF to include the per-item condition
     * and general notes recorded from the document's own page (see
     * front/document.form.php's save_notes action) - called every time
     * those are saved. Deliberately never touches rendered_html/
     * content_hash: that frozen pair is the legal record of exactly what
     * was generated/signed, and condition/notes are recorded afterwards
     * (typically at physical handover), so they only ever affect this
     * regenerated file, never the original record. Re-renders
     * header/footer from the template fresh rather than reusing anything
     * cached, the same way generate() does the first time; if the
     * template was since deleted, falls back to empty header/footer
     * (rare - only for documents whose template was later removed).
     *
     * @return int the new pdf_document_id (glpi_documents.id), or 0 if
     *   the PDF couldn't be rebuilt (e.g. no items resolvable at all)
     */
    public function regeneratePdfWithConditions(Document $document): int
    {
        $template = new DocumentTemplate();
        $has_template = $template->getFromDB((int) $document->fields['plugin_termodocs_templates_id']);

        $recipient = new User();
        $recipient->getFromDB((int) $document->fields['users_id_recipient']);
        $deliverer = new User();
        $deliverer->getFromDB((int) $document->fields['users_id_deliverer']);

        $linked_items = Document_Item::getItemsForDocument($document->getID());
        $items = [];
        foreach ($linked_items as $linked) {
            $items[] = [$linked['itemtype'], (int) $linked['items_id']];
        }

        $context = (new ContextResolver())->resolve(
            $items,
            $recipient,
            $deliverer,
            (int) $document->fields['entities_id'],
            [
                'date' => substr((string) $document->fields['date_generated'], 0, 10) ?: date('Y-m-d'),
                'name' => $document->fields['name'],
            ]
        );

        $renderer = TemplateRenderer::getInstance();
        $rendered_header = $has_template ? $renderer->render($template->fields['header_html'] ?? '', $context) : '';
        $rendered_content = $has_template ? $renderer->render($template->fields['content_html'] ?? '', $context) : '';
        $rendered_footer = $has_template ? $renderer->render($template->fields['footer_html'] ?? '', $context) : '';

        $rendered_content .= Document::buildConditionsSectionHtml($document, $linked_items);
        $rendered_content .= Document::buildAccessoriesSectionHtml($document);

        return (new PdfBuilder())->buildFromHtml(
            $rendered_header,
            $rendered_content,
            $rendered_footer,
            $has_template ? $template : null,
            $document->fields['name'],
            (int) $document->fields['entities_id']
        );
    }

}
