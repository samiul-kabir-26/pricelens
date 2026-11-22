<?php

namespace App\Console\Commands;

use App\Jobs\RefreshSitemapJob;
use App\Models\SitemapEntry;
use App\Models\Source;
use App\Scraping\Discovery\SitemapIndexer;
use App\Scraping\Fetching\FetchException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Index the sitemaps of site sources that have `sitemap_urls`. Queued by default; --sync runs inline
 * (useful for the first index of a large shop, with progress output).
 */
class RefreshSitemapsCommand extends Command
{
    protected $signature = 'pricelens:sitemaps:refresh {source? : Source ULID or domain (default: all sitemap sources)} {--sync : Index inline instead of queueing}';

    protected $description = 'Index product URLs from site sources\' sitemaps';

    public function handle(SitemapIndexer $indexer): int
    {
        /** @var Collection<int, Source> $sources */
        $sources = Source::acrossTenants()->where('type', 'site')->where('enabled', true)->get()
            ->filter(fn (Source $s) => (bool) $s->cfg('sitemap_urls'))
            ->when($this->argument('source'), fn ($c, $key) => $c->filter(fn (Source $s) => $s->ulid === $key || $s->domain === $key));

        if ($sources->isEmpty()) {
            $this->warn('No enabled site sources with sitemap_urls matched.');

            return self::FAILURE;
        }

        $status = self::SUCCESS;
        foreach ($sources as $source) {
            if (! $this->option('sync')) {
                RefreshSitemapJob::dispatch($source->id)->onQueue('default');
                $this->line("Queued {$source->domain}");

                continue;
            }

            $seenAt = now()->startOfSecond();
            try {
                $files = $indexer->leafSitemaps($source);
            } catch (FetchException $e) {
                $this->error("{$source->domain}: {$e->errorCode} ({$e->getMessage()})");
                $status = self::FAILURE;

                continue;
            }
            $this->info("{$source->domain}: ".count($files).' sitemap file(s)');
            $total = 0;
            $failed = 0;
            foreach ($files as $i => $file) {
                try {
                    $n = $indexer->indexFile($source, $file, $seenAt);
                    $total += $n;
                    $this->line(sprintf('  [%d/%d] %s → %d', $i + 1, count($files), $file, $n));
                } catch (FetchException $e) {
                    $failed++;
                    $this->warn(sprintf('  [%d/%d] %s → %s', $i + 1, count($files), $file, $e->errorCode));
                }
                usleep((int) config('pricelens.sitemaps.seconds_between_files', 2) * 1_000_000);
            }
            if ($failed === 0) {
                $indexer->prune($source, $seenAt);
            }
            $source->forceFill(['sitemap_refreshed_at' => now()])->save();
            $this->info("{$source->domain}: {$total} entries written, ".SitemapEntry::query()->where('source_id', $source->id)->count().' indexed'.($failed ? ", {$failed} file(s) failed (not pruned)" : ''));
        }

        return $status;
    }
}
