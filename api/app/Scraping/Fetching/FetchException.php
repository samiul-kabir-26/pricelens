<?php

namespace App\Scraping\Fetching;

use RuntimeException;

/**
 * error codes: ssrf_blocked, invalid_url, dns_failed, skipped_policy, skipped_robots, blocked, not_found,
 * http_error, timeout, connection_error, too_large, rate_limited, renderer_saturated, renderer_error, circuit_open
 */
class FetchException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message ?: $errorCode);
    }

    /** Task status the error maps to. */
    public function taskStatus(): string
    {
        return match ($this->errorCode) {
            'skipped_robots' => 'skipped_robots',
            'skipped_policy', 'ssrf_blocked' => 'skipped_policy',
            'blocked' => 'blocked',
            default => 'failed',
        };
    }
}
