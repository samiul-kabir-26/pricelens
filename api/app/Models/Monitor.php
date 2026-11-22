<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $tracked_product_id
 * @property string $schedule
 * @property string $timezone
 * @property array<int, string>|null $source_ids
 * @property array<string, mixed>|null $options
 * @property array<string, mixed>|null $alert_rules
 * @property string $status
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read TrackedProduct $trackedProduct
 * @property-read Collection<int, Search> $searches
 */
class Monitor extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'source_ids' => 'array',
            'options' => 'array',
            'alert_rules' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrackedProduct, $this> */
    public function trackedProduct(): BelongsTo
    {
        return $this->belongsTo(TrackedProduct::class);
    }

    /** @return HasMany<Search, $this> */
    public function searches(): HasMany
    {
        return $this->hasMany(Search::class);
    }

    public function computeNextRun(?\DateTimeInterface $from = null): Carbon
    {
        $next = (new CronExpression($this->schedule))
            ->getNextRunDate($from ?? now(), 0, false, $this->timezone ?: 'UTC');

        return Carbon::instance($next)->utc();
    }
}
