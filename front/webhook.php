<?php

use GlpiPlugin\Termodocs\Signature\AssineiConfig;
use GlpiPlugin\Termodocs\Signature\AssineiDigitalProvider;
use GlpiPlugin\Termodocs\Signature\SignatureProviderManager;

/**
 * Public endpoint (see Firewall::addPluginStrategyForLegacyScripts() in
 * setup.php - no GLPI session exists here, this is called by Assinei's
 * own servers, not a browser) that receives e-signature webhook events
 * and routes them to the right SignatureProviderInterface::handleCallback().
 *
 * Authentication is a shared secret (AssineiConfig::get()['webhook_secret'],
 * generated in front/config.php) passed as a `secret` query-string
 * parameter on the URL handed to Assinei's operations team when
 * registering the webhook - Assinei's own docs describe registration as
 * a manual step with their team rather than a self-service API call, and
 * don't publish a signature/HMAC scheme for the callback itself, so this
 * is the simplest verifiable option; tighten it (e.g. an HMAC header) if
 * Assinei's team confirms a stronger mechanism is supported.
 */
header('Content-Type: application/json');

$provider_key = (string) ($_GET['provider'] ?? AssineiDigitalProvider::KEY);
$secret = (string) ($_GET['secret'] ?? '');

$expected_secret = AssineiConfig::get()['webhook_secret'];
if ($expected_secret === '' || !hash_equals($expected_secret, $secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid secret']);
    return;
}

$raw = file_get_contents('php://input');
$payload = json_decode((string) $raw, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json body']);
    return;
}

// Always 200 past this point: whatever goes wrong parsing/matching the
// payload is logged by handleCallback() itself (see
// AssineiDigitalProvider::logRaw()) - responding with an error here
// would just make Assinei retry the same unparsable payload forever.
SignatureProviderManager::getInstance()->resolve($provider_key)->handleCallback($payload);

echo json_encode(['ok' => true]);
