<?php

namespace App\Scraping\Fetching;

/**
 * Decides whether a static page probably needs a browser to show prices.
 */
class RenderHeuristics
{
    public static function looksClientRendered(string $html): bool
    {
        // Empty SPA root container.
        if (preg_match('#<div[^>]+id=["\'](root|app|__next|__nuxt|svelte)["\'][^>]*>\s*</div>#i', $html)) {
            return true;
        }

        // Next.js payload present but no offer/price data in it.
        if (preg_match('#<script[^>]+id=["\']__NEXT_DATA__["\'][^>]*>(.*?)</script>#is', $html, $m)
            && ! preg_match('/"(offers|price|priceAmount|salePrice)"\s*:/i', $m[1])) {
            return true;
        }

        // High script-to-text ratio.
        $scriptBytes = 0;
        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $scripts)) {
            $scriptBytes = array_sum(array_map('strlen', $scripts[1]));
        }
        $text = preg_replace(['#<script\b[^>]*>.*?</script>#is', '#<style\b[^>]*>.*?</style>#is'], '', $html) ?? '';
        $textBytes = strlen(trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? ''));

        return $textBytes < 500 || ($scriptBytes > 0 && $scriptBytes / max(1, $scriptBytes + $textBytes) > 0.85);
    }
}
