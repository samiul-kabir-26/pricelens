<?php

namespace App\Jobs\Middleware;

use App\Jobs\PipelineJob;
use App\Scraping\Fetching\CircuitBreaker;
use Closure;

/**
 * Short-circuits tasks for a domain whose circuit is open (too many consecutive failures).
 */
class DomainCircuitBreaker
{
    public function handle(PipelineJob $job, Closure $next): mixed
    {
        $host = $job->throttleHost();

        if ($host && app(CircuitBreaker::class)->isOpen($host)) {
            $job->failTask('failed', 'circuit_open', "Circuit open for {$host}");

            return null;
        }

        return $next($job);
    }
}
