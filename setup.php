<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Termodocs\Document;
use GlpiPlugin\Termodocs\MassiveActionHandler;
use GlpiPlugin\Termodocs\Menu as TermodocsMenu;
use GlpiPlugin\Termodocs\PendingSignatures;
use GlpiPlugin\Termodocs\Profile as TermodocsProfile;

define('PLUGIN_TERMODOCS_VERSION', '1.0.0');
define('PLUGIN_TERMODOCS_MIN_GLPI_VERSION', '11.0.0');
define('PLUGIN_TERMODOCS_MAX_GLPI_VERSION', '11.9.99');

function plugin_version_termodocs(): array
{
    return [
        'name'         => 'Termodocs',
        'version'      => PLUGIN_TERMODOCS_VERSION,
        'author'       => 'Felipe Aquino',
        'license'      => 'GPL-3.0-or-later',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_TERMODOCS_MIN_GLPI_VERSION,
                'max' => PLUGIN_TERMODOCS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_init_termodocs(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['termodocs'] = true;

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['termodocs'] = ['management' => [TermodocsMenu::class, PendingSignatures::class]];

    // The central-interface menu entry above never shows up for users on
    // the simplified/self-service interface (a different menu tree
    // entirely) - most recipients signing a delivery/return term are
    // exactly those simplified-interface users, so they need their own
    // entry there. HELPDESK_MENU_ENTRY would only add it as a sub-item
    // nested under a generic "Plugins" group labeled with the plugin's
    // own name - REDEFINE_MENUS is used instead so it shows as a real
    // top-level "Assinaturas pendentes" entry.
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['termodocs'] = 'plugin_termodocs_redefine_menus';

    Plugin::registerClass(Document::class, [
        'addtabon' => [
            'Computer',
            'Monitor',
            'NetworkEquipment',
            'Peripheral',
            'Printer',
            'Phone',
        ],
    ]);

    Plugin::registerClass(TermodocsProfile::class, [
        'addtabon' => ['Profile'],
    ]);

    $PLUGIN_HOOKS[Hooks::USE_MASSIVE_ACTION]['termodocs'] = true;

    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['termodocs'] = [
        'Computer'         => [Document::class, 'onAssetPurge'],
        'Monitor'          => [Document::class, 'onAssetPurge'],
        'NetworkEquipment' => [Document::class, 'onAssetPurge'],
        'Peripheral'       => [Document::class, 'onAssetPurge'],
        'Printer'          => [Document::class, 'onAssetPurge'],
        'Phone'            => [Document::class, 'onAssetPurge'],
    ];
}

/**
 * Adds "Assinaturas pendentes" as a real top-level entry in the
 * simplified/self-service interface's sidebar (not nested under the
 * generic "Plugins" group, which is the only option HELPDESK_MENU_ENTRY
 * offers). This callback also runs for the central-interface menu, so it
 * only touches $menu when the current session is on the helpdesk
 * interface.
 */
function plugin_termodocs_redefine_menus(array $menu): array
{
    if (Session::getCurrentInterface() !== 'helpdesk') {
        return $menu;
    }

    $menu['termodocs'] = [
        'default' => '/plugins/termodocs/front/pendingsignatures.php',
        'title'   => __('Assinaturas pendentes', 'termodocs'),
        'icon'    => 'ti ti-signature',
    ];

    return $menu;
}

function plugin_termodocs_install(): bool
{
    include_once(__DIR__ . '/install/install.php');
    return plugin_termodocs_install_run();
}

function plugin_termodocs_uninstall(): bool
{
    include_once(__DIR__ . '/install/uninstall.php');
    return plugin_termodocs_uninstall_run();
}

function plugin_termodocs_MassiveActions($itemtype)
{
    return MassiveActionHandler::getActionsForItemtype($itemtype);
}
