<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Global (tenant_id NULL) or tenant-specific domain policy: allow | block | throttle.
 * Not tenant-scoped automatically because global rows must always apply.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $domain
 * @property string $policy
 * @property array<string, mixed>|null $rate_limit
 * @property string|null $reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainPolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_limit' => 'array'];
    }
}
