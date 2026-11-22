<?php

namespace App\Scraping\Data;

final class MatchResult
{
    /**
     * @param  array<string, float|string|array<int, string>>  $breakdown
     */
    public function __construct(
        public float $confidence,
        public string $band,
        public string $type,
        public array $breakdown,
    ) {}
}
