<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\DocumentTemplateDefaults;

/**
 * Seeds two ready-to-use example templates ("Termo de Entrega de
 * Equipamento" and "Termo de Devolução de Equipamento") so the plugin
 * isn't empty out of the box. Each is guarded independently by name
 * (not just "does any template exist"), so re-running this after
 * upgrading an install that already has the delivery one still adds
 * the return one instead of skipping it entirely.
 */
function import_termodocs_default_template(): void
{
    global $DB;

    $exists = static function (string $name) use ($DB): bool {
        return (bool) ($DB->request([
            'FROM'  => 'glpi_plugin_termodocs_documenttemplates',
            'COUNT' => 'c',
            'WHERE' => ['name' => $name],
        ])->current()['c'] ?? 0);
    };

    $delivery_name = 'Termo de Entrega de Equipamento';
    if (!$exists($delivery_name)) {
        (new DocumentTemplate())->add([
            'name'                       => $delivery_name,
            'description'                => '',
            'header_html'                => DocumentTemplateDefaults::getHeaderHtml(),
            'footer_html'                => DocumentTemplateDefaults::getFooterHtml(),
            'content_html'               => DocumentTemplateDefaults::getContentHtml(),
            'css'                        => DocumentTemplateDefaults::getCss(),
            'operation_type'             => DocumentTemplate::OPERATION_DELIVERY,
            'allowed_itemtypes'          => json_encode(['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone']),
            'allow_multiple_items'       => 1,
            'require_acceptance'         => 1,
            'signature_provider'         => 'internal',
            'is_active'                  => 1,
            'entities_id'                => 0,
            'is_recursive'               => 1,
        ]);
    }

    $return_name = 'Termo de Devolução de Equipamento';
    if (!$exists($return_name)) {
        (new DocumentTemplate())->add([
            'name'                       => $return_name,
            'description'                => '',
            'header_html'                => DocumentTemplateDefaults::getReturnHeaderHtml(),
            'footer_html'                => DocumentTemplateDefaults::getFooterHtml(),
            'content_html'               => DocumentTemplateDefaults::getReturnContentHtml(),
            'css'                        => DocumentTemplateDefaults::getCss(),
            'operation_type'             => DocumentTemplate::OPERATION_RETURN,
            'allowed_itemtypes'          => json_encode(['Computer', 'Monitor', 'NetworkEquipment', 'Peripheral', 'Printer', 'Phone']),
            'allow_multiple_items'       => 1,
            'require_acceptance'         => 1,
            'signature_provider'         => 'internal',
            'is_active'                  => 1,
            'entities_id'                => 0,
            'is_recursive'               => 1,
        ]);
    }
}
