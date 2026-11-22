<?php

namespace App\Services;

use App\Billing\QuotaGuard;
use App\Billing\UsageMeter;
use App\Jobs\FinalizeSearchJob;
use App\Jobs\PlanSearchJob;
use App\Models\Monitor;
use App\Models\Search;
use App\Models\SearchTask;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SearchService
{
    public function __construct(private QuotaGuard $quota, private UsageMeter $usage) {}

    /**
     * @param  array<string, mixed>  $input  title, gtin, mpn, brand, image_url, reference_url, sources
     * @param  array<string, mixed>  $options
     */
    public function create(Tenant $tenant, array $input, array $options = [], ?UploadedFile $image = null, ?string $idempotencyKey = null, ?Monitor $monitor = null): Search
    {
        if ($idempotencyKey && ($existing = Search::query()->where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $this->quota->assertCanCreateSearch($tenant);

        $options = $this->normalizeOptions($tenant, $options);
        $ulid = (string) Str::ulid();

        if ($image) {
            $path = "{$tenant->storagePrefix()}/uploads/{$ulid}/".Str::random(8);
            Storage::disk(config('filesystems.default'))->put($path, (string) file_get_contents($image->getRealPath()), ['visibility' => 'private']);
            $input['image_upload_path'] = $path;
        }

        try {
            $search = Search::query()->create([
                'ulid' => $ulid,
                'monitor_id' => $monitor?->id,
                'status' => Search::STATUS_QUEUED,
                'input' => array_filter($input, fn ($v) => $v !== null && $v !== []),
                'options' => $options,
                'target_currency' => $options['target_currency'],
                'country' => $options['country'],
                'idempotency_key' => $idempotencyKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Search::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
        }

        $this->usage->record($tenant, 'searches', 1, 'search', $search->ulid);
        PlanSearchJob::dispatch($tenant->id, $search->id)->onQueue('orchestration');

        return $search;
    }

    public function rerun(Tenant $tenant, Search $search): Search
    {
        $input = $search->input;
        unset($input['image_upload_path']);
        if (! empty($search->product_query['image_path']) && empty($input['image_url'])) {
            $input['image_upload_path'] = $search->product_query['image_path'];
        }

        return $this->create($tenant, $input, $search->options, monitor: $search->monitor);
    }

    public function cancel(Tenant $tenant, Search $search): Search
    {
        if ($search->isTerminal()) {
            return $search;
        }

        if ($search->batch_id && ($batch = Bus::findBatch($search->batch_id))) {
            $batch->cancel();
        }

        $search->update(['status' => Search::STATUS_CANCELLED]);
        SearchTask::query()->where('search_id', $search->id)->whereIn('status', ['pending', 'running'])->update(['status' => 'cancelled']);

        // Finalize directly: a cancelled batch may never reach its `finally` callback.
        FinalizeSearchJob::dispatchSync($tenant->id, $search->id);

        return $search->refresh();
    }

    /**
     * Apply tenant defaults and plan limits to request options.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function normalizeOptions(Tenant $tenant, array $options): array
    {
        $maxResults = (int) ($options['max_results'] ?? config('pricelens.defaults.max_results'));
        $planMax = $tenant->limit('max_results_per_search');

        return array_filter([
            'country' => strtoupper((string) ($options['country'] ?? $tenant->default_country)),
            'language' => strtolower((string) ($options['language'] ?? $tenant->default_language)),
            'target_currency' => strtoupper((string) ($options['target_currency'] ?? $tenant->default_currency)),
            'condition' => $options['condition'] ?? null,
            'max_results' => $planMax ? min($maxResults, $planMax) : $maxResults,
            'include_possible' => (bool) ($options['include_possible'] ?? false),
            'min_confidence' => (float) ($options['min_confidence'] ?? $tenant->setting('matching.min_confidence', config('pricelens.defaults.min_confidence'))),
            'fresh' => (bool) ($options['fresh'] ?? false),
            'callback_url' => $options['callback_url'] ?? null,
            'max_candidates_per_source' => $options['max_candidates_per_source'] ?? null,
        ], fn ($v) => $v !== null);
    }
}
