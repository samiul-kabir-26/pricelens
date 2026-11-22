<?php

namespace App\Jobs\Middleware;

use App\Jobs\PipelineJob;
use App\Scraping\Fetching\DomainPolicyService;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/**
 * Per-domain politeness (shared across tenants): concurrency funnel + rate throttle on Redis
 * (default 1 request / 2 s), with a cache-based fallback when Redis isn't the cache store.
 * Throttled jobs are released back to the queue with jitter.
 */
class DomainThrottle
{
    public function handle(PipelineJob $job, Closure $next): mixed
    {
        $host = $job->throttleHost();
        if (! $host) {
            return $next($job);
        }

        $limit = app(DomainPolicyService::class)->rateLimitFor($job->tenantId, $host, $job->source());
        $jitter = fn (int $base = 1) => $base + random_int(0, 3);

        if (config('cache.default') === 'redis') {
            return Redis::funnel("domain:{$host}:concurrency")
                ->limit(max(1, $limit['concurrency']))
                ->releaseAfter(60)
                ->block(0)
                ->then(
                    fn () => Redis::throttle("domain:{$host}")
                        ->allow(max(1, $limit['requests']))
                        ->every(max(1, $limit['per_seconds']))
                        ->block(0)
                        ->then(fn () => $next($job), fn () => $job->release($jitter($limit['per_seconds']))),
                    fn () => $job->release($jitter(2)),
                );
        }

        $key = "domain:{$host}";
        if (RateLimiter::tooManyAttempts($key, max(1, $limit['requests']))) {
            $job->release($jitter(RateLimiter::availableIn($key)));

            return null;
        }
        RateLimiter::hit($key, max(1, $limit['per_seconds']));

        return $next($job);
    }
}
