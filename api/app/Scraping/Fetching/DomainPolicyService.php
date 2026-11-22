<?php

namespace App\Scraping\Fetching;

use App\Models\DomainPolicy;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\Url;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class DomainPolicyService
{
    /** Throws FetchException(skipped_policy) when the host may not be fetched for this tenant. */
    public function assertAllowed(Tenant $tenant, string $host): void
    {
        if (! $this->isAllowed($tenant, $host)) {
            throw new FetchException('skipped_policy', "Domain '{$host}' is excluded by policy.");
        }
    }

    public function isAllowed(Tenant $tenant, string $host): bool
    {
        foreach ((array) $tenant->setting('domains.blocklist', []) as $blocked) {
            if (Url::matchesDomain($host, (string) $blocked)) {
                return false;
            }
        }

        $allowlist = (array) $tenant->setting('domains.allowlist', []);
        if ($allowlist !== [] && ! collect($allowlist)->contains(fn ($d) => Url::matchesDomain($host, (string) $d))) {
            return false;
        }

        return ! $this->policiesFor($tenant->id, $host)->contains(fn (DomainPolicy $p) => $p->policy === 'block');
    }

    /**
     * Effective per-domain rate limit: source config > tenant/global domain policy > platform default.
     *
     * @return array{requests: int, per_seconds: int, concurrency: int}
     */
    public function rateLimitFor(int $tenantId, string $host, ?Source $source = null): array
    {
        $defaultRps = max(0.01, (float) config('pricelens.scraper.default_rps_per_domain', 0.5));
        $limit = [
            'requests' => $defaultRps >= 1 ? (int) round($defaultRps) : 1,
            'per_seconds' => $defaultRps >= 1 ? 1 : (int) round(1 / $defaultRps),
            'concurrency' => (int) config('pricelens.scraper.default_domain_concurrency', 2),
        ];

        $policy = $this->policiesFor($tenantId, $host)->first(fn (DomainPolicy $p) => $p->policy === 'throttle' && $p->rate_limit);
        if ($policy && $policy->rate_limit) {
            $limit = array_merge($limit, array_intersect_key($policy->rate_limit, $limit));
        }

        if ($source && is_array($source->cfg('rate_limit'))) {
            $limit = array_merge($limit, array_intersect_key($source->cfg('rate_limit'), $limit));
        }

        return ['requests' => intval($limit['requests']), 'per_seconds' => intval($limit['per_seconds']), 'concurrency' => intval($limit['concurrency'])];
    }

    /** @return Collection<int, DomainPolicy> tenant rows first */
    private function policiesFor(int $tenantId, string $host): Collection
    {
        // Cache plain arrays: Laravel 13 refuses to unserialize arbitrary classes from the cache.
        $rows = Cache::remember("domain_policies:{$tenantId}", 60, fn () => DomainPolicy::query()
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderByRaw('tenant_id is null')
            ->get()->map(fn (DomainPolicy $p) => $p->getAttributes())->all());
        $all = DomainPolicy::hydrate($rows);

        return $all->filter(fn (DomainPolicy $p) => Url::matchesDomain($host, $p->domain))->values();
    }
}
