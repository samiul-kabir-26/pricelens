<?php

namespace App\Support;

use Brick\Money\Currency;
use Throwable;

/**
 * Minor-unit helpers. All prices are stored as BIGINT minor units + ISO 4217 code.
 */
final class Money
{
    /** @var array<string, int> */
    private static array $digits = [];

    public static function fractionDigits(string $currency): int
    {
        $currency = strtoupper($currency);

        return self::$digits[$currency] ??= (function () use ($currency) {
            try {
                return Currency::of($currency)->getDefaultFractionDigits();
            } catch (Throwable) {
                return 2;
            }
        })();
    }

    public static function isValidCurrency(?string $currency): bool
    {
        if ($currency === null || ! preg_match('/^[A-Z]{3}$/', $currency)) {
            return false;
        }

        try {
            Currency::of($currency);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** "329.99" + USD → 32999 */
    public static function toMinor(string|int|float $amount, string $currency): int
    {
        $digits = self::fractionDigits($currency);

        return (int) round(((float) $amount) * (10 ** $digits));
    }

    /**
     * 32999 + USD → "329.99"
     *
     * @return ($minor is null ? null : string)
     */
    public static function format(?int $minor, string $currency): ?string
    {
        if ($minor === null) {
            return null;
        }

        $digits = self::fractionDigits($currency);
        $negative = $minor < 0;
        $abs = abs($minor);

        if ($digits === 0) {
            return ($negative ? '-' : '').$abs;
        }

        $base = 10 ** $digits;
        $str = intdiv($abs, $base).'.'.str_pad((string) ($abs % $base), $digits, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$str;
    }

    /**
     * @return array{amount: string, currency: string}|null
     */
    public static function toArray(?int $minor, ?string $currency): ?array
    {
        if ($minor === null || $currency === null) {
            return null;
        }

        return ['amount' => self::format($minor, $currency), 'currency' => $currency];
    }
}
