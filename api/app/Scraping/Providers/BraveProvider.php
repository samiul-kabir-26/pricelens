<?php

namespace App\Scraping\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Brave Search API (web).
 */
class BraveProvider implements SearchProvider
{
    public function __construct(private string $apiKey, private string $baseUrl = 'https://api.search.brave.com/res/v1/web/search') {}

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(web: true, siteFilter: true);
    }

    public function search(ProviderQuery $query): ProviderResultCollection
    {
        if ($query->mode !== 'web') {
            throw new ProviderException('unsupported_mode', "Brave does not support '{$query->mode}'.");
        }

        $q = (string) $query->query;
        if ($query->domains !== []) {
            $q = implode(' OR ', array_map(fn ($d) => "site:{$d}", $query->domains)).' '.$q;
        }

        $response = Http::timeout(20)->acceptJson()
            ->withHeaders(['X-Subscription-Token' => $this->apiKey, 'Accept-Encoding' => 'gzip'])
            ->get($this->baseUrl, [
                'q' => $q,
                'country' => strtoupper($query->country),
                'search_lang' => strtolower($query->language),
                'count' => min(20, $query->limit),
            ]);

        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderException('provider_unavailable', 'Brave HTTP '.$response->status(), retryable: true);
        }
        if (! $response->successful()) {
            throw new ProviderException('provider_error', 'Brave HTTP '.$response->status());
        }

        return new ProviderResultCollection(array_map(fn ($r) => new ProviderResult(
            url: $r['url'], title: isset($r['title']) ? strip_tags($r['title']) : null, snippet: isset($r['description']) ? strip_tags($r['description']) : null,
        ), array_filter($response->json('web.results', []), fn ($r) => isset($r['url']))));
    }
}
