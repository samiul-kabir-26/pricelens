<?php

namespace App\Services;

use App\Events\SearchCompleted;
use App\Models\Offer;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\SearchTask;
use App\Models\Tenant;
use App\Monitoring\AlertEvaluator;
use App\Monitoring\SnapshotWriter;
use App\Observability\Metrics;
use App\Scraping\Normalization\PriceStats;
use App\Support\Money;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Stage 7: secondary dedupe, outlier flags, summary stats, final status, events, monitor follow-up.
 */
class SearchFinalizer
{
    public function __construct(
        private WebhookDispatcher $webhooks,
        private SearchProgress $progress,
        private SnapshotWriter $snapshots,
        private AlertEvaluator $alerts,
    ) {}

    public function finalize(Tenant $tenant, Search $search): Search
    {
        $lock = Cache::lock("search:{$search->id}:finalize", 30);
        if (! $lock->get()) {
            return $search;
        }

        try {
            $search->refresh();
            if (in_array($search->status, [Search::STATUS_COMPLETED, Search::STATUS_PARTIAL, Search::STATUS_FAILED], true)) {
                return $search;
            }

            $this->dedupeBySku($search);
            $this->flagOutliers($search);
            $stats = $this->stats($search);
            $this->closeSearchSources($search);

            $tasks = SearchTask::query()->where('search_id', $search->id)->get(['status', 'stage']);
            $failed = $tasks->whereIn('status', ['failed', 'blocked'])->count();
            // Discovery alone succeeding doesn't make a search useful; count work past discovery.
            $succeeded = $tasks->where('status', 'succeeded')->where('stage', '!=', 'discover')->count();
            $offers = Offer::query()->where('search_id', $search->id)->count();

            $status = match (true) {
                $search->status === Search::STATUS_CANCELLED => Search::STATUS_CANCELLED,
                $tasks->isEmpty() => Search::STATUS_FAILED,
                $failed > 0 && $succeeded === 0 && $offers === 0 => Search::STATUS_FAILED,
                $failed > 0 => Search::STATUS_PARTIAL,
                default => Search::STATUS_COMPLETED,
            };

            $search->fill([
                'status' => $status,
                'stats' => $stats,
                'error' => $status === Search::STATUS_FAILED ? ($search->error ?? ($tasks->isEmpty() ? 'no_sources' : 'all_tasks_failed')) : $search->error,
                'completed_at' => now(),
                'progress_done' => $search->progress_total,
            ])->save();

            Cache::increment("{$tenant->cachePrefix()}:search:{$search->id}:offers:v");
            if ($search->started_at) {
                Metrics::observe('pricelens_search_duration_seconds', (float) $search->started_at->diffInMilliseconds(now()) / 1000, ['status' => $status]);
            }
        } finally {
            $lock->release();
        }

        $this->progress->broadcast($search, force: true);
        try {
            event(new SearchCompleted($search->channelName(), $search->ulid, $search->status, $search->stats));
        } catch (Throwable $e) {
            report($e);
        }

        $event = $search->status === Search::STATUS_FAILED ? 'search.failed' : ($search->status === Search::STATUS_CANCELLED ? null : 'search.completed');
        if ($event) {
            $payload = [
                'search_id' => $search->ulid, 'status' => $search->status, 'stats' => $search->stats, 'error' => $search->error,
                'monitor_id' => $search->monitor?->ulid,
            ];
            $this->webhooks->dispatch($tenant, $event, $payload);
            if ($callbackUrl = $search->option('callback_url')) {
                $this->webhooks->dispatchCallback($tenant, (string) $callbackUrl, $event, $payload);
            }
        }

        if ($search->monitor_id && $search->status !== Search::STATUS_CANCELLED && $search->status !== Search::STATUS_FAILED) {
            $monitor = $search->monitor;
            if ($monitor) {
                $written = $this->snapshots->write($search, $monitor);
                $alerts = $this->alerts->evaluate($tenant, $monitor, $written);
                $this->webhooks->dispatch($tenant, 'monitor.run_completed', [
                    'monitor_id' => $monitor->ulid, 'tracked_product_id' => $monitor->trackedProduct?->ulid, 'search_id' => $search->ulid,
                    'snapshots_written' => count($written), 'alerts_triggered' => count($alerts), 'stats' => $search->stats,
                ]);
            }
        }

        return $search;
    }

    private function dedupeBySku(Search $search): void
    {
        $offers = Offer::query()->where('search_id', $search->id)->whereNotNull('sku')->get();
        foreach ($offers->groupBy(fn (Offer $o) => $o->site_domain.'|'.mb_strtolower((string) $o->sku)) as $group) {
            if ($group->count() < 2) {
                continue;
            }
            $keep = $group->sortBy([fn (Offer $a, Offer $b) => $b->match_confidence <=> $a->match_confidence, fn (Offer $a, Offer $b) => $a->totalMinor() <=> $b->totalMinor()])->firstOrFail();
            Offer::query()->whereIn('id', $group->pluck('id')->reject(fn ($id) => $id === $keep->id))->update(['is_duplicate' => true]);
        }
    }

    private function flagOutliers(Search $search): void
    {
        $offers = Offer::query()->where('search_id', $search->id)->where('is_duplicate', false)
            ->where('match_band', '!=', 'rejected')->whereNotNull('converted_total_minor')->get(['id', 'converted_total_minor']);
        $flags = PriceStats::outliers($offers->pluck('converted_total_minor', 'id')->map(fn ($v) => (int) $v)->all());

        $outlierIds = array_keys(array_filter($flags));
        Offer::query()->where('search_id', $search->id)->update(['price_outlier' => false]);
        if ($outlierIds !== []) {
            Offer::query()->whereIn('id', $outlierIds)->update(['price_outlier' => true]);
        }
    }

    /** @return array<string, mixed> */
    private function stats(Search $search): array
    {
        $offers = Offer::query()->where('search_id', $search->id)
            ->whereIn('match_band', ['exact', 'likely'])
            ->where('availability', 'in_stock')
            ->where('is_duplicate', false)->where('price_outlier', false)
            ->whereNotNull('converted_total_minor')
            ->get(['ulid', 'site_domain', 'converted_total_minor']);

        $currency = $search->target_currency;
        $summary = PriceStats::summary($offers->pluck('converted_total_minor')->map(fn ($v) => (int) $v)->all());
        if ($summary === null) {
            return ['currency' => $currency, 'min' => null, 'p25' => null, 'median' => null, 'p75' => null, 'max' => null, 'mean' => null,
                'offer_count' => 0, 'site_count' => 0, 'cheapest_offer_id' => null];
        }

        $cheapest = $offers->sortBy('converted_total_minor')->first();

        return array_map(fn ($v) => Money::format($v, $currency), $summary) + [
            'currency' => $currency,
            'offer_count' => $offers->count(),
            'site_count' => $offers->pluck('site_domain')->unique()->count(),
            'cheapest_offer_id' => $cheapest?->ulid,
        ];
    }

    private function closeSearchSources(Search $search): void
    {
        foreach (SearchSource::query()->where('search_id', $search->id)->get() as $ss) {
            $tasks = SearchTask::query()->where('search_source_id', $ss->id)->get(['status', 'error_code']);
            $failed = $tasks->whereIn('status', ['failed', 'blocked']);
            $status = match (true) {
                $search->status === Search::STATUS_CANCELLED => 'cancelled',
                $ss->offers_found > 0 && $failed->isNotEmpty() => 'partially_completed',
                $ss->offers_found > 0 || ($tasks->isNotEmpty() && $failed->isEmpty()) => 'completed',
                default => 'failed',
            };
            $ss->update([
                'status' => $status,
                'error' => $status === 'failed' ? ($ss->error ?? $failed->pluck('error_code')->filter()->first() ?? $tasks->pluck('status')->first()) : $ss->error,
            ]);
        }
    }
}
