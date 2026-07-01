<?php

namespace Paymenter\Extensions\Servers\HAVProxyIPv4DC;

use Exception;
use Illuminate\Http\Client\Response;

class V3ProviderException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $providerCode = null,
        public readonly bool $retryable = false,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response, string $method, string $url): self
    {
        $body = $response->json() ?? [];
        $status = $response->status();
        $providerCode = data_get($body, 'error.code') ?? data_get($body, 'code');
        $message = data_get($body, 'error.message')
            ?? data_get($body, 'message')
            ?? $response->reason()
            ?? 'Provider request failed';

        return new self(
            sprintf('HAV Proxy API %s %s failed with HTTP %s: %s', strtoupper($method), $url, $status, $message),
            $status,
            $providerCode ? (string) $providerCode : null,
            (bool) (data_get($body, 'error.retryable') ?? data_get($body, 'retryable') ?? false),
            (array) (data_get($body, 'error.details') ?? data_get($body, 'details') ?? []),
        );
    }
}
