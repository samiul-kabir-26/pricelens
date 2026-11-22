<?php

namespace App\Services;

use App\Models\Monitor;
use App\Models\Search;
use App\Models\Tenant;

/**
 * Starts a pipeline search for a monitor's tracked product; results become price snapshots on completion.
 */
class MonitorRunner
{
    public function __construct(private SearchService $searches) {}

    public function run(Tenant $tenant, Monitor $monitor): Search
    {
        $product = $monitor->trackedProduct;

        $sources = array_filter(['source_ids' => array_values((array) ($monitor->source_ids ?? []))]);
        $sources += array_intersect_key((array) ($monitor->options['sources'] ?? []), array_flip(['domains', 'search_engines', 'reverse_image']));

        $input = array_filter([
            'title' => $product->title,
            'brand' => $product->brand,
            'gtin' => $product->gtin,
            'mpn' => $product->mpn,
            'image_url' => $product->image_url,
            'image_upload_path' => $product->image_path,
            'sources' => $sources ?: null,
        ]);

        $options = array_merge(
            ['condition' => 'new', 'include_possible' => false],
            collect($monitor->options ?? [])->except('sources')->all(),
            ['target_currency' => $product->currency, 'fresh' => true],
        );

        $monitor->forceFill(['last_run_at' => now()])->save();

        return $this->searches->create($tenant, $input, $options, monitor: $monitor);
    }
}
