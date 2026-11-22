<?php

namespace App\Billing;

use App\Exceptions\ProblemException;
use App\Models\Monitor;
use App\Models\Search;
use App\Models\Tenant;

class QuotaGuard
{
    public function __construct(private UsageMeter $usage) {}

    public function assertCanCreateSearch(Tenant $tenant): void
    {
        $monthly = $tenant->limit('searches_per_month');
        if ($monthly !== null && $this->usage->usedThisPeriod($tenant, 'searches') >= $monthly) {
            throw ProblemException::quotaExceeded("Plan '{$tenant->plan->code}' allows {$monthly} searches per month.", (int) now()->diffInSeconds(now()->endOfMonth()));
        }

        $concurrent = $tenant->limit('concurrent_searches');
        if ($concurrent !== null && $this->activeSearches($tenant) >= $concurrent) {
            throw new ProblemException('concurrency_limit', 'Too many concurrent searches', 429,
                "Plan '{$tenant->plan->code}' allows {$concurrent} concurrent searches.", ['Retry-After' => '10']);
        }
    }

    public function activeSearches(Tenant $tenant): int
    {
        return Search::acrossTenants()->where('tenant_id', $tenant->id)
            ->whereIn('status', [Search::STATUS_QUEUED, Search::STATUS_RUNNING])->count();
    }

    public function assertCanActivateMonitor(Tenant $tenant, ?Monitor $except = null): void
    {
        if (! $tenant->hasFeature('monitors')) {
            throw ProblemException::featureUnavailable('monitors');
        }

        $limit = $tenant->limit('active_monitors');
        if ($limit === null) {
            return;
        }

        $active = Monitor::acrossTenants()->where('tenant_id', $tenant->id)->where('status', 'active')
            ->when($except, fn ($q, Monitor $m) => $q->whereKeyNot($m->id))->count();

        if ($active >= $limit) {
            throw ProblemException::quotaExceeded("Plan '{$tenant->plan->code}' allows {$limit} active monitors.");
        }
    }

    public function canRender(Tenant $tenant): bool
    {
        $limit = $tenant->limit('render_pages_per_month');

        return $limit === null || $this->usage->usedThisPeriod($tenant, 'render_pages') < $limit;
    }

    public function canCallProvider(Tenant $tenant): bool
    {
        $limit = $tenant->limit('provider_calls_per_month');

        return $limit === null || $this->usage->usedThisPeriod($tenant, 'provider_calls') < $limit;
    }
}
