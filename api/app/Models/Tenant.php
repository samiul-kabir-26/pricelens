<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property string $name
 * @property string $slug
 * @property int $plan_id
 * @property string $status
 * @property string $default_country
 * @property string $default_language
 * @property string $default_currency
 * @property array<string, mixed>|null $settings
 * @property string|null $callback_secret
 * @property int $retention_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Plan $plan
 * @property-read TenantUser $pivot membership row, present when loaded through User::tenants()
 * @property-read Collection<int, ApiKey> $apiKeys
 */
class Tenant extends Model
{
    use HasPublicUlid;

    protected $guarded = ['id'];

    protected $hidden = ['callback_secret'];

    protected function casts(): array
    {
        return ['settings' => 'array', 'retention_days' => 'integer', 'callback_secret' => 'encrypted'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsToMany<User, $this, TenantUser, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(TenantUser::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Tenant setting with dot-notation (e.g. `matching.bands.exact`, `domains.blocklist`).
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    /** Plan limit, optionally overridden in tenant settings under `limits.*`. Null = unlimited. */
    public function limit(string $key): ?int
    {
        $override = $this->setting("limits.$key");
        if ($override !== null) {
            return (int) $override;
        }

        return $this->plan?->limit($key);
    }

    public function hasFeature(string $feature): bool
    {
        $override = $this->setting("features.$feature");

        return $override !== null ? (bool) $override : (bool) $this->plan?->hasFeature($feature);
    }

    public function storagePrefix(): string
    {
        return "tenants/{$this->id}";
    }

    public function cachePrefix(): string
    {
        return "tenant:{$this->id}";
    }
}
