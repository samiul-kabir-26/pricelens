<?php

namespace App\Scraping\Data;

final class FetchResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $url,
        public string $finalUrl,
        public int $status,
        public string $body,
        public array $headers = [],
        public int $durationMs = 0,
        public bool $fromCache = false,
        public bool $rendered = false,
        public ?string $snapshotPath = null,
    ) {}
}
