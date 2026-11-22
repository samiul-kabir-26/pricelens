<?php

namespace App\Jobs\Middleware;

use App\Jobs\PipelineJob;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Plan-based cap on a tenant's in-flight pipeline tasks so one tenant can't starve the others.
 * Over the cap, the job is released back to the queue with a delay.
 */
class TenantFairness
{
    public function handle(PipelineJob $job, Closure $next): mixed
    {
        $tenant = CurrentTenant::instance()->get();
        $cap = $tenant->limit('concurrent_tasks');
        if ($cap === null) {
            return $next($job);
        }

        $key = "{$tenant->cachePrefix()}:inflight";
        Cache::add($key, 0, 3600);
        $inflight = (int) Cache::increment($key);

        if ($inflight > $cap) {
            Cache::decrement($key);
            $job->release(random_int(2, 6));

            return null;
        }

        try {
            return $next($job);
        } finally {
            Cache::decrement($key);
        }
    }
}
