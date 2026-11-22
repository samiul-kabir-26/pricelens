<?php

namespace App\Scraping\Planning;

/**
 * GTIN-8 / UPC-A (12) / EAN-13 / GTIN-14 with GS1 mod-10 check digit.
 */
final class GtinValidator
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/[\s-]/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $value): bool
    {
        $gtin = self::normalize($value);
        if ($gtin === null || ! preg_match('/^\d{8}$|^\d{12,14}$/', $gtin)) {
            return false;
        }

        return self::checkDigit(substr($gtin, 0, -1)) === (int) substr($gtin, -1);
    }

    public static function checkDigit(string $payload): int
    {
        $sum = 0;
        $digits = array_reverse(str_split($payload));
        foreach ($digits as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10;
    }

    /** GTIN-14 form for comparisons (UPC-A 012345678905 == EAN-13 0012345678905). */
    public static function toGtin14(string $gtin): string
    {
        return str_pad((string) self::normalize($gtin), 14, '0', STR_PAD_LEFT);
    }

    public static function equals(?string $a, ?string $b): bool
    {
        if (! self::isValid($a) || ! self::isValid($b)) {
            return false;
        }

        return self::toGtin14((string) $a) === self::toGtin14((string) $b);
    }
}
