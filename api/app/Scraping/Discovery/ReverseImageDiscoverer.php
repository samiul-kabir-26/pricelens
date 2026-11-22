<?php

namespace App\Scraping\Discovery;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Tenant;
use App\Scraping\Data\Candidate;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Fetching\DomainPolicyService;
use App\Support\Url;

/**
 * Visual-match page URLs (obtained during planning) become candidates.
 */
class ReverseImageDiscoverer implements Discoverer
{
    public function __construct(private DomainPolicyService $policies) {}

    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult
    {
        $limit = (int) $search->option('max_candidates_per_source', config('pricelens.scraper.max_candidates_per_source', 10));
        $result = new DiscoveryResult;

        foreach ($query->visualMatches as $match) {
            if (count($result->candidates) >= $limit) {
                break;
            }
            $host = Url::host($match['url'] ?? '');
            if ($host && $this->policies->isAllowed($tenant, $host)) {
                $result->candidates[] = new Candidate($match['url'], Url::canonicalize($match['url']), $match['title'] ?? null);
            }
        }

        if ($query->visualMatches === []) {
            $result->error = 'no_visual_matches';
        }

        return $result;
    }
}
