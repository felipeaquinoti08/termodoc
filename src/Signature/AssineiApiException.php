<?php

namespace GlpiPlugin\Termodocs\Signature;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Throwable;

/**
 * Carries the raw request/response around so callers (AssineiDigitalProvider)
 * can persist it verbatim into Document::external_payload and the log
 * file - essential while confirming the exact error shape Assinei's API
 * actually returns for this tenant.
 */
class AssineiApiException extends RuntimeException
{
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly ?int $status_code,
        public readonly string $raw_response,
        string $message
    ) {
        parent::__construct($message);
    }

    public static function fromGuzzleException(string $method, string $url, GuzzleException $e): self
    {
        $status = null;
        $body = $e->getMessage();
        if ($e instanceof RequestException && $e->hasResponse()) {
            $status = $e->getResponse()->getStatusCode();
            $body = (string) $e->getResponse()->getBody();
        }

        return new self($method, $url, $status, $body, self::summarize($method, $url, $status, $e));
    }

    private static function summarize(string $method, string $url, ?int $status, Throwable $e): string
    {
        return sprintf(
            'Assinei API %s %s falhou%s: %s',
            $method,
            $url,
            $status !== null ? " (HTTP {$status})" : '',
            $e->getMessage()
        );
    }
}
