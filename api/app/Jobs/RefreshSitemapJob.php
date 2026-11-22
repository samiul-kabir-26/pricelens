<?php

namespace App\Jobs;

use App\Models\Source;
use App\Scraping\Discovery\SitemapIndexer;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

/**
 * Re-index a site source's sitemaps: expand the sitemap indexes, then index each leaf file in its own job
 * (large shops have hundreds of 10–50k-URL files), spaced out for politeness. Entries not seen in a fully
 * successful run are pruned.
 */
class RefreshSitemapJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 120;

    public int $tries = 3;

    public function __construct(public int $sourceId) {}

    public function handle(SitemapIndexer $indexer): void
    {
        $source = Source::acrossTenants()->where('type', 'site')->find($this->sourceId);
        if (! $source || ! $source->cfg('sitemap_urls')) {
            return;
        }

        $files = $indexer->leafSitemaps($source);
        if ($files === []) {
            return;
        }

        $seenAt = now()->startOfSecond();
        $spacing = (int) config('pricelens.sitemaps.seconds_between_files', 2);
        $jobs = array_map(
            fn (string $file, int $i) => (new IndexSitemapFileJob($source->id, $file, $seenAt->toIso8601String()))->delay($i * $spacing),
            $files, array_keys($files),
        );

        $sourceId = $source->id;
        $iso = $seenAt->toIso8601String();
        Bus::batch($jobs)
            ->name("sitemaps:{$source->ulid}")
            ->onQueue('default')
            ->allowFailures() // one bad file must not stop the others
            ->then(function (Batch $batch) use ($sourceId, $iso) {
                // Only runs when every file succeeded, so a partial run never prunes live entries.
                $source = Source::acrossTenants()->find($sourceId);
                if ($source) {
                    app(SitemapIndexer::class)->prune($source, Carbon::parse($iso));
                }
            })
            ->finally(function (Batch $batch) use ($sourceId) {
                Source::acrossTenants()->whereKey($sourceId)->update(['sitemap_refreshed_at' => now()]);
            })
            ->dispatch();
    }
}
