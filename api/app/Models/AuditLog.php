<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $ip
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property-read Tenant $tenant
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, ?Model $subject = null, array $metadata = []): self
    {
        $request = request();
        $apiKey = $request->attributes->get('api_key');
        $user = $request->user();

        return static::create([
            'actor_type' => $apiKey ? 'api_key' : ($user ? 'user' : 'system'),
            'actor_id' => $apiKey->ulid ?? $user?->ulid,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject ? (string) ($subject->getAttribute('ulid') ?? $subject->getKey()) : null,
            'ip' => $request->ip(),
            'metadata' => $metadata ?: null,
        ]);
    }
}
