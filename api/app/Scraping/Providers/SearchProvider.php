<?php

namespace App\Scraping\Providers;

interface SearchProvider
{
    public function search(ProviderQuery $query): ProviderResultCollection;

    public function capabilities(): ProviderCapabilities;
}
