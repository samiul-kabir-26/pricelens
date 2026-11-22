<?php

namespace App\Scraping\Planning;

/**
 * Title cleaning and tokenization shared by planning and matching.
 */
class QueryNormalizer
{
    private const STOPWORDS = ['the', 'and', 'with', 'for', 'of', 'a', 'an', 'in', 'on', 'by', 'to', '&', '-', '|', '/', 'w'];

    private const UNITS = 'tb|gb|mb|mm|cm|m|inch|in|hz|khz|ghz|w|kw|mah|wh|ml|l|oz|fl oz|kg|g|lb|lbs|mp|k|v|pcs|pack|ct';

    /** Lowercase, strip marketing noise, normalize units (`1 TB` → `1tb`), collapse whitespace. */
    public function clean(?string $title): ?string
    {
        if ($title === null || trim($title) === '') {
            return null;
        }

        $t = mb_strtolower(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5));
        $t = str_replace(['™', '®', '©', '"', '“', '”', '″'], ['', '', '', ' inch ', ' ', ' ', ' inch '], $t);

        foreach (config('pricelens.matching.marketing_noise', []) as $noise) {
            $t = preg_replace('/(?<![\p{L}\p{N}])'.$noise.'(?![\p{L}\p{N}])/u', ' ', $t) ?? $t;
        }

        $t = $this->normalizeUnits($t);
        $t = preg_replace('/[!*()\[\]{}<>,;:]+/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+[-–—|]\s+/u', ' ', $t) ?? $t;
        $t = trim(preg_replace('/\s+/u', ' ', $t) ?? $t);

        return $t === '' ? null : $t;
    }

    public function normalizeUnits(string $text): string
    {
        $text = preg_replace('/(\d+(?:[.,]\d+)?)\s*(?:-\s*)?('.self::UNITS.')(?![\p{L}])/iu', '$1$2', $text) ?? $text;

        return preg_replace_callback('/(\d+),(\d+)(?=[a-z])/i', fn ($m) => $m[1].'.'.$m[2], $text) ?? $text;
    }

    /**
     * @return array<int, string>
     */
    public function tokens(?string $text): array
    {
        $clean = $this->clean($text);
        if ($clean === null) {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}.]+/u', $clean) ?: [];
        $tokens = [];
        foreach ($parts as $p) {
            $p = trim($p, '.');
            if ($p !== '' && ! in_array($p, self::STOPWORDS, true)) {
                $tokens[] = $p;
            }
        }

        return array_values(array_unique($tokens));
    }

    /** Model/MPN comparison form: uppercase alphanumerics only. */
    public static function normalizeModel(?string $model): ?string
    {
        if ($model === null) {
            return null;
        }
        $m = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $model) ?? '');

        return $m === '' ? null : $m;
    }
}
