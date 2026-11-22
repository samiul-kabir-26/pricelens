<?php

namespace App\Scraping\Normalization;

class AvailabilityMapper
{
    public const VALUES = ['in_stock', 'out_of_stock', 'preorder', 'backorder', 'limited', 'discontinued', 'unknown'];

    public static function map(mixed $raw): string
    {
        if (is_bool($raw)) {
            return $raw ? 'in_stock' : 'out_of_stock';
        }
        if (! is_string($raw) || trim($raw) === '') {
            return 'unknown';
        }

        $v = strtolower(trim($raw));
        $v = preg_replace('#^https?://(www\.)?schema\.org/#', '', $v) ?? $v;
        $v = trim(preg_replace('/\s+/', ' ', $v) ?? $v);

        if (in_array($v, self::VALUES, true)) {
            return $v;
        }

        return match (true) {
            in_array($v, ['instock', 'onlineonly', 'instoreonly'], true) => 'in_stock',
            in_array($v, ['outofstock', 'soldout'], true) => 'out_of_stock',
            in_array($v, ['preorder', 'presale'], true) => 'preorder',
            $v === 'backorder' => 'backorder',
            $v === 'limitedavailability' => 'limited',
            $v === 'discontinued' => 'discontinued',
            (bool) preg_match('/discontinued|no longer available/', $v) => 'discontinued',
            (bool) preg_match('/pre-?order|coming soon|available for pre/', $v) => 'preorder',
            (bool) preg_match('/back-?order/', $v) => 'backorder',
            (bool) preg_match('/out of stock|sold out|unavailable|not available|currently unavailable|ausverkauft|agotado|épuisé/', $v) => 'out_of_stock',
            (bool) preg_match('/only \d+ left|few left|low stock|limited/', $v) => 'limited',
            (bool) preg_match('/in stock|available|add to (cart|basket)|ships|auf lager|en stock|disponible|lieferbar/', $v) => 'in_stock',
            default => 'unknown',
        };
    }
}
