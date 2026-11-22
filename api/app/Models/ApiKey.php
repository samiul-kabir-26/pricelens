<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Key format: pl_live_{prefix}_{secret}. Only sha256(secret) is stored.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property array<int, string> $abilities
 * @property Carbon|null $last_used_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasPublicUlid;

    public const ABILITIES = [
        'searches:read', 'searches:write', 'sources:write', 'monitors:write', 'webhooks:write', 'admin',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @param  array<int, string>  $abilities
     * @return array{0: ApiKey, 1: string} the model and the plaintext key (shown once)
     */
    public static function generate(Tenant $tenant, string $name, array $abilities, ?\DateTimeInterface $expiresAt = null): array
    {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);

        $key = static::acrossTenants()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $secret),
            'abilities' => array_values($abilities),
            'expires_at' => $expiresAt,
        ]);

        return [$key, "pl_live_{$prefix}_{$secret}"];
    }

    /**
     * Resolve a plaintext key to an active ApiKey (any tenant), or null.
     */
    public static function findActive(string $plaintext): ?self
    {
        if (! preg_match('/^pl_live_([a-z0-9]{8})_([A-Za-z0-9]{20,128})$/', $plaintext, $m)) {
            return null;
        }

        /** @var self|null $key */
        $key = static::acrossTenants()->where('key_hash', hash('sha256', $m[2]))->first();

        if (! $key || ! hash_equals($key->prefix, $m[1]) || ! $key->isActive()) {
            return null;
        }

        return $key;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $ability): bool
    {
        $abilities = $this->abilities ?? [];

        return in_array('admin', $abilities, true) || in_array($ability, $abilities, true)
            || ($ability === 'searches:read' && in_array('searches:write', $abilities, true));
    }
}
