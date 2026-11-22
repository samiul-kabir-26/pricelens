<?php

namespace App\Jobs\Middleware;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\Context;
use Sentry\State\Scope;

/**
 * Re-binds CurrentTenant from the job's tenantId before handle() and clears it afterwards.
 * Jobs for suspended or deleted tenants are dropped.
 */
class TenantAware
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job->tenantId ?? null;
        $tenant = is_int($tenantId) ? Tenant::query()->with('plan')->find($tenantId) : null;
        if (! $tenant || ! $tenant->isActive()) {
            if (method_exists($job, 'delete')) {
                $job->delete();
            }

            return null;
        }

        $current = CurrentTenant::instance();
        $previous = $current->check() ? $current->get() : null;
        $current->set($tenant);
        Context::add('tenant_id', $tenant->id);
        if (function_exists('\\Sentry\\configureScope')) {
            \Sentry\configureScope(fn (Scope $scope) => $scope->setTag('tenant_id', (string) $tenant->id));
        }

        try {
            return $next($job);
        } finally {
            $previous ? $current->set($previous) : $current->forget();
            Context::forget('tenant_id');
        }
    }
}
