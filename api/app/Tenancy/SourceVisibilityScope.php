<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Sources are readable when owned by the current tenant or when they are platform (global) adapters.
 * Write access to global adapters is denied by SourcePolicy.
 *
 * @implements Scope<Model>
 */
class SourceVisibilityScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = CurrentTenant::instance()->id();

        $builder->where(function (Builder $q) use ($model, $tenantId) {
            $q->where($model->qualifyColumn('tenant_id'), $tenantId)
                ->orWhere(fn (Builder $g) => $g->whereNull($model->qualifyColumn('tenant_id'))->where($model->qualifyColumn('is_global'), true));
        });
    }
}
