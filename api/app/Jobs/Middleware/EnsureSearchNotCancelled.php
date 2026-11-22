<?php

namespace App\Jobs\Middleware;

use App\Jobs\PipelineJob;
use App\Models\Search;
use Closure;

class EnsureSearchNotCancelled
{
    public function handle(PipelineJob $job, Closure $next): mixed
    {
        $cancelled = $job->batch()?->cancelled()
            || Search::acrossTenants()->whereKey($job->searchId)->value('status') === Search::STATUS_CANCELLED;

        if ($cancelled) {
            $job->markCancelled();

            return null;
        }

        return $next($job);
    }
}
