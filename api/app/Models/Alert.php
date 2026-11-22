<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property int $monitor_id
 * @property int $tracked_product_id
 * @property string $type
 * @property string|null $site_domain
 * @property array<string, mixed> $payload
 * @property Carbon $triggered_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Monitor $monitor
 * @property-read TrackedProduct $trackedProduct
 */
class Alert extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const TYPES = ['price_drop', 'price_increase', 'map_violation', 'back_in_stock', 'out_of_stock', 'new_seller'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'triggered_at' => 'datetime', 'acknowledged_at' => 'datetime'];
    }

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /** @return BelongsTo<TrackedProduct, $this> */
    public function trackedProduct(): BelongsTo
    {
        return $this->belongsTo(TrackedProduct::class);
    }
}
