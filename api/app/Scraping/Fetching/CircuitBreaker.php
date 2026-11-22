<?php

namespace App\Scraping\Fetching;

use Illuminate\Support\Facades\Cache;

/**
 * Per-domain circuit breaker: opens after N consecutive failures within the window and short-circuits
 * further tasks for the open period.
 */
class CircuitBreaker
{
    public function isOpen(string $host): bool
    {
        return Cache::has($this->openKey($host));
    }

    public function recordFailure(string $host): void
    {
        $key = $this->failuresKey($host);
        Cache::add($key, 0, (int) config('pricelens.scraper.circuit_window_seconds', 300));
        $failures = (int) Cache::increment($key);

        if ($failures >= (int) config('pricelens.scraper.circuit_failures', 10)) {
            Cache::put($this->openKey($host), true, (int) config('pricelens.scraper.circuit_open_seconds', 900));
            Cache::forget($key);
        }
    }

    public function recordSuccess(string $host): void
    {
        Cache::forget($this->failuresKey($host));
    }

    private function failuresKey(string $host): string
    {
        return "circuit:{$host}:failures";
    }

    private function openKey(string $host): string
    {
        return "circuit:{$host}:open";
    }
}
