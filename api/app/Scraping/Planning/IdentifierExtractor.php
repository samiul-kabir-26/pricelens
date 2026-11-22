<?php

namespace App\Scraping\Planning;

/**
 * Pulls GTINs, model numbers, brands and variant attributes out of free text.
 */
class IdentifierExtractor
{
    private const UNIT_TOKEN = '/^\d+(?:\.\d+)?(tb|gb|mb|mm|cm|m|inch|in|hz|khz|ghz|w|kw|mah|wh|ml|l|oz|kg|g|lb|lbs|mp|k|v|pcs|ct|p|x|th|nd|rd|st|s)$/i';

    public function __construct(private QueryNormalizer $normalizer) {}

    public function gtin(?string $text): ?string
    {
        if (! $text || ! preg_match_all('/(?<!\d)(\d{8}|\d{12,14})(?!\d)/', $text, $m)) {
            return null;
        }
        foreach ($m[1] as $candidate) {
            if (GtinValidator::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Model/MPN: alphanumeric tokens that contain both letters and digits (e.g. WH-1000XM5, SM-S928B),
     * excluding units (256GB, 65W) and years. Longest wins.
     */
    public function model(?string $text, ?string $brand = null): ?string
    {
        if (! $text) {
            return null;
        }
        preg_match_all('/(?<![\p{L}\p{N}])([A-Za-z0-9]+(?:[-\/.][A-Za-z0-9]+)*)(?![\p{L}\p{N}])/u', $text, $m);

        $best = null;
        foreach ($m[1] as $token) {
            $token = rtrim($token, '.');
            $flat = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';
            if (strlen($flat) < 4 || ! preg_match('/\d/', $flat) || ! preg_match('/[A-Za-z]/', $flat)) {
                continue;
            }
            if (preg_match(self::UNIT_TOKEN, $flat) || preg_match('/^(19|20)\d{2}s?$/', $flat)) {
                continue;
            }
            if ($brand && strcasecmp($flat, preg_replace('/[^A-Za-z0-9]/', '', $brand) ?? '') === 0) {
                continue;
            }
            if ($best === null || strlen($flat) > strlen(preg_replace('/[^A-Za-z0-9]/', '', $best) ?? '')) {
                $best = $token;
            }
        }

        return $best ? strtoupper($best) : null;
    }

    /**
     * @param  array<int, string>  $extraBrands  tenant brand dictionary
     */
    public function brand(?string $text, array $extraBrands = []): ?string
    {
        if (! $text) {
            return null;
        }
        $lower = mb_strtolower($text);

        foreach (config('pricelens.matching.brand_aliases', []) as $alias => $canonical) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($alias, '/').'(?![\p{L}\p{N}])/u', $lower)) {
                return $canonical;
            }
        }

        $brands = array_merge($extraBrands, config('pricelens.matching.brands', []));
        $bestPos = PHP_INT_MAX;
        $best = null;
        foreach ($brands as $brand) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower($brand), '/').'(?![\p{L}\p{N}])/u', $lower, $m, PREG_OFFSET_CAPTURE)
                && $m[0][1] < $bestPos) {
                $bestPos = $m[0][1];
                $best = $brand;
            }
        }

        return $best;
    }

    public static function canonicalBrand(?string $brand): ?string
    {
        if ($brand === null || trim($brand) === '') {
            return null;
        }
        $lower = mb_strtolower(trim($brand));
        $aliases = config('pricelens.matching.brand_aliases', []);

        return mb_strtolower($aliases[$lower] ?? $brand);
    }

    /**
     * Variant attributes: storage (256gb), size (13.3inch / 42mm), color, pack quantity.
     *
     * @return array<string, string|int>
     */
    public function attributes(?string $text): array
    {
        if (! $text) {
            return [];
        }
        $t = $this->normalizer->normalizeUnits(mb_strtolower($text));
        $t = str_replace(['"', '″'], 'inch', $t);
        $attrs = [];

        if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)(tb|gb)(?![a-z])/', $t, $m)) {
            $attrs['storage'] = ($m[2] === 'tb' ? (int) round((float) $m[1] * 1024) : (int) $m[1]).'gb';
        }
        if (preg_match('/(?<![\d.])(\d+(?:\.\d+)?)(inch|in|mm|cm)(?![a-z])/', $t, $m)) {
            $attrs['size'] = rtrim(rtrim($m[1], '0'), '.').($m[2] === 'in' ? 'inch' : $m[2]);
            if (! str_contains($m[1], '.')) {
                $attrs['size'] = $m[1].($m[2] === 'in' ? 'inch' : $m[2]);
            }
        }

        $colors = config('pricelens.matching.colors', []);
        usort($colors, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($colors as $color) {
            if (preg_match('/(?<![\p{L}])'.preg_quote($color, '/').'(?![\p{L}])/u', $t)) {
                $attrs['color'] = $color === 'grey' ? 'gray' : $color;
                break;
            }
        }

        if (preg_match('/(?:pack of|set of)\s*(\d+)|(\d+)\s*-?(?:pack|pcs|count|ct|pk)(?![a-z])/', $t, $m)) {
            $attrs['pack'] = (int) ($m[1] !== '' ? $m[1] : $m[2]);
        }

        return $attrs;
    }
}
