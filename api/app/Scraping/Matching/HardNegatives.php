<?php

namespace App\Scraping\Matching;

/**
 * Signals that almost certainly mean "not the same product": accessory keywords absent from the query,
 * conflicting storage/size, or used/refurbished when the search asked for new.
 */
class HardNegatives
{
    /**
     * @param  array<string, string|int>  $queryAttrs
     * @param  array<scalar>  $offerAttrs  extracted + scraped variant attributes (compared as strings)
     * @return array<int, string> reasons
     */
    public function detect(?string $queryTitle, ?string $offerTitle, array $queryAttrs, array $offerAttrs, ?string $requestedCondition, string $offerCondition): array
    {
        $reasons = [];
        $q = mb_strtolower((string) $queryTitle);
        $o = mb_strtolower((string) $offerTitle);

        foreach (config('pricelens.matching.accessory_keywords', []) as $kw) {
            $pattern = '/(?<![\p{L}])'.preg_quote($kw, '/').'(?![\p{L}])/u';
            if (preg_match($pattern, $o) && ! preg_match($pattern, $q)) {
                $reasons[] = "accessory:{$kw}";
                break;
            }
        }

        foreach (['storage', 'size', 'pack'] as $attr) {
            if (isset($queryAttrs[$attr], $offerAttrs[$attr]) && (string) $queryAttrs[$attr] !== (string) $offerAttrs[$attr]) {
                $reasons[] = "conflicting_{$attr}";
            }
        }

        if ($requestedCondition === 'new' && in_array($offerCondition, ['used', 'refurbished'], true)) {
            $reasons[] = "condition:{$offerCondition}";
        }

        return $reasons;
    }
}
