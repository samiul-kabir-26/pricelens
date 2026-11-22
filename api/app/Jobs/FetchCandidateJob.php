<?php

namespace App\Jobs;

use App\Billing\QuotaGuard;
use App\Jobs\Middleware\DomainCircuitBreaker;
use App\Jobs\Middleware\DomainThrottle;
use App\Scraping\Fetching\Fetcher;
use App\Scraping\Fetching\FetchException;
use App\Services\SourceHealthRecorder;
use App\Support\Url;

/**
 * Stage 3: polite fetch (static, or rendered when $render) → snapshot → ExtractOfferJob.
 * Middleware: TenantAware → TenantFairness → EnsureSearchNotCancelled → DomainCircuitBreaker → DomainThrottle.
 */
class FetchCandidateJob extends PipelineJob
{
    public int $tries = 4;

    protected bool $render = false;

    public function __construct(int $tenantId, int $searchId, ?int $searchSourceId, int $taskId, public string $url)
    {
        parent::__construct($tenantId, $searchId, $searchSourceId, $taskId);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [...parent::middleware(), new DomainCircuitBreaker, new DomainThrottle];
    }

    public function throttleHost(): ?string
    {
        return Url::host($this->url);
    }

    public function handle(Fetcher $fetcher, SourceHealthRecorder $health, QuotaGuard $quota): void
    {
        $this->startTask();
        $source = $this->source();

        if ($this->render && ! $quota->canRender($this->tenant())) {
            $this->failTask('failed', 'render_quota_exceeded');

            return;
        }

        try {
            $result = $fetcher->fetch($this->tenant(), $this->url, $source, [
                'fresh' => (bool) $this->search()->option('fresh', false),
                'render' => $this->render,
            ]);
        } catch (FetchException $e) {
            if ($e->retryable && $this->canRetry()) {
                $this->release($this->retryDelay($e->retryAfter));

                return;
            }
            $this->failTask($e->taskStatus(), $e->errorCode, $e->getMessage(), $e->httpStatus);

            return;
        }

        $this->completeTask('succeeded', ['http_status' => $result->status, 'duration_ms' => $result->durationMs,
            'error_code' => $result->fromCache ? 'cache_hit' : null]);
        if ($source) {
            $health->record($source, $this->render ? 'render' : 'fetch', 'ok', $result->durationMs);
        }

        $task = $this->newTask('extract', $result->finalUrl);
        $job = (new ExtractOfferJob($this->tenantId, $this->searchId, $this->searchSourceId, $task->id, $this->url, $result->finalUrl, (string) $result->snapshotPath, $result->rendered))
            ->onQueue('extract');

        if ($this->batch() && ! $this->batch()->cancelled()) {
            $this->batch()->add([$job]);
        }
    }
}
