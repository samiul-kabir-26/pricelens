<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Nightly: per (tracked product, site, day) min/avg/max into price_daily for long-range history. */
class RollupPriceDailyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public function __construct(public ?string $date = null) {}

    public function handle(): void
    {
        $day = $this->date ? Carbon::parse($this->date) : now()->utc()->subDay();

        $rows = DB::table('price_snapshots')
            ->selectRaw('tenant_id, tracked_product_id, site_domain, MIN(COALESCE(converted_minor, total_minor, price_minor)) as min_minor, '
                .'AVG(COALESCE(converted_minor, total_minor, price_minor)) as avg_minor, MAX(COALESCE(converted_minor, total_minor, price_minor)) as max_minor, '
                .'MAX(currency) as currency, COUNT(*) as samples')
            ->whereBetween('observed_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->groupBy('tenant_id', 'tracked_product_id', 'site_domain')
            ->get();

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('price_daily')->upsert($chunk->map(fn ($r) => [
                'tenant_id' => $r->tenant_id, 'tracked_product_id' => $r->tracked_product_id, 'site_domain' => $r->site_domain,
                'date' => $day->toDateString(), 'min_minor' => (int) $r->min_minor, 'avg_minor' => (int) round((float) $r->avg_minor),
                'max_minor' => (int) $r->max_minor, 'currency' => $r->currency, 'samples' => (int) $r->samples,
            ])->all(), ['tenant_id', 'tracked_product_id', 'site_domain', 'date'], ['min_minor', 'avg_minor', 'max_minor', 'currency', 'samples']);
        }
    }
}
