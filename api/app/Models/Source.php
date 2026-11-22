<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\CurrentTenant;
use App\Tenancy\SourceVisibilityScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $ulid
 * @property int|null $tenant_id
 * @property bool $is_global
 * @property string $type
 * @property string $name
 * @property string|null $domain
 * @property string|null $country
 * @property string|null $currency
 * @property array<string, mixed> $config
 * @property string $render
 * @property bool $enabled
 * @property string $health_status
 * @property int|null $forked_from_id
 * @property Carbon|null $feed_refreshed_at
 * @property Carbon|null $sitemap_refreshed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant|null $tenant
 * @property-read Source|null $forkedFrom
 */
class Source extends Model
{
    use HasPublicUlid;

    public const TYPES = ['site', 'search_engine', 'shopping_engine', 'reverse_image', 'feed'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'enabled' => 'boolean',
            'is_global' => 'boolean',
            'feed_refreshed_at' => 'datetime',
            'sitemap_refreshed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new SourceVisibilityScope);

        static::creating(function (Source $source) {
            if (! $source->is_global && $source->tenant_id === null) {
                $source->tenant_id = CurrentTenant::instance()->id();
            }
        });
    }

    /** @return Builder<self> */
    public static function acrossTenants(): Builder
    {
        return static::withoutGlobalScope(SourceVisibilityScope::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function forkedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'forked_from_id');
    }

    public function cfg(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->config ?? [], $path, $default);
    }

    public function isOwnedBy(int $tenantId): bool
    {
        return ! $this->is_global && $this->tenant_id === $tenantId;
    }

    /** Whether the current tenant has this source enabled (tenant-owned flag, or opt-in row for global adapters). */
    public function isEnabledForTenant(int $tenantId): bool
    {
        if (! $this->is_global) {
            return $this->enabled;
        }

        if (! $this->enabled) {
            return false; // paused by platform
        }

        return (bool) DB::table('tenant_global_sources')
            ->where('tenant_id', $tenantId)->where('source_id', $this->id)->value('enabled');
    }

    /**
     * Sources a search uses by default: enabled tenant sources + opted-in global adapters, tenant adapters
     * winning on the same domain.
     *
     * @return Collection<int, static>
     */
    public static function defaultForTenant(int $tenantId): Collection
    {
        $own = static::query()->where('tenant_id', $tenantId)->where('enabled', true)->get();

        $globalIds = DB::table('tenant_global_sources')->where('tenant_id', $tenantId)->where('enabled', true)->pluck('source_id');
        $ownDomains = $own->pluck('domain')->filter()->all();
        $globals = static::query()->whereIn('id', $globalIds)->where('is_global', true)->where('enabled', true)->get()
            ->reject(fn (Source $s) => $s->domain && in_array($s->domain, $ownDomains, true));

        return $own->concat($globals)->values();
    }
}
