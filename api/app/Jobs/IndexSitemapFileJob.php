<?php

namespace App\Jobs;

use App\Models\Source;
use App\Scraping\Discovery\SitemapIndexer;
use App\Scraping\Fetching\FetchException;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;

/** Index one leaf sitemap file of a site source (see RefreshSitemapJob). */
class IndexSitemapFileJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 85;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $sourceId, public string $url, public string $seenAt) {}

    public function handle(SitemapIndexer $indexer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $source = Source::acrossTenants()->find($this->sourceId);
        if (! $source) {
            return;
        }

        try {
            $indexer->indexFile($source, $this->url, Carbon::parse($this->seenAt));
        } catch (FetchException $e) {
            if ($e->retryable) {
                throw $e;
            }
            // A missing or disallowed file must not block the rest of the run, but it must stop the prune:
            // failing the job marks the batch as having failures, so `then` (prune) does not run.
            $this->fail($e);
        }
    }
}
