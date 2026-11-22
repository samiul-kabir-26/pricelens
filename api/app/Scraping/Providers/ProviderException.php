<?php

namespace App\Scraping\Providers;

use RuntimeException;

class ProviderException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '', public readonly bool $retryable = false)
    {
        parent::__construct($message ?: $errorCode);
    }
}
