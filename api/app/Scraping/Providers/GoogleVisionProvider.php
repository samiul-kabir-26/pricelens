<?php

namespace App\Scraping\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Cloud Vision WEB_DETECTION: pagesWithMatchingImages, webEntities, bestGuessLabels.
 * Authenticates with an API key or a service account (OAuth bearer token from GoogleServiceAccountToken).
 */
class GoogleVisionProvider implements SearchProvider
{
    /** @param array<string, mixed>|null $serviceAccount */
    public function __construct(
        private ?string $apiKey,
        private string $baseUrl = 'https://vision.googleapis.com/v1/images:annotate',
        private ?array $serviceAccount = null,
    ) {}

    public function capabilities(): ProviderCapabilities
    {
        return new ProviderCapabilities(reverseImage: true);
    }

    public function search(ProviderQuery $query): ProviderResultCollection
    {
        if ($query->mode !== 'reverse_image' || ! $query->imageUrl) {
            throw new ProviderException('unsupported_mode', 'Google Vision supports reverse_image with an image URL only.');
        }

        $request = Http::timeout(30)->acceptJson();
        $url = $this->baseUrl;
        if ($this->serviceAccount) {
            $request = $request->withToken(GoogleServiceAccountToken::get($this->serviceAccount));
        } else {
            $url .= '?key='.urlencode((string) $this->apiKey);
        }

        $response = $request->post($url, [
            'requests' => [[
                'image' => ['source' => ['imageUri' => $query->imageUrl]],
                'features' => [['type' => 'WEB_DETECTION', 'maxResults' => max(10, $query->limit)]],
            ]],
        ]);

        if ($response->status() === 429 || $response->serverError()) {
            throw new ProviderException('provider_unavailable', 'Vision HTTP '.$response->status(), retryable: true);
        }
        if (! $response->successful() || $response->json('responses.0.error')) {
            throw new ProviderException('provider_error', (string) ($response->json('responses.0.error.message') ?? 'Vision HTTP '.$response->status()));
        }

        $web = $response->json('responses.0.webDetection', []);
        $labels = array_merge(
            array_column($web['bestGuessLabels'] ?? [], 'label'),
            array_slice(array_filter(array_column($web['webEntities'] ?? [], 'description')), 0, 5),
        );

        return new ProviderResultCollection(
            array_map(fn ($p) => new ProviderResult(url: $p['url'], title: isset($p['pageTitle']) ? strip_tags($p['pageTitle']) : null),
                array_filter($web['pagesWithMatchingImages'] ?? [], fn ($p) => isset($p['url']))),
            array_values(array_unique($labels)),
        );
    }
}
