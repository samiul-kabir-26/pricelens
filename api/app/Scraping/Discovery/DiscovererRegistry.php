<?php

namespace App\Scraping\Discovery;

use InvalidArgumentException;

class DiscovererRegistry
{
    public function for(string $strategy): Discoverer
    {
        return match ($strategy) {
            'site_adapter', 'generic_site' => app(SiteSearchDiscoverer::class),
            'engine' => app(EngineDiscoverer::class),
            'reverse_image' => app(ReverseImageDiscoverer::class),
            'feed' => app(FeedDiscoverer::class),
            'reference_url' => app(ReferenceUrlDiscoverer::class),
            default => throw new InvalidArgumentException("Unknown discovery strategy '{$strategy}'."),
        };
    }
}
