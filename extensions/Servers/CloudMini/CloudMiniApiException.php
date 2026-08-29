<?php

namespace Paymenter\Extensions\Servers\CloudMini;

use RuntimeException;

class CloudMiniApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly string $method = '',
        public readonly string $path = '',
        public readonly bool $ambiguous = false,
        public readonly bool $retryable = false,
        public readonly array $safeContext = [],
    ) {
        parent::__construct($message);
    }
}
