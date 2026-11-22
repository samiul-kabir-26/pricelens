<?php

namespace App\Jobs;

use App\Jobs\Middleware\TenantAware;
use App\Jobs\Middleware\TraceJob;
use App\Models\Search;
use App\Services\SearchFinalizer;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class FinalizeSearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(public int $tenantId, public int $searchId) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TraceJob, new TenantAware];
    }

    public function handle(SearchFinalizer $finalizer): void
    {
        $search = Search::query()->find($this->searchId);
        if ($search) {
            $finalizer->finalize(CurrentTenant::instance()->get(), $search);
        }
    }
}
