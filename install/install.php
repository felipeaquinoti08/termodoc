<?php

require_once __DIR__ . '/import_custom_theme.php';

function plugin_termodocs_install_run(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $migration = new Migration(PLUGIN_TERMODOCS_VERSION);

    if (!$DB->tableExists('glpi_plugin_termodocs_documenttemplates')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_termodocs_documenttemplates` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL DEFAULT '',
            `description` text,
            `header_html` mediumtext,
            `footer_html` mediumtext,
            `content_html` mediumtext,
            `css` mediumtext,
            `background_documents_id` int {$default_key_sign} DEFAULT NULL,
            `operation_type` tinyint NOT NULL DEFAULT '1',
            `allowed_itemtypes` text,
            `allow_multiple_items` tinyint NOT NULL DEFAULT '1',
            `require_acceptance` tinyint NOT NULL DEFAULT '1',
            `signature_provider` varchar(64) NOT NULL DEFAULT 'internal',
            `is_active` tinyint NOT NULL DEFAULT '1',
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `is_recursive` tinyint NOT NULL DEFAULT '0',
            `is_deleted` tinyint NOT NULL DEFAULT '0',
            `comment` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `is_active` (`is_active`),
            KEY `entities_id` (`entities_id`),
            KEY `is_recursive` (`is_recursive`),
            KEY `is_deleted` (`is_deleted`),
            KEY `background_documents_id` (`background_documents_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    } else {
        migrate_termodocs_merge_theme_into_template();
    }

    if (!$DB->tableExists('glpi_plugin_termodocs_documents')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_termodocs_documents` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_termodocs_templates_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `name` varchar(255) NOT NULL DEFAULT '',
            `rendered_html` mediumtext,
            `content_hash` char(64) DEFAULT NULL,
            `theme_css_snapshot` mediumtext,
            `signature_provider` varchar(64) NOT NULL DEFAULT 'internal',
            `users_id_recipient` int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id_deliverer` int {$default_key_sign} NOT NULL DEFAULT '0',
            `users_id_requester` int {$default_key_sign} NOT NULL DEFAULT '0',
            `status` tinyint NOT NULL DEFAULT '1',
            `pdf_document_id` int {$default_key_sign} DEFAULT NULL,
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `is_deleted` tinyint NOT NULL DEFAULT '0',
            `date_generated` timestamp NULL DEFAULT NULL,
            `date_finalized` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `name` (`name`),
            KEY `plugin_termodocs_templates_id` (`plugin_termodocs_templates_id`),
            KEY `users_id_recipient` (`users_id_recipient`),
            KEY `users_id_deliverer` (`users_id_deliverer`),
            KEY `users_id_requester` (`users_id_requester`),
            KEY `status` (`status`),
            KEY `pdf_document_id` (`pdf_document_id`),
            KEY `entities_id` (`entities_id`),
            KEY `is_deleted` (`is_deleted`),
            KEY `content_hash` (`content_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    } else {
        migrate_termodocs_add_delivery_return_fields();
    }

    if (!$DB->tableExists('glpi_plugin_termodocs_documents_items')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_termodocs_documents_items` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_termodocs_documents_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `item_alias` varchar(64) DEFAULT NULL,
            `item_snapshot` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_termodocs_documents_id`,`itemtype`,`items_id`),
            KEY `plugin_termodocs_documents_id` (`plugin_termodocs_documents_id`),
            KEY `item` (`itemtype`,`items_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    }

    if (!$DB->tableExists('glpi_plugin_termodocs_acceptances')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_termodocs_acceptances` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `plugin_termodocs_documents_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `role` tinyint NOT NULL DEFAULT '1',
            `users_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `status` tinyint NOT NULL DEFAULT '0',
            `accepted_name_snapshot` varchar(255) DEFAULT NULL,
            `ip_address` varchar(45) DEFAULT NULL,
            `user_agent` text,
            `content_hash_signed` char(64) DEFAULT NULL,
            `refusal_reason` text,
            `signature_provider` varchar(64) NOT NULL DEFAULT 'internal',
            `external_reference` varchar(255) DEFAULT NULL,
            `external_payload` text,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_termodocs_documents_id`,`role`),
            KEY `users_id` (`users_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC;");
    } else {
        migrate_termodocs_two_party_acceptance();
    }

    $migration->addRight('plugin:termodocs:template', ALLSTANDARDRIGHT);
    $migration->addRight('plugin:termodocs:document', READ | CREATE | DELETE | PURGE);
    $migration->addRight('plugin:termodocs:acceptance', READ);

    $migration->executeMigration();

    // addRight() only inserts a row for profiles that don't have this
    // right yet, so installs that ran before DELETE was added here need
    // it OR'd into their existing value - the documents table has
    // is_deleted, so GLPI's massive action checkboxes only appear once
    // DELETE (put in trash), not just PURGE, is granted.
    $DB->doQuery("UPDATE `glpi_profilerights` SET `rights` = `rights` | " . DELETE . " WHERE `name` = 'plugin:termodocs:document'");

    // Already-logged-in sessions cache their profile's rights in
    // $_SESSION and only reload them once glpi_profiles.last_rights_update
    // moves forward (Session::validateIDOR()/checkValidSession() compares
    // it every request) - bump it for every profile holding this right so
    // the fix above applies on the user's very next page load, with no
    // logout/login required.
    if ($DB->fieldExists('glpi_profiles', 'last_rights_update')) {
        $profile_ids = [];
        foreach ($DB->request([
            'SELECT' => 'profiles_id',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => 'plugin:termodocs:document'],
        ]) as $row) {
            $profile_ids[] = $row['profiles_id'];
        }
        if (!empty($profile_ids)) {
            $DB->update('glpi_profiles', ['last_rights_update' => Session::getCurrentTime() ?? date('Y-m-d H:i:s')], ['id' => $profile_ids]);
        }
    }

    import_termodocs_default_template();

    return true;
}

/**
 * Redesign migration: the separate, selectable "Theme" entity is gone -
 * every document template now owns its own header/footer/css/background,
 * edited together with its content on a single screen. Copies each
 * template's linked theme data into its own new columns before dropping
 * the themes table entirely. Safe to re-run.
 */
function migrate_termodocs_merge_theme_into_template(): void
{
    global $DB;

    foreach (['header_html', 'footer_html', 'css', 'background_documents_id'] as $field) {
        if (!$DB->fieldExists('glpi_plugin_termodocs_documenttemplates', $field)) {
            $type = $field === 'background_documents_id' ? 'int unsigned NULL' : 'mediumtext NULL';
            $DB->doQuery("ALTER TABLE `glpi_plugin_termodocs_documenttemplates` ADD COLUMN `$field` $type");
        }
    }

    if (
        $DB->fieldExists('glpi_plugin_termodocs_documenttemplates', 'plugin_termodocs_themes_id')
        && $DB->tableExists('glpi_plugin_termodocs_themes')
    ) {
        $templates = $DB->request(['FROM' => 'glpi_plugin_termodocs_documenttemplates']);
        foreach ($templates as $template) {
            $theme = $DB->request([
                'FROM'  => 'glpi_plugin_termodocs_themes',
                'WHERE' => ['id' => $template['plugin_termodocs_themes_id']],
            ])->current();
            if (!$theme) {
                continue;
            }
            $DB->update('glpi_plugin_termodocs_documenttemplates', [
                'header_html'              => $theme['header_html'],
                'footer_html'              => $theme['footer_html'],
                'css'                      => $theme['css'],
                'background_documents_id'  => $theme['background_documents_id'],
            ], ['id' => $template['id']]);
        }

        $DB->doQuery('ALTER TABLE `glpi_plugin_termodocs_documenttemplates` DROP COLUMN `plugin_termodocs_themes_id`');
    }

    if ($DB->tableExists('glpi_plugin_termodocs_themes')) {
        $DB->doQuery('DROP TABLE `glpi_plugin_termodocs_themes`');
    }

    $DB->doQuery("DELETE FROM `glpi_profilerights` WHERE `name` = 'plugin:termodocs:theme'");
}

/**
 * Adds the delivery/return workflow fields: a template now declares
 * whether it is a delivery ("Entrega") or a return ("Devolução"), and a
 * document records an explicit "deliverer" (the IT-side counterpart)
 * separate from the recipient (always the non-IT employee, who signs
 * either way) and from the audit-only "requester" (whoever operated the
 * system). Also forces every template to require acceptance - signing is
 * mandatory, never optional. Safe to re-run.
 */
function migrate_termodocs_add_delivery_return_fields(): void
{
    global $DB;

    if (!$DB->fieldExists('glpi_plugin_termodocs_documenttemplates', 'operation_type')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_termodocs_documenttemplates` ADD COLUMN `operation_type` tinyint NOT NULL DEFAULT '1' AFTER `background_documents_id`");
    }

    if (!$DB->fieldExists('glpi_plugin_termodocs_documents', 'users_id_deliverer')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_termodocs_documents` ADD COLUMN `users_id_deliverer` int unsigned NOT NULL DEFAULT '0' AFTER `users_id_recipient`");
        $DB->doQuery('ALTER TABLE `glpi_plugin_termodocs_documents` ADD KEY `users_id_deliverer` (`users_id_deliverer`)');
        // Best-effort backfill: previously the "deliverer" role in templates
        // was implicitly whoever generated the document.
        $DB->doQuery('UPDATE `glpi_plugin_termodocs_documents` SET `users_id_deliverer` = `users_id_requester` WHERE `users_id_deliverer` = 0');
    }

    if ($DB->fieldExists('glpi_plugin_termodocs_documenttemplates', 'require_acceptance')) {
        $DB->doQuery('UPDATE `glpi_plugin_termodocs_documenttemplates` SET `require_acceptance` = 1');
    }
}

/**
 * Both parties must sign for a document to be finalized: the recipient
 * (colaborador, always) and the deliverer (TI). Acceptances move from
 * one-per-document to one-per-(document, role) - existing rows are
 * backfilled as the recipient's signature (the only role that used to be
 * able to sign at all). Safe to re-run.
 */
function migrate_termodocs_two_party_acceptance(): void
{
    global $DB;

    if (!$DB->fieldExists('glpi_plugin_termodocs_acceptances', 'role')) {
        $DB->doQuery("ALTER TABLE `glpi_plugin_termodocs_acceptances` ADD COLUMN `role` tinyint NOT NULL DEFAULT '1' AFTER `plugin_termodocs_documents_id`");
        $DB->doQuery('ALTER TABLE `glpi_plugin_termodocs_acceptances` DROP INDEX `plugin_termodocs_documents_id`');
        $DB->doQuery('ALTER TABLE `glpi_plugin_termodocs_acceptances` ADD UNIQUE KEY `unicity` (`plugin_termodocs_documents_id`,`role`)');
    }
}
