<?php

namespace App\Scraping\Discovery;

use App\Models\FeedItem;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Tenant;
use App\Scraping\Data\DirectOffer;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Planning\GtinValidator;

/**
 * Tenant merchant feeds (indexed into feed_items): matched by GTIN, MPN, then title tokens, without fetching.
 */
class FeedDiscoverer implements Discoverer
{
    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult
    {
        $limit = (int) $search->option('max_candidates_per_source', config('pricelens.scraper.max_candidates_per_source', 10));
        $base = fn () => FeedItem::query()->where('source_id', $searchSource->source_id);

        $items = collect();
        if ($query->gtin) {
            $items = $base()->whereIn('gtin', array_unique([$query->gtin, ltrim($query->gtin, '0'), GtinValidator::toGtin14($query->gtin)]))->limit($limit)->get();
        }
        if ($items->isEmpty() && $query->mpn) {
            $items = $base()->where('mpn', $query->mpn)->limit($limit)->get();
        }
        if ($items->isEmpty() && $query->tokens !== []) {
            $q = $base();
            foreach (array_slice(array_filter($query->tokens, fn ($t) => mb_strlen($t) >= 3), 0, 4) as $token) {
                $q->where('title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $token).'%');
            }
            $items = $q->limit($limit)->get();
        }

        return new DiscoveryResult(directOffers: $items->map(fn (FeedItem $i) => new DirectOffer($i->link, ExtractedProduct::make(array_filter([
            'title' => $i->title,
            'brand' => $i->brand,
            'gtin' => $i->gtin,
            'mpn' => $i->mpn,
            'sku' => $i->item_id,
            'image_url' => $i->image_link,
            'price' => $i->price,
            'sale_price' => $i->sale_price,
            'availability' => $i->availability,
            'condition' => $i->condition,
            'shipping_price' => $i->shipping,
        ]), 'feed'), $searchSource->label))->all());
    }
}
