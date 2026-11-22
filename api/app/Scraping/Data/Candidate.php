<?php

namespace App\Scraping\Data;

final class Candidate
{
    /**
     * @param  array<string, mixed>  $hints  listing-card data (title, price) used for listing_mode / ranking
     */
    public function __construct(
        public string $url,
        public string $canonicalUrl,
        public ?string $title = null,
        public array $hints = [],
    ) {}
}
