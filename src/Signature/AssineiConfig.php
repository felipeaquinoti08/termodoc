<?php

namespace GlpiPlugin\Termodocs\Signature;

use Config as GlpiConfig;
use GLPIKey;

/**
 * Typed access to the Assinei.digital (Aliare) integration settings,
 * persisted as rows in the core `glpi_configs` table via
 * Config::setConfigurationValues() / getConfigurationValues() - same
 * pattern as GlpiPlugin\Entrasso\Config. `portal_password` and
 * `webhook_secret` are encrypted at rest through the SECURED_CONFIGS
 * hook registered in setup.php; that hook only makes
 * setConfigurationValues() encrypt on write, getConfigurationValues()
 * never decrypts automatically on read, so it's done explicitly in
 * get(). CONTEXT must stay exactly 'plugin:termodocs' - GLPIKey resolves
 * SECURED_CONFIGS entries as 'plugin:' . <array key used in the hook>
 * (see GLPIKey::getConfigs()), so it has to match
 * $PLUGIN_HOOKS[Hooks::SECURED_CONFIGS]['termodocs'] in setup.php
 * exactly, not some more specific sub-context.
 */
class AssineiConfig
{
    public const CONTEXT = 'plugin:termodocs';

    // "Parte" in Assinei's default tipoParticipante catalog (GET
    // /v1/Participante/Tipo) - a generic signer, not tied to a specific
    // contractual role (buyer/lessor/witness/...), which fits both the
    // "colaborador" and "TI" parties on a delivery/return term.
    public const DEFAULT_PARTICIPANT_TYPE_ID = 'bf391392-4ec4-4b74-8fd6-2e343e1ec30a';

    private const FIELDS = [
        'is_active'             => 0,
        'base_url'              => 'https://app.assinei.digital/api/backoffice',
        'auth_url'               => 'https://api.aliare.digital/auth/v1.0-rc/connect/token',
        'subscription_key'        => '',
        'portal_user'               => '',
        'portal_password'             => '',
        'tenant_id'                     => '',
        'cofre_id'                        => '',
        'participante_tipo_id'              => self::DEFAULT_PARTICIPANT_TYPE_ID,
        'webhook_secret'                       => '',
    ];

    public static function get(): array
    {
        $stored = GlpiConfig::getConfigurationValues(self::CONTEXT, array_keys(self::FIELDS));

        foreach (['portal_password', 'webhook_secret'] as $secured) {
            if (!empty($stored[$secured])) {
                $stored[$secured] = (new GLPIKey())->decrypt($stored[$secured]) ?? '';
            }
        }

        return array_merge(self::FIELDS, $stored);
    }

    public static function set(array $values): void
    {
        $clean = [];
        foreach (self::FIELDS as $key => $default) {
            if (array_key_exists($key, $values)) {
                $clean[$key] = $values[$key];
            }
        }
        GlpiConfig::setConfigurationValues(self::CONTEXT, $clean);
    }

    /**
     * The minimum needed to actually call the API - is_active alone
     * isn't enough to try, and trying with half-filled credentials would
     * just produce confusing 401s deep inside AssineiApiClient.
     */
    public static function isConfigured(): bool
    {
        $config = self::get();
        foreach (['subscription_key', 'portal_user', 'portal_password', 'tenant_id', 'cofre_id'] as $required) {
            if ($config[$required] === '') {
                return false;
            }
        }
        return true;
    }

    public static function isActive(): bool
    {
        return (bool) self::get()['is_active'] && self::isConfigured();
    }

    /**
     * Generated once on first save (see front/config.php) and given to
     * Assinei's operations team when registering the webhook - there is
     * no self-service webhook registration endpoint in their API, so
     * this is the shared secret we ask them to send back to us in
     * whatever header/query-param scheme they support, and that
     * front/webhook.php checks before trusting a callback.
     */
    public static function generateWebhookSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function getWebhookUrl(): string
    {
        global $CFG_GLPI;
        return rtrim($CFG_GLPI['url_base'], '/') . '/plugins/termodocs/front/webhook.php?provider=' . AssineiDigitalProvider::KEY;
    }
}
