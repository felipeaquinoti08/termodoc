<?php

namespace GlpiPlugin\Termodocs\Signature;

use GlpiPlugin\Termodocs\Document;

/**
 * V1 signature provider: the recipient reviews and accepts/refuses the
 * document from inside GLPI itself (front/acceptance.php). No external
 * call is made here; GlpiPlugin\Termodocs\Acceptance::accept()/refuse()
 * record the outcome directly.
 */
class InternalAcceptanceProvider implements SignatureProviderInterface
{
    public function getKey(): string
    {
        return SignatureProviderManager::INTERNAL;
    }

    public function getLabel(): string
    {
        return __('Aceite interno (padrão)', 'termodocs');
    }

    public function initiate(Document $document): void
    {
    }

    public function handleCallback(array $payload): void
    {
    }
}
