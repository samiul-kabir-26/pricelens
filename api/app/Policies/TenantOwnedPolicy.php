<?php

namespace App\Policies;

use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Defense in depth against withoutGlobalScopes() misuse: the model must belong to the current tenant.
 * `?User` allows API-key (guest) requests; abilities are enforced by the `ability` middleware.
 */
class TenantOwnedPolicy
{
    public function view(?User $user, Model $model): bool
    {
        return $this->owns($model);
    }

    public function update(?User $user, Model $model): bool
    {
        return $this->owns($model);
    }

    public function delete(?User $user, Model $model): bool
    {
        return $this->owns($model);
    }

    protected function owns(Model $model): bool
    {
        $current = CurrentTenant::instance();

        return $current->check() && (int) $model->getAttribute('tenant_id') === $current->id();
    }
}
