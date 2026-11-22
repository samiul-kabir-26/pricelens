<?php

namespace App\Scraping\Discovery;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Tenant;
use App\Scraping\Data\Candidate;
use App\Scraping\Data\DirectOffer;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Fetching\DomainPolicyService;
use App\Scraping\Providers\ProviderException;
use App\Scraping\Providers\ProviderManager;
use App\Scraping\Providers\ProviderQuery;
use App\Support\Url;

/**
 * search_engine (web) and shopping_engine (shopping) sources. Web results become candidates; shopping results
 * become offers immediately and, with `verify: true`, candidates too (the fetched page overwrites the offer).
 */
class EngineDiscoverer implements Discoverer
{
    public function __construct(private ProviderManager $providers, private DomainPolicyService $policies) {}

    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult
    {
        $plan = $searchSource->plan ?? [];
        $provider = (string) ($plan['provider'] ?? config('pricelens.providers.default_web'));
        $mode = (string) ($plan['mode'] ?? 'web');
        $domains = ($plan['scope'] ?? 'open') === 'open' ? [] : (array) ($plan['domains'] ?? []);
        $limit = (int) $search->option('max_candidates_per_source', config('pricelens.scraper.max_candidates_per_source', 10));
        $variants = array_slice($plan['queries'] ?? $query->variants(), 0, 2);
        $result = new DiscoveryResult;
        $seen = [];

        foreach ($variants as $variant) {
            if (count($result->candidates) + count($result->directOffers) >= $limit) {
                break;
            }
            $started = hrtime(true);
            try {
                $collection = $this->providers->search($tenant, $provider, new ProviderQuery(
                    mode: $mode, query: $variant, country: $search->country,
                    language: (string) $search->option('language', $tenant->default_language), domains: $domains, limit: $limit,
                ), (bool) $search->option('fresh', false));
                $result->requests[] = ['url' => "{$provider}:{$mode}:{$variant}", 'http_status' => 200, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'error_code' => null];
            } catch (ProviderException $e) {
                $result->requests[] = ['url' => "{$provider}:{$mode}:{$variant}", 'http_status' => null, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'error_code' => $e->errorCode];
                if ($e->retryable) {
                    throw $e;
                }
                $result->error = $e->errorCode;
                break;
            }

            $before = count($result->candidates) + count($result->directOffers);
            foreach ($collection->results as $r) {
                $host = Url::host($r->url);
                $canonical = Url::canonicalize($r->url);
                if (! $host || isset($seen[$canonical]) || ! $this->policies->isAllowed($tenant, $host)) {
                    continue;
                }
                if ($domains !== [] && ! collect($domains)->contains(fn ($d) => Url::matchesDomain($host, $d))) {
                    continue;
                }
                $seen[$canonical] = true;

                if ($mode === 'shopping') {
                    $result->directOffers[] = new DirectOffer($r->url, ExtractedProduct::make(array_filter([
                        'title' => $r->title,
                        'price' => $r->price,
                        'currency' => $r->currency,
                        'seller_name' => $r->merchant,
                        'image_url' => $r->imageUrl,
                        'condition' => $r->condition,
                        'shipping_price' => $r->shipping && preg_match('/free/i', $r->shipping) ? '0' : null,
                    ], fn ($v) => $v !== null), 'provider'), $r->merchant, verify: (bool) ($plan['verify'] ?? false));
                    if ($plan['verify'] ?? false) {
                        $result->candidates[] = new Candidate($r->url, $canonical, $r->title);
                    }
                } else {
                    $result->candidates[] = new Candidate($r->url, $canonical, $r->title, ['snippet' => $r->snippet]);
                }
            }

            // Provider calls are metered: stop at the first (most precise) variant that produced results.
            if (count($result->candidates) + count($result->directOffers) > $before) {
                break;
            }
        }

        return $result;
    }
}
