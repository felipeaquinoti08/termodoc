<?php

namespace GlpiPlugin\Termodocs\Signature;

use GlpiPlugin\Termodocs\Document;

/**
 * Extension point for v2: plugging an external e-signature platform
 * (Clicksign, Autentique, DocuSign...) only requires implementing this
 * interface and registering it on SignatureProviderManager - nothing in
 * Document, DocumentTemplate or the placeholder engine needs to change.
 */
interface SignatureProviderInterface
{
    public function getKey(): string;

    public function getLabel(): string;

    /**
     * Called right after a document has been generated and needs to be
     * sent for signature/acceptance.
     */
    public function initiate(Document $document): void;

    /**
     * Called when an external provider notifies GLPI (webhook) that a
     * document has been signed/refused. Unused by the built-in provider,
     * which handles acceptance synchronously through the GLPI UI instead.
     */
    public function handleCallback(array $payload): void;
}
