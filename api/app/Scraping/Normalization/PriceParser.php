<?php

namespace App\Scraping\Normalization;

use App\Support\Money;

/**
 * Locale-aware price parsing: "1.299,00 €", "€1,299.00", "1 299,00", "Rs. 1,29,999", "¥12,800", "KWD 1.250".
 * Output is minor units in the detected currency (or the hint), using ISO 4217 minor-unit precision.
 */
class PriceParser
{
    /** Longest first so "US$" wins over "$". */
    private const SYMBOLS = [
        'US$' => 'USD', 'CA$' => 'CAD', 'AU$' => 'AUD', 'NZ$' => 'NZD', 'HK$' => 'HKD', 'C$' => 'CAD', 'A$' => 'AUD',
        'S$' => 'SGD', 'R$' => 'BRL', 'MX$' => 'MXN', 'Rs.' => 'INR', 'Rs' => 'INR', 'zł' => 'PLN', 'Kč' => 'CZK',
        'KD' => 'KWD', 'Ft' => 'HUF', 'lei' => 'RON', '€' => 'EUR', '£' => 'GBP', '¥' => 'JPY', '￥' => 'JPY',
        '₹' => 'INR', '₩' => 'KRW', '₽' => 'RUB', '₺' => 'TRY', '฿' => 'THB', '₫' => 'VND', '₱' => 'PHP',
        '₪' => 'ILS', '৳' => 'BDT', '₦' => 'NGN', '$' => 'USD',
    ];

    /** Currencies that share a symbol: a hint in this set overrides the symbol's default. */
    private const SYMBOL_FAMILIES = [
        '$' => ['USD', 'CAD', 'AUD', 'NZD', 'HKD', 'SGD', 'MXN', 'ARS', 'CLP', 'COP', 'TWD'],
        '¥' => ['JPY', 'CNY'],
        'kr' => ['SEK', 'NOK', 'DKK', 'ISK'],
    ];

    /**
     * @param  bool  $structured  true for machine-readable values (JSON-LD, meta tags): dot is always decimal
     * @return array{minor: int, currency: ?string}|null
     */
    public function parse(mixed $raw, ?string $currencyHint = null, bool $structured = false): ?array
    {
        if ($raw === null || $raw === '' || is_bool($raw) || is_array($raw)) {
            return null;
        }

        $hint = $currencyHint ? strtoupper(trim($currencyHint)) : null;
        $hint = Money::isValidCurrency($hint) ? $hint : null;

        if (is_int($raw) || is_float($raw)) {
            $currency = $hint;

            return $raw > 0 ? ['minor' => Money::toMinor($raw, $currency ?? 'USD'), 'currency' => $currency] : null;
        }

        $text = trim(html_entity_decode(strip_tags((string) $raw), ENT_QUOTES | ENT_HTML5));
        $currency = $this->detectCurrency($text, $hint) ?? $hint;

        if ($structured && preg_match('/^\s*\d+(\.\d+)?\s*$/', $text)) {
            $amount = (float) $text;

            return $amount > 0 ? ['minor' => Money::toMinor($amount, $currency ?? 'USD'), 'currency' => $currency] : null;
        }

        if (! preg_match('/\d(?:[\d.,\'\s\x{00A0}\x{202F}]*\d)?/u', $text, $m)) {
            return null;
        }

        $amount = $this->toFloat($m[0], $currency);
        if ($amount === null || $amount <= 0) {
            return null;
        }

        return ['minor' => Money::toMinor($amount, $currency ?? 'USD'), 'currency' => $currency];
    }

    public function detectCurrency(string $text, ?string $hint = null): ?string
    {
        if (preg_match('/\b([A-Z]{3})\b/', strtoupper($text), $m) && Money::isValidCurrency($m[1]) && ! in_array($m[1], ['FOR', 'AND', 'THE', 'NEW', 'OFF'], true)) {
            return $m[1];
        }

        if (preg_match('/(?<![a-z])kr\.?(?![a-z])/i', $text)) {
            return $hint && in_array($hint, self::SYMBOL_FAMILIES['kr'], true) ? $hint : 'SEK';
        }

        foreach (self::SYMBOLS as $symbol => $code) {
            $found = ctype_alpha(substr($symbol, 0, 1))
                ? preg_match('/(?<![A-Za-z])'.preg_quote($symbol, '/').'(?![A-Za-z])/u', $text)
                : str_contains($text, $symbol);
            if ($found) {
                foreach (self::SYMBOL_FAMILIES as $familySymbol => $family) {
                    if ($symbol === $familySymbol && $hint && in_array($hint, $family, true)) {
                        return $hint;
                    }
                }

                return $code;
            }
        }

        return null;
    }

    private function toFloat(string $number, ?string $currency): ?float
    {
        $n = preg_replace('/[\s\x{00A0}\x{202F}\']/u', '', $number) ?? '';
        $dots = substr_count($n, '.');
        $commas = substr_count($n, ',');

        if ($dots && $commas) {
            $decimal = strrpos($n, '.') > strrpos($n, ',') ? '.' : ',';
            $thousands = $decimal === '.' ? ',' : '.';
            $n = str_replace([$thousands, $decimal], ['', '.'], $n);
        } elseif ($dots + $commas > 0) {
            $sep = $dots ? '.' : ',';
            $count = $dots ?: $commas;
            $after = strlen(substr($n, strrpos($n, $sep) + 1));
            $digits = $currency ? Money::fractionDigits($currency) : 2;

            $isDecimal = $count === 1 && ($after !== 3 || ($digits === 3 && $sep === '.'));
            $n = $isDecimal ? str_replace($sep, '.', $n) : str_replace($sep, '', $n);
        }

        return is_numeric($n) ? (float) $n : null;
    }
}
