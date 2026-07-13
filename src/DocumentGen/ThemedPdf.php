<?php

namespace GlpiPlugin\Termodocs\DocumentGen;

use GLPIPDF;

/**
 * Renders a theme's header/footer/background on every page of the PDF,
 * via TCPDF's own page-cycle callbacks - the idiomatic way to get a
 * repeating header/footer in TCPDF (see GLPIPDF::Header()/Footer(), which
 * this overrides entirely instead of extending).
 */
class ThemedPdf extends GLPIPDF
{
    private string $headerHtml;
    private string $footerHtml;
    private ?string $backgroundPath;

    public function __construct(
        array $config,
        string $title,
        string $headerHtml,
        string $footerHtml,
        ?string $backgroundPath
    ) {
        $this->headerHtml = $headerHtml;
        $this->footerHtml = $footerHtml;
        $this->backgroundPath = $backgroundPath;

        parent::__construct($config, null, $title);
    }

    public function Header(): void
    {
        if ($this->backgroundPath !== null) {
            $this->Image($this->backgroundPath, 0, 0, $this->getPageWidth(), $this->getPageHeight());
            $this->setPageMark();
        }

        if ($this->headerHtml !== '') {
            $this->writeHTMLCell(0, 0, '', '', $this->substitutePageTokens($this->headerHtml), 0, 1, false, true, 'C');
        }
    }

    public function Footer(): void
    {
        if ($this->footerHtml === '') {
            return;
        }

        $this->SetY(-15);
        $this->writeHTMLCell(0, 0, '', '', $this->substitutePageTokens($this->footerHtml), 0, 1, false, true, 'C');
    }

    private function substitutePageTokens(string $html): string
    {
        return str_replace(
            ['{PAGENO}', '{nb}'],
            [$this->getAliasNumPage(), $this->getAliasNbPages()],
            $html
        );
    }
}
