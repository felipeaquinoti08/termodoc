<?php

namespace GlpiPlugin\Termodocs;

use CommonGLPI;
use Session;

/**
 * Single entry point shown as "Gerência > Termodocs": not backed by any
 * table, just a tab container (same pattern core uses for \Preference)
 * grouping Document templates / Documents as tabs of one page, instead of
 * separate sidebar rows.
 */
class Menu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return __('Termos', 'termodocs');
    }

    public static function getMenuName(): string
    {
        return __('Termos', 'termodocs');
    }

    public static function getIcon(): string
    {
        return 'ti ti-file-certificate';
    }

    public static function canView(): bool
    {
        return Session::haveRight(Document::$rightname, READ)
            || Session::haveRight(DocumentTemplate::$rightname, READ);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addStandardTab(DocumentTemplate::class, $tabs, $options);
        $this->addStandardTab(Document::class, $tabs, $options);

        $tabs['no_all_tab'] = true;

        return $tabs;
    }
}
