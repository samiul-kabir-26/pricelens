<?php

namespace App\Scraping\Data;

final class DiscoveryResult
{
    /**
     * @param  array<int, Candidate>  $candidates
     * @param  array<int, DirectOffer>  $directOffers
     * @param  array<int, array{url: ?string, http_status: ?int, duration_ms: ?int, error_code: ?string}>  $requests
     */
    public function __construct(
        public array $candidates = [],
        public array $directOffers = [],
        public array $requests = [],
        public ?string $error = null,
    ) {}
}
