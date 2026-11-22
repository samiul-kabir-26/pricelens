<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int|null $monitor_id
 * @property string $status
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $product_query
 * @property array<string, mixed> $options
 * @property string $target_currency
 * @property string $country
 * @property int $progress_total
 * @property int $progress_done
 * @property array<string, mixed>|null $stats
 * @property string|null $error
 * @property string|null $idempotency_key
 * @property string|null $batch_id
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, SearchSource> $searchSources
 * @property-read Collection<int, SearchTask> $tasks
 * @property-read Collection<int, Offer> $offers
 * @property-read Monitor|null $monitor
 */
class Search extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partially_completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const TERMINAL = [self::STATUS_COMPLETED, self::STATUS_PARTIAL, self::STATUS_FAILED, self::STATUS_CANCELLED];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'product_query' => 'array',
            'options' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return HasMany<SearchSource, $this> */
    public function searchSources(): HasMany
    {
        return $this->hasMany(SearchSource::class);
    }

    /** @return HasMany<SearchTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(SearchTask::class);
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->options, $key, $default);
    }

    /** Reverb channel name without the `private-` prefix. */
    public function channelName(): string
    {
        return "tenant.{$this->tenant_id}.search.{$this->ulid}";
    }
}
