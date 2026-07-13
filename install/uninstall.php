<?php

function plugin_termodocs_uninstall_run(): bool
{
    global $DB;

    $tables = [
        'glpi_plugin_termodocs_acceptances',
        'glpi_plugin_termodocs_documents_items',
        'glpi_plugin_termodocs_documents',
        'glpi_plugin_termodocs_documenttemplates',
        'glpi_plugin_termodocs_themes',
    ];

    foreach ($tables as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `$table`");
    }

    $DB->doQuery("DELETE FROM `glpi_profilerights` WHERE `name` LIKE 'plugin:termodocs:%'");

    return true;
}
