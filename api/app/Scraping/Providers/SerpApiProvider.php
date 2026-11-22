<?php

namespace App\Scraping\Providers;

use Illuminate\Support\Facades\Http;

/**
 * SerpApi: web (engine=google), shopping (engine=google_shopping), reverse image (engine=google_lens).
 */
class SerpApiProvider implements SearchProvider
{
    public function __construct(private string $apiKey, private string $baseUrl = 'https://serpapi.com/search.json') {}

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(web: true, shopping: true, reverseImage: true, siteFilter: true);
    }

    public function search(ProviderQuery $query): ProviderResultCollection
    {
        $params = ['api_key' => $this->apiKey, 'gl' => strtolower($query->country), 'hl' => strtolower($query->language)];

        $params += match ($query->mode) {
            'web' => ['engine' => 'google', 'q' => $this->withSiteFilter($query), 'num' => $query->limit],
            'shopping' => ['engine' => 'google_shopping', 'q' => $query->query, 'num' => $query->limit],
            'reverse_image' => ['engine' => 'google_lens', 'url' => $query->imageUrl],
            default => throw new ProviderException('unsupported_mode', "SerpApi does not support '{$query->mode}'."),
        };

        $response = Http::timeout(30)->acceptJson()->get($this->baseUrl, $params);
        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderException('provider_unavailable', 'SerpApi HTTP '.$response->status(), retryable: true);
        }
        if (! $response->successful() || $response->json('error')) {
            throw new ProviderException('provider_error', (string) ($response->json('error') ?? 'SerpApi HTTP '.$response->status()));
        }

        return match ($query->mode) {
            'web' => new ProviderResultCollection(array_map(fn ($r) => new ProviderResult(
                url: $r['link'], title: $r['title'] ?? null, snippet: $r['snippet'] ?? null,
            ), array_filter($response->json('organic_results', []), fn ($r) => isset($r['link'])))),

            'shopping' => new ProviderResultCollection(array_map(fn ($r) => new ProviderResult(
                url: $r['link'] ?? $r['product_link'],
                title: $r['title'] ?? null,
                price: $r['price'] ?? ($r['extracted_price'] ?? null),
                merchant: $r['source'] ?? null,
                imageUrl: $r['thumbnail'] ?? null,
                condition: $r['second_hand_condition'] ?? null,
                shipping: $r['delivery'] ?? null,
            ), array_filter($response->json('shopping_results', []), fn ($r) => isset($r['link']) || isset($r['product_link'])))),

            'reverse_image' => new ProviderResultCollection(
                array_map(fn ($r) => new ProviderResult(
                    url: $r['link'], title: $r['title'] ?? null, merchant: $r['source'] ?? null,
                    price: $r['price']['value'] ?? null, currency: $r['price']['currency'] ?? null, imageUrl: $r['thumbnail'] ?? null,
                ), array_filter($response->json('visual_matches', []), fn ($r) => isset($r['link']))),
                array_values(array_filter(array_map(fn ($k) => $k['title'] ?? null, $response->json('knowledge_graph', []) ?? []))),
            ),
        };
    }

    private function withSiteFilter(ProviderQuery $query): string
    {
        if ($query->domains === []) {
            return (string) $query->query;
        }
        $sites = implode(' OR ', array_map(fn ($d) => "site:{$d}", $query->domains));

        return count($query->domains) > 1 ? "({$sites}) {$query->query}" : "{$sites} {$query->query}";
    }
}
