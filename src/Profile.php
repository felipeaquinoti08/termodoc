<?php

namespace GlpiPlugin\Termodocs;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Adds a "Termodocs" tab on the core Profile form so the plugin's rights
 * get a checkbox matrix, exactly like core rights do. Plugin rights are
 * *not* auto-rendered by Profile::getRightsForForm() (that helper is
 * explicitly core-only), so this tab is required, not optional polish.
 */
class Profile extends \CommonDBTM
{
    public static function getTypeName($nb = 0): string
    {
        return __('Termodocs', 'termodocs');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!$item instanceof \Profile || !Session::haveRight('profile', READ)) {
            return '';
        }
        return __('Termodocs', 'termodocs');
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof \Profile) {
            return false;
        }

        $rights = [
            [
                'field' => 'plugin:termodocs:template',
                'label' => DocumentTemplate::getTypeName(2),
                'rights' => [
                    READ   => __('Read'),
                    CREATE => __('Create'),
                    UPDATE => __('Update'),
                    PURGE  => __('Purge'),
                ],
            ],
            [
                'field' => 'plugin:termodocs:document',
                'label' => __('Gerar e visualizar documentos', 'termodocs'),
                'rights' => [
                    READ   => __('Read'),
                    CREATE => __('Gerar', 'termodocs'),
                    DELETE => __('Delete'),
                    PURGE  => __('Purge'),
                ],
            ],
            [
                'field' => 'plugin:termodocs:acceptance',
                'label' => __('Acompanhar aceites (visão administrativa)', 'termodocs'),
                'rights' => [
                    READ => __('Read'),
                ],
            ],
        ];

        $can_edit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        TemplateRenderer::getInstance()->display('@termodocs/profile_tab.html.twig', [
            'item'     => $item,
            'rights'   => $rights,
            'can_edit' => $can_edit,
        ]);

        return true;
    }
}
