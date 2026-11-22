<?php

namespace App\Scraping\Providers;

final class ProviderCapabilities
{
    public function __construct(
        public bool $web = false,
        public bool $shopping = false,
        public bool $reverseImage = false,
        public bool $siteFilter = false,
    ) {}

    public function supports(string $mode): bool
    {
        return match ($mode) {
            'web' => $this->web,
            'shopping' => $this->shopping,
            'reverse_image' => $this->reverseImage,
            default => false,
        };
    }
}
