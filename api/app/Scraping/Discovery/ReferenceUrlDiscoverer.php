<?php

namespace App\Scraping\Discovery;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Tenant;
use App\Scraping\Data\Candidate;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ProductQuery;
use App\Support\Url;

/**
 * The search's reference_url is itself a candidate product page.
 */
class ReferenceUrlDiscoverer implements Discoverer
{
    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult
    {
        $url = $query->referenceUrl;

        return $url ? new DiscoveryResult([new Candidate($url, Url::canonicalize($url))]) : new DiscoveryResult(error: 'no_reference_url');
    }
}
