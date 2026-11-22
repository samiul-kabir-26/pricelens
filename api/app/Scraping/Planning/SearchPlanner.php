<?php

namespace App\Scraping\Planning;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Source;
use App\Models\Tenant;
use App\Scraping\Data\ProductQuery;
use App\Support\Url;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Builds the search plan: one SearchSource row per (source, strategy, query variants).
 */
class SearchPlanner
{
    /** @return Collection<int, SearchSource> */
    public function plan(Tenant $tenant, Search $search, ProductQuery $query): Collection
    {
        $requested = (array) ($search->input['sources'] ?? []);
        $variants = $query->variants((int) $search->option('max_query_variants', config('pricelens.scraper.max_query_variants', 3)));
        $items = [];

        $sources = collect();
        if (! empty($requested['source_ids'])) {
            $sources = Source::query()->whereIn('ulid', (array) $requested['source_ids'])->get();
        }

        $explicit = ! empty($requested['source_ids']) || ! empty($requested['domains']) || ! empty($requested['search_engines']) || ! empty($requested['reverse_image']);
        if (! $explicit) {
            $sources = Source::defaultForTenant($tenant->id);
        }

        foreach ($sources as $source) {
            $items[] = $this->forSource($source, $variants, (array) ($requested['domains'] ?? []));
        }

        $coveredDomains = $sources->pluck('domain')->filter()->map(fn ($d) => Url::siteDomain('https://'.$d))->all();
        foreach ((array) ($requested['domains'] ?? []) as $domain) {
            $domain = strtolower(trim($domain));
            if (in_array(Url::siteDomain('https://'.$domain), $coveredDomains, true)) {
                continue;
            }
            $adapter = $this->adapterForDomain($tenant, $domain);
            $items[] = $adapter
                ? $this->forSource($adapter, $variants, [])
                : [
                    'key' => "domain:{$domain}", 'type' => 'site', 'label' => $domain, 'domain' => $domain, 'source_id' => null,
                    'plan' => ['strategy' => 'generic_site', 'queries' => $variants],
                ];
            $coveredDomains[] = Url::siteDomain('https://'.$domain);
        }

        foreach ((array) ($requested['search_engines'] ?? []) as $engine) {
            $provider = (string) ($engine['provider'] ?? config('pricelens.providers.default_web'));
            $mode = (string) ($engine['mode'] ?? 'web');
            $scope = (string) ($engine['scope'] ?? (! empty($requested['domains']) ? 'allowed_domains' : 'open'));
            $items[] = [
                'key' => "engine:{$provider}:{$mode}", 'type' => $mode === 'shopping' ? 'shopping_engine' : 'search_engine',
                'label' => ucfirst($provider).' '.$mode, 'domain' => null, 'source_id' => null,
                'plan' => [
                    'strategy' => 'engine', 'provider' => $provider, 'mode' => $mode, 'scope' => $scope,
                    'domains' => array_values(array_unique(array_merge((array) ($requested['domains'] ?? []), (array) $tenant->setting('domains.allowlist', [])))),
                    'verify' => (bool) ($engine['verify'] ?? false), 'queries' => $variants,
                ],
            ];
        }

        if (! empty($requested['reverse_image']) && ($query->imagePath || $query->imageUrl) && Feature::for($tenant)->active('image-search')) {
            $items[] = [
                'key' => 'reverse_image', 'type' => 'reverse_image', 'label' => 'Reverse image', 'domain' => null, 'source_id' => null,
                'plan' => ['strategy' => 'reverse_image'],
            ];
        }

        if ($query->referenceUrl) {
            $refDomain = Url::siteDomain($query->referenceUrl);
            // Use the domain's adapter (selectors, render mode, rate limit) for the reference page when one exists.
            $adapter = $refDomain ? $this->adapterForDomain($tenant, $refDomain) : null;
            $items[] = [
                'key' => 'reference_url', 'type' => 'site', 'label' => 'Reference URL', 'domain' => $refDomain,
                'source_id' => $adapter?->id, 'plan' => ['strategy' => 'reference_url'],
            ];
        }

        $max = $tenant->limit('max_sources_per_search');
        if ($max !== null) {
            $items = array_slice($items, 0, $max);
        }

        return collect($items)->unique('key')->map(fn (array $item) => SearchSource::create($item + ['search_id' => $search->id, 'status' => 'pending']))->values();
    }

    /**
     * @param  array<int, string>  $variants
     * @param  array<int, string>  $requestDomains
     * @return array<string, mixed>
     */
    private function forSource(Source $source, array $variants, array $requestDomains): array
    {
        $base = ['key' => "source:{$source->ulid}", 'type' => $source->type, 'label' => $source->name, 'domain' => $source->domain, 'source_id' => $source->id];

        return $base + ['plan' => match ($source->type) {
            'site' => ['strategy' => 'site_adapter', 'queries' => $variants],
            'search_engine', 'shopping_engine' => [
                'strategy' => 'engine',
                'provider' => (string) $source->cfg('provider', config('pricelens.providers.'.($source->type === 'shopping_engine' ? 'default_shopping' : 'default_web'))),
                'mode' => $source->type === 'shopping_engine' ? 'shopping' : 'web',
                'scope' => (string) $source->cfg('scope', 'open'),
                'domains' => array_values(array_unique(array_merge((array) $source->cfg('allowed_domains', []), $requestDomains))),
                'verify' => (bool) $source->cfg('verify', false),
                'queries' => $variants,
            ],
            'reverse_image' => ['strategy' => 'reverse_image'],
            'feed' => ['strategy' => 'feed'],
            default => ['strategy' => 'site_adapter', 'queries' => $variants],
        }];
    }

    private function adapterForDomain(Tenant $tenant, string $domain): ?Source
    {
        $candidates = Source::query()->where('type', 'site')->where('enabled', true)
            ->whereIn('domain', [$domain, 'www.'.$domain, preg_replace('/^www\./', '', $domain)])->get();

        return $candidates->first(fn (Source $s) => $s->tenant_id === $tenant->id)
            ?? $candidates->first(fn (Source $s) => $s->is_global && $s->isEnabledForTenant($tenant->id))
            ?? $candidates->first(fn (Source $s) => $s->is_global);
    }
}
