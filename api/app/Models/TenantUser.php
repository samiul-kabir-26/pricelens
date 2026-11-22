<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Tenant membership (`tenant_user` pivot) carrying the member's role.
 *
 * @property int $tenant_id
 * @property int $user_id
 * @property string $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TenantUser extends Pivot
{
    protected $table = 'tenant_user';
}
