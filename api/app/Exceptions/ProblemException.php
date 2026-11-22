<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain error rendered as RFC 9457 application/problem+json with a stable `type` code.
 */
class ProblemException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public readonly string $code_,
        public readonly string $title,
        public readonly int $status,
        ?string $detail = null,
        public readonly array $headers = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function quotaExceeded(string $detail, int $retryAfter = 0): self
    {
        return new self('quota_exceeded', 'Quota exceeded', 429, $detail, $retryAfter ? ['Retry-After' => (string) $retryAfter] : []);
    }

    public static function featureUnavailable(string $feature): self
    {
        return new self('feature_unavailable', 'Feature not available on your plan', 403, "The '{$feature}' feature is not enabled for this tenant.");
    }

    public static function conflict(string $detail): self
    {
        return new self('conflict', 'Conflict', 409, $detail);
    }
}
