<?php

namespace GlpiPlugin\Termodocs\Signature;

class SignatureProviderManager
{
    public const INTERNAL = 'internal';

    private static ?self $instance = null;

    /** @var array<string, SignatureProviderInterface> */
    private array $providers = [];

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->register(new InternalAcceptanceProvider());
        }
        return self::$instance;
    }

    public function register(SignatureProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;
    }

    public function resolve(string $key): SignatureProviderInterface
    {
        return $this->providers[$key] ?? $this->providers[self::INTERNAL];
    }

    /**
     * @return array<string, string> key => label, for dropdowns.
     */
    public function getAvailableProviders(): array
    {
        $list = [];
        foreach ($this->providers as $key => $provider) {
            $list[$key] = $provider->getLabel();
        }
        return $list;
    }
}
