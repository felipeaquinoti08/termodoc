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

        // The header is deliberately NOT passed to ThemedPdf as a
        // repeating page header: TCPDF's Header() callback draws within a
        // fixed-height band (margin_top/margin_header) that does not
        // adapt to how tall the header HTML actually renders, so
        // anything beyond a single short line (a real entity name +
        // title + date reliably needs 3-4 lines) gets silently drawn
        // UNDER the page's main content instead of pushing it down -
        // confirmed by direct PDF inspection, and unaffected by
        // increasing those margins. Folding it into the same writeHTML()
        // call as the body sidesteps that entirely (this is the same
        // pipeline that already lays out the info-grid/signatures tables
        // correctly) at the cost of the header no longer repeating on
        // page 2+ - an acceptable trade for a document that is almost
        // always one page. The footer/background stay as real page
        // callbacks since those never showed this problem.
        $pdf = new ThemedPdf(
            ['orientation' => 'P', 'format' => 'A4'],
            $title,
            '',
            $style . '<div class="td-footer">' . $this->normalizeForTcpdf($footer_html) . '</div>',
            $background_path
        );

        $pdf->writeHTML(
            $style
                . '<div class="td-header">' . $this->normalizeForTcpdf($header_html) . '</div>'
                . '<div class="td-content">' . $this->normalizeForTcpdf($content_html) . '</div>',
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
