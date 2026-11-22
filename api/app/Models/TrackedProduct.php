<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string|null $external_ref
 * @property string $title
 * @property string|null $brand
 * @property string|null $gtin
 * @property string|null $mpn
 * @property string|null $image_url
 * @property string|null $image_path
 * @property string|null $image_phash
 * @property int|null $reference_price_minor
 * @property int|null $map_price_minor
 * @property string $currency
 * @property array<string, mixed>|null $attributes
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Collection<int, Monitor> $monitors
 * @property-read Collection<int, PriceSnapshot> $snapshots
 * @property-read Collection<int, Alert> $alerts
 */
class TrackedProduct extends Model
{
    use BelongsToTenant, HasPublicUlid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['attributes' => 'array', 'archived_at' => 'datetime'];
    }

    /** @return HasMany<Monitor, $this> */
    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** @return HasMany<PriceSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(PriceSnapshot::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
