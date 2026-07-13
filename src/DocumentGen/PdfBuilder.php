<?php

namespace GlpiPlugin\Termodocs\DocumentGen;

use Document as GlpiDocument;
use GlpiPlugin\Termodocs\DocumentTemplate;

/**
 * Renders the (already placeholder-resolved) header/content/footer HTML
 * to a PDF via ThemedPdf/TCPDF - the only PDF stack shipped by GLPI core
 * - and stores it as a regular glpi_documents entry so downloads reuse
 * the native GED (permissions, storage, mimetype handling) instead of a
 * bespoke one.
 *
 * The rendered_html + content_hash pair (not this PDF) is the legal
 * source of truth for what the recipient accepted; the PDF is only the
 * printable/archival representation, which is why it is safe to strip a
 * handful of modern CSS properties TCPDF's writeHTML() cannot lay out.
 */
class PdfBuilder
{
    private const UNSUPPORTED_CSS_PATTERN = '/(display\s*:\s*flex[^;]*;|display\s*:\s*grid[^;]*;|position\s*:\s*sticky[^;]*;|grid-template[a-z-]*\s*:[^;]+;|gap\s*:[^;]+;)/i';

    public function buildFromHtml(
        string $header_html,
        string $content_html,
        string $footer_html,
        ?DocumentTemplate $template,
        string $title,
        int $entities_id
    ): int {
        $css = $template !== null ? $this->normalizeForTcpdf((string) ($template->fields['css'] ?? '')) : '';
        $style = '<style>' . $css . '</style>';
        $background_path = $template?->getBackgroundFilePath();

        $pdf = new ThemedPdf(
            ['orientation' => 'P', 'format' => 'A4'],
            $title,
            $style . '<div class="td-header">' . $this->normalizeForTcpdf($header_html) . '</div>',
            $style . '<div class="td-footer">' . $this->normalizeForTcpdf($footer_html) . '</div>',
            $background_path
        );

        $pdf->writeHTML(
            $style . '<div class="td-content">' . $this->normalizeForTcpdf($content_html) . '</div>',
            true,
            false,
            true,
            false,
            ''
        );

        $basename = 'termodocs_' . bin2hex(random_bytes(8)) . '.pdf';
        $pdf->Output(GLPI_TMP_DIR . '/' . $basename, 'F');

        $document = new GlpiDocument();
        $document->add([
            'name'        => $title,
            'entities_id' => $entities_id,
            '_filename'   => [$basename],
        ]);

        return (int) $document->getID();
    }

    private function normalizeForTcpdf(string $html): string
    {
        return preg_replace(self::UNSUPPORTED_CSS_PATTERN, '', $html) ?? $html;
    }
}
