<?php

namespace App\Scraping\Providers;

final class ProviderQuery
{
    /**
     * @param  array<int, string>  $domains  restrict results to these domains (site: filter)
     */
    public function __construct(
        public string $mode,             // web | shopping | reverse_image
        public ?string $query = null,
        public string $country = 'US',
        public string $language = 'en',
        public array $domains = [],
        public ?string $imageUrl = null,
        public int $limit = 10,
    ) {}

    public function cacheKey(string $provider): string
    {
        return sha1(json_encode([$provider, $this->mode, mb_strtolower(trim((string) $this->query)), strtoupper($this->country),
            strtolower($this->language), $this->domains, $this->imageUrl, $this->limit]) ?: '');
    }
}
