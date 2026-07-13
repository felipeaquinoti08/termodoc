<?php

use GlpiPlugin\Termodocs\DocumentTemplate;
use GlpiPlugin\Termodocs\DocumentTemplateDefaults;

/**
 * Seeds one ready-to-use example template ("Termo de Entrega de
 * Equipamento") on first install, so the plugin isn't empty out of the
 * box. Safe to re-run: only creates it if no template exists yet.
 */
function import_termodocs_default_template(): void
{
    global $DB;

    $existing = $DB->request([
        'FROM'  => 'glpi_plugin_termodocs_documenttemplates',
        'COUNT' => 'c',
    ])->current()['c'] ?? 0;

    if ($existing > 0) {
        return;
    }

    $template = new DocumentTemplate();
    $template->add([
        'name'                       => 'Termo de Entrega de Equipamento',
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
