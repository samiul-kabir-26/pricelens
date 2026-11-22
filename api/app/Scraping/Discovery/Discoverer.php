<?php

namespace App\Scraping\Discovery;

use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Tenant;
use App\Scraping\Data\DiscoveryResult;
use App\Scraping\Data\ProductQuery;

interface Discoverer
{
    public function discover(Tenant $tenant, Search $search, SearchSource $searchSource, ProductQuery $query): DiscoveryResult;
}
