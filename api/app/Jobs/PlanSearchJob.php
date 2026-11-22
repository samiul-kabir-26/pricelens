<?php

namespace App\Jobs;

use App\Exceptions\ProblemException;
use App\Jobs\Middleware\TenantAware;
use App\Jobs\Middleware\TraceJob;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\SearchTask;
use App\Scraping\Planning\ProductQueryBuilder;
use App\Scraping\Planning\SearchPlanner;
use App\Services\SearchProgress;
use App\Tenancy\CurrentTenant;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Bus;

/**
 * Stage 1: normalize the query, build the plan, fan out a DiscoverJob per planned source in a batch whose
 * `finally` runs FinalizeSearchJob.
 */
class PlanSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $searchId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TraceJob, new TenantAware];
    }

    public function handle(ProductQueryBuilder $builder, SearchPlanner $planner, SearchProgress $progress, WebhookDispatcher $webhooks): void
    {
        $tenant = CurrentTenant::instance()->get();
        $search = Search::query()->find($this->searchId);
        if (! $search || $search->status !== Search::STATUS_QUEUED) {
            return;
        }

        $search->update(['status' => Search::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $query = $builder->build($tenant, $search);
        } catch (ProblemException $e) {
            $search->update(['error' => $e->code_.': '.$e->getMessage()]);
            FinalizeSearchJob::dispatchSync($this->tenantId, $this->searchId);

            return;
        }

        if (! $query->title && ! $query->gtin && ! $query->mpn && ! $query->referenceUrl) {
            $search->update(['product_query' => $query->toArray(), 'error' => 'query_unresolvable']);
            FinalizeSearchJob::dispatchSync($this->tenantId, $this->searchId);

            return;
        }

        $search->update(['product_query' => $query->toArray()]);
        $sources = $planner->plan($tenant, $search, $query);

        if ($sources->isEmpty()) {
            $search->update(['error' => 'no_sources']);
            FinalizeSearchJob::dispatchSync($this->tenantId, $this->searchId);

            return;
        }

        $jobs = $sources->map(function (SearchSource $ss) {
            $task = SearchTask::query()->create([
                'search_id' => $this->searchId,
                'search_source_id' => $ss->id,
                'source_id' => $ss->source_id,
                'stage' => 'discover',
                'url' => null,
                'status' => 'pending',
            ]);
            $ss->update(['status' => 'running']);

            return (new DiscoverJob($this->tenantId, $this->searchId, $ss->id, $task->id))->onQueue('discovery');
        })->all();
        $progress->tasksAdded($search, count($jobs));

        $tenantId = $this->tenantId;
        $searchId = $this->searchId;
        $batch = Bus::batch($jobs)
            ->name("search:{$search->ulid}")
            ->allowFailures()
            ->finally(function (Batch $batch) use ($tenantId, $searchId) {
                FinalizeSearchJob::dispatch($tenantId, $searchId)->onQueue('orchestration');
            })
            ->onQueue('discovery')
            ->dispatch();

        Search::query()->whereKey($search->id)->whereNull('batch_id')->update(['batch_id' => $batch->id]);

        $webhooks->dispatch($tenant, 'search.started', [
            'search_id' => $search->ulid,
            'sources' => $progress->sources($search),
            'product_query' => collect($query->toArray())->except(['image_path', 'visual_matches'])->all(),
        ]);
        $progress->broadcast($search, force: true);
    }
}
