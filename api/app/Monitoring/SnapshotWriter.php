<?php

namespace App\Monitoring;

use App\Models\Monitor;
use App\Models\Offer;
use App\Models\PriceSnapshot;
use App\Models\Search;

/**
 * On monitor-search completion, exact and likely offers become price_snapshots (best offer per site).
 */
class SnapshotWriter
{
    /** @return array<int, PriceSnapshot> */
    public function write(Search $search, Monitor $monitor): array
    {
        $offers = Offer::query()->where('search_id', $search->id)
            ->whereIn('match_band', ['exact', 'likely'])->where('is_duplicate', false)
            ->orderByDesc('match_confidence')->get()
            ->groupBy('site_domain')
            ->map(fn ($group) => $group->sortBy(fn (Offer $o) => $o->converted_total_minor ?? $o->totalMinor())->firstOrFail());

        $written = [];
        foreach ($offers as $offer) {
            $written[] = PriceSnapshot::query()->create([
                'tenant_id' => $search->tenant_id,
                'tracked_product_id' => $monitor->tracked_product_id,
                'site_domain' => $offer->site_domain,
                'url_hash' => $offer->url_hash,
                'url' => $offer->url,
                'seller_name' => $offer->seller_name ?? $offer->site_name,
                'price_minor' => $offer->price_minor,
                'total_minor' => $offer->totalMinor(),
                'currency' => $offer->currency,
                'converted_minor' => $offer->converted_total_minor,
                'availability' => $offer->availability,
                'search_id' => $search->id,
                'observed_at' => $offer->observed_at,
            ]);
        }

        return $written;
    }
}
