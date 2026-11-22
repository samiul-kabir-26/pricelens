<?php

namespace App\Scraping\Data;

/**
 * An offer produced without fetching the product page (shopping engine, merchant feed, listing_mode cards).
 */
final class DirectOffer
{
    public function __construct(
        public string $url,
        public ExtractedProduct $product,
        public ?string $siteName = null,
        public bool $verify = false,
    ) {}

    /** @return array{url: string, product: array<string, mixed>, site_name: string|null, verify: bool} */
    public function toArray(): array
    {
        return ['url' => $this->url, 'product' => $this->product->toArray(), 'site_name' => $this->siteName, 'verify' => $this->verify];
    }
}
