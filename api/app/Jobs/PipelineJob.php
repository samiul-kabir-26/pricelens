<?php

namespace App\Jobs;

use App\Jobs\Middleware\EnsureSearchNotCancelled;
use App\Jobs\Middleware\TenantAware;
use App\Jobs\Middleware\TenantFairness;
use App\Jobs\Middleware\TraceJob;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\SearchTask;
use App\Models\Source;
use App\Models\Tenant;
use App\Observability\Metrics;
use App\Services\SearchProgress;
use App\Services\SourceHealthRecorder;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Base for discover/fetch/render/extract jobs. Every job carries tenantId and owns one search_tasks row.
 */
abstract class PipelineJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    private ?Search $searchModel = null;

    private ?SearchSource $searchSourceModel = null;

    public function __construct(
        public int $tenantId,
        public int $searchId,
        public ?int $searchSourceId,
        public int $taskId,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TraceJob, new TenantAware, new TenantFairness, new EnsureSearchNotCancelled];
    }

    /** Host used by DomainThrottle / DomainCircuitBreaker; null = not throttled. */
    public function throttleHost(): ?string
    {
        return null;
    }

    protected function tenant(): Tenant
    {
        return CurrentTenant::instance()->get();
    }

    protected function search(): Search
    {
        return $this->searchModel ??= Search::query()->findOrFail($this->searchId);
    }

    protected function searchSource(): ?SearchSource
    {
        if (! $this->searchSourceId) {
            return null;
        }

        return $this->searchSourceModel ??= SearchSource::query()->where('search_id', $this->searchId)->find($this->searchSourceId);
    }

    public function source(): ?Source
    {
        $ss = $this->searchSource();

        return $ss?->source_id ? Source::acrossTenants()->find($ss->source_id) : null;
    }

    protected function task(): SearchTask
    {
        return SearchTask::query()->findOrFail($this->taskId);
    }

    /**
     * Throttle/fairness releases must not burn retries, so jobs are bounded by time; real execution attempts
     * are counted on the task row and compared with $tries in canRetry().
     */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(20);
    }

    protected function startTask(): void
    {
        SearchTask::query()->whereKey($this->taskId)->update(['status' => 'running']);
        SearchTask::query()->whereKey($this->taskId)->increment('attempts');
    }

    protected function taskAttempts(): int
    {
        return max(1, (int) SearchTask::query()->whereKey($this->taskId)->value('attempts'));
    }

    /** @param array<string, mixed> $attributes */
    protected function completeTask(string $status = 'succeeded', array $attributes = []): void
    {
        $task = $this->task();
        if (in_array($task->status, ['succeeded', 'failed', 'blocked', 'skipped_robots', 'skipped_policy', 'cancelled'], true)) {
            return; // idempotent on retries
        }
        $task->fill($attributes + ['status' => $status])->save();

        Metrics::increment('pricelens_tasks_total', ['stage' => $task->stage, 'status' => $status, 'error_code' => (string) ($attributes['error_code'] ?? '')]);
        app(SearchProgress::class)->taskFinished($this->search());
    }

    public function failTask(string $status, string $errorCode, ?string $message = null, ?int $httpStatus = null): void
    {
        $this->completeTask($status, array_filter([
            'error_code' => $errorCode,
            'error_message' => $message ? mb_substr($message, 0, 500) : null,
            'http_status' => $httpStatus,
        ], fn ($v) => $v !== null));

        if ($this->searchSourceId && $source = $this->source()) {
            app(SourceHealthRecorder::class)->record($source, $this->task()->stage, $status === 'blocked' ? 'blocked' : 'fail');
        }
    }

    public function markCancelled(): void
    {
        $this->completeTask('cancelled');
    }

    /** Create a follow-up task row and count it in progress. */
    protected function newTask(string $stage, ?string $url): SearchTask
    {
        $task = SearchTask::query()->create([
            'search_id' => $this->searchId,
            'search_source_id' => $this->searchSourceId,
            'source_id' => $this->searchSource()?->source_id,
            'stage' => $stage,
            'url' => $url,
            'url_hash' => $url ? sha1($url) : null,
            'status' => 'pending',
        ]);
        app(SearchProgress::class)->tasksAdded($this->search(), 1);

        return $task;
    }

    /** Backoff for retryable failures: [10, 30, 90] s, honoring Retry-After. */
    protected function retryDelay(?int $retryAfter = null): int
    {
        $schedule = config('pricelens.scraper.retry_backoff', [10, 30, 90]);
        $base = $schedule[min($this->taskAttempts() - 1, count($schedule) - 1)] ?? 90;

        return max($base, min((int) $retryAfter, 600)) + random_int(0, 3);
    }

    protected function canRetry(): bool
    {
        return $this->taskAttempts() < $this->tries;
    }

    public function failed(\Throwable $e): void
    {
        try {
            $tenant = Tenant::query()->find($this->tenantId);
            if ($tenant) {
                CurrentTenant::instance()->runAs($tenant, fn () => $this->failTask('failed', 'exception', $e->getMessage()));
            }
        } catch (\Throwable) {
            // best effort
        }
    }
}
