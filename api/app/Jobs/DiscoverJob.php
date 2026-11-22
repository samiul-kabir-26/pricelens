<?php

namespace App\Jobs;

use App\Jobs\Middleware\DomainCircuitBreaker;
use App\Jobs\Middleware\DomainThrottle;
use App\Models\SearchSource;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Discovery\DiscovererRegistry;
use App\Scraping\Fetching\FetchException;
use App\Scraping\Providers\ProviderException;
use App\Services\SourceHealthRecorder;
use Illuminate\Support\Facades\Cache;

/**
 * Stage 2: turn the planned source into candidate URLs (deduped per search, capped per source) and
 * direct offers, and add the follow-up fetch/match jobs to the search batch.
 */
class DiscoverJob extends PipelineJob
{
    public int $timeout = 60;

    public int $tries = 4;

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [...parent::middleware(), new DomainCircuitBreaker, new DomainThrottle];
    }

    public function throttleHost(): ?string
    {
        $ss = $this->searchSource();
        $strategy = $ss?->plan['strategy'] ?? null;

        return in_array($strategy, ['site_adapter', 'generic_site'], true) ? ($ss->domain ?? $this->source()?->domain) : null;
    }

    public function handle(DiscovererRegistry $registry, SourceHealthRecorder $health): void
    {
        $this->startTask();
        $search = $this->search();
        $ss = $this->searchSource();
        if (! $ss) {
            $this->failTask('failed', 'missing_source');

            return;
        }

        $query = ProductQuery::fromArray($search->product_query ?? []);
        $started = hrtime(true);

        try {
            $result = $registry->for((string) ($ss->plan['strategy'] ?? 'site_adapter'))->discover($this->tenant(), $search, $ss, $query);
        } catch (FetchException|ProviderException $e) {
            if ($e->retryable && $this->canRetry()) {
                $this->release($this->retryDelay($e instanceof FetchException ? $e->retryAfter : null));

                return;
            }
            $code = $e->errorCode;
            $ss->update(['error' => $code]);
            $this->failTask($e instanceof FetchException ? $e->taskStatus() : 'failed', $code, $e->getMessage(), $e instanceof FetchException ? $e->httpStatus : null);

            return;
        }

        $render = $this->source()?->render === 'always';
        $limit = (int) $search->option('max_candidates_per_source', config('pricelens.scraper.max_candidates_per_source', 10));
        $jobs = [];
        $accepted = 0;

        foreach ($result->candidates as $candidate) {
            if ($accepted >= $limit) {
                break;
            }
            if (! Cache::add("search:{$search->id}:cand:".sha1($candidate->canonicalUrl), 1, now()->addDay())) {
                continue; // already discovered by another source
            }
            $accepted++;
            $task = $this->newTask($render ? 'render' : 'fetch', $candidate->url);
            $jobs[] = $render
                ? (new RenderCandidateJob($this->tenantId, $this->searchId, $ss->id, $task->id, $candidate->url))->onQueue('render')
                : (new FetchCandidateJob($this->tenantId, $this->searchId, $ss->id, $task->id, $candidate->url))->onQueue('fetch');
        }

        foreach ($result->directOffers as $offer) {
            $task = $this->newTask('extract', $offer->url);
            $jobs[] = (new MatchOfferJob($this->tenantId, $this->searchId, $ss->id, $task->id, $offer->toArray()))->onQueue('extract');
        }

        SearchSource::query()->whereKey($ss->id)->update([
            'candidates_found' => $accepted + count($result->directOffers),
            'error' => $result->error,
        ]);

        $first = $result->requests[0] ?? null;
        $durationMs = (int) ((hrtime(true) - $started) / 1e6);

        if ($jobs === [] && $result->error) {
            $this->failTask('failed', $result->error, null, $first['http_status'] ?? null);
        } else {
            $this->completeTask('succeeded', ['http_status' => $first['http_status'] ?? null, 'duration_ms' => $durationMs,
                'url' => $first['url'] ?? null, 'error_code' => $jobs === [] ? 'no_candidates' : null]);
            if ($source = $this->source()) {
                $health->record($source, 'discover', 'ok', $durationMs);
            }
        }

        if ($jobs !== [] && $this->batch() && ! $this->batch()->cancelled()) {
            $this->batch()->add($jobs);
        }
    }
}
