<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query to the current tenant. Queries without a resolved tenant throw,
 * so a missing tenant can never silently return cross-tenant data. System code that must
 * span tenants (scheduler, retention) opts out explicitly with withoutGlobalScope().
 *
 * @implements Scope<Model>
 */
class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), CurrentTenant::instance()->id());
    }
}
