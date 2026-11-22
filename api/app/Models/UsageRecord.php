<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $metric
 * @property int $quantity
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property Carbon|null $created_at
 * @property-read Tenant $tenant
 */
class UsageRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
