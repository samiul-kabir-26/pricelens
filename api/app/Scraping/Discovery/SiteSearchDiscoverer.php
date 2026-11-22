<?php

namespace App\Scraping\Discovery;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Source;
use App\Models\Tenant;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Fetching\Fetcher;
use App\Scraping\Fetching\FetchException;

/**
 * `site` sources: fetch the adapter's search_url_template per query variant and harvest product links.
 * Domains without an adapter use the generic storefront search templates. Adapters with `sitemap_urls` and no
 * search template use the local sitemap index instead (SitemapDiscoverer).
 */
class SiteSearchDiscoverer implements Discoverer
{
    public function __construct(private Fetcher $fetcher, private LinkHarvester $harvester, private SitemapDiscoverer $sitemaps) {}

    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult
    {
        /** @var Source|null $source */
        $source = $searchSource->source;
        $domain = $searchSource->domain ?? $source?->domain;
        $limit = (int) $search->option('max_candidates_per_source', config('pricelens.scraper.max_candidates_per_source', 10));

        // Shops that disallow their search pages in robots.txt are discovered through their indexed sitemaps.
        if ($source && $source->cfg('sitemap_urls') && ! $source->cfg('search_url_template')) {
            return $this->sitemaps->discover($source, $query, $limit);
        }

        $templates = $source && $source->cfg('search_url_template')
            ? [(string) $source->cfg('search_url_template')]
            : array_map(fn (string $t) => str_replace('{domain}', (string) $domain, $t), config('pricelens.scraper.generic_search_templates', []));

        $variants = $searchSource->plan['queries'] ?? $query->variants();
        $result = new DiscoveryResult;
        $lastError = null;

        foreach ($templates as $template) {
            foreach ($variants as $variant) {
                if (count($result->candidates) >= $limit) {
                    break 2;
                }
                // GTIN-only queries rarely work on storefront search; skip them for generic sites.
                if (! $source && preg_match('/^\d{8,14}$/', $variant)) {
                    continue;
                }
                $url = str_replace('{query}', rawurlencode($variant), $template);
                $started = hrtime(true);
                try {
                    $page = $this->fetcher->fetch($tenant, $url, $source, ['fresh' => (bool) $search->option('fresh', false)]);
                    $result->requests[] = ['url' => $url, 'http_status' => $page->status, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'error_code' => null];
                } catch (FetchException $e) {
                    $result->requests[] = ['url' => $url, 'http_status' => $e->httpStatus, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6), 'error_code' => $e->errorCode];
                    $lastError = $e;
                    if (in_array($e->errorCode, ['skipped_policy', 'ssrf_blocked', 'blocked', 'circuit_open'], true)) {
                        break 2;
                    }
                    if ($e->retryable) {
                        throw $e;
                    }

                    continue;
                }

                $harvest = $this->harvester->harvest($page->body, $page->finalUrl, $source, $limit - count($result->candidates));
                array_push($result->candidates, ...$harvest['candidates']);
                array_push($result->directOffers, ...$harvest['offers']);
            }
        }

        if ($result->candidates === [] && $result->directOffers === [] && $lastError) {
            $result->error = $lastError->errorCode;
        }

        return $result;
    }
}
