<?php

namespace App\Scraping\Discovery;

use App\Models\Source;
use App\Scraping\Data\Candidate;
use App\Scraping\Data\DirectOffer;
use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Extraction\SelectorEngine;
use App\Support\Url;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Turns a search-results page into product candidates, using the adapter's `listing` selectors when present
 * and product-URL patterns otherwise. In `listing_mode` cards become direct offers.
 */
class LinkHarvester
{
    public function __construct(private SelectorEngine $selectors) {}

    /**
     * @return array{candidates: array<int, Candidate>, offers: array<int, DirectOffer>}
     */
    public function harvest(string $html, string $pageUrl, ?Source $source, int $limit): array
    {
        $page = new Crawler($html, $pageUrl);
        $host = Url::siteDomain($pageUrl);
        $listing = $source?->cfg('listing');
        $patterns = $this->patterns($source);
        $candidates = [];
        $offers = [];

        if (is_array($listing) && ! empty($listing['item']) && ! empty($listing['item_link'])) {
            try {
                $cards = $page->filter((string) $listing['item']);
            } catch (\Throwable) {
                $cards = new Crawler;
            }
            foreach ($cards as $i => $node) {
                if (count($candidates) >= $limit) {
                    break;
                }
                $card = new Crawler($node, $pageUrl);
                $href = $this->selectors->first($card, (string) $listing['item_link']);
                $url = $href ? Url::absolutize($href, $pageUrl) : null;
                if (! $url) {
                    continue;
                }
                $title = ! empty($listing['item_title']) ? $this->selectors->first($card, $listing['item_title']) : null;
                $price = ! empty($listing['item_price']) ? $this->selectors->first($card, $listing['item_price']) : null;
                $candidates[] = new Candidate($url, Url::canonicalize($url), $title, array_filter(['price' => $price]));

                if (! empty($listing['listing_mode']) && $price) {
                    $offers[] = new DirectOffer($url, ExtractedProduct::make(array_filter([
                        'title' => $title,
                        'price' => $price,
                        'currency' => $source?->currency,
                        'image_url' => ! empty($listing['item_image']) ? Url::absolutize((string) $this->selectors->first($card, $listing['item_image']), $pageUrl) : null,
                    ]), 'selector'), $source?->name);
                }
            }

            return ['candidates' => $candidates, 'offers' => $offers];
        }

        $seen = [];
        $page->filter('a[href]')->each(function (Crawler $a) use (&$candidates, &$seen, $pageUrl, $host, $patterns, $limit) {
            if (count($candidates) >= $limit) {
                return;
            }
            $url = Url::absolutize((string) $a->attr('href'), $pageUrl);
            if (! $url || ! $host || ! Url::matchesDomain((string) Url::host($url), $host)) {
                return;
            }
            $canonical = Url::canonicalize($url);
            if (isset($seen[$canonical]) || ! $this->matchesAny($url, $patterns)) {
                return;
            }
            $seen[$canonical] = true;
            $text = trim(preg_replace('/\s+/u', ' ', $a->text('')) ?? '');
            $candidates[] = new Candidate($url, $canonical, $text !== '' ? mb_substr($text, 0, 300) : null);
        });

        return ['candidates' => $candidates, 'offers' => []];
    }

    /** @return array<int, string> */
    private function patterns(?Source $source): array
    {
        $custom = (array) ($source?->cfg('product_url_patterns') ?? []);
        if ($custom !== []) {
            return array_map(fn ($p) => '~'.str_replace('~', '\~', $p).'~i', $custom);
        }

        return config('pricelens.scraper.generic_product_url_patterns', []);
    }

    /** @param array<int, string> $patterns */
    private function matchesAny(string $url, array $patterns): bool
    {
        foreach ($patterns as $p) {
            if (@preg_match($p, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
