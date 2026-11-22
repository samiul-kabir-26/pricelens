<?php

namespace App\Scraping\Providers;

final class ProviderResult
{
    public function __construct(
        public string $url,
        public ?string $title = null,
        public ?string $snippet = null,
        public string|int|float|null $price = null,
        public ?string $currency = null,
        public ?string $merchant = null,
        public ?string $imageUrl = null,
        public ?string $condition = null,
        public ?string $shipping = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        return new self(...array_intersect_key($a, array_flip(['url', 'title', 'snippet', 'price', 'currency', 'merchant', 'imageUrl', 'condition', 'shipping'])));
    }
}
