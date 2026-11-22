<?php

namespace App\Webhooks;

/**
 * X-PriceLens-Signature: v1=<hex(HMAC_SHA256(secret, timestamp + "." + raw_body))>
 */
final class Signer
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** Receiver-side verification helper (also used in tests and docs). */
    public static function verify(string $secret, string $signatureHeader, int $timestamp, string $body, ?int $now = null, int $tolerance = 300): bool
    {
        if (abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        foreach (explode(',', $signatureHeader) as $part) {
            if (hash_equals(self::sign($secret, $timestamp, $body), trim($part))) {
                return true;
            }
        }

        return false;
    }
}
