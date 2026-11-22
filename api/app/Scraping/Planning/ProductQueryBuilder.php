<?php

namespace App\Scraping\Planning;

use App\Models\Search;
use App\Models\Tenant;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Providers\ProviderException;
use App\Scraping\Providers\ProviderManager;
use App\Scraping\Providers\ProviderQuery;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;

/**
 * Stage 1: normalize the search input into a ProductQuery (identifiers, brand, attributes, image hash,
 * reverse-image visual matches and a derived title when none was supplied).
 */
class ProductQueryBuilder
{
    public function __construct(
        private QueryNormalizer $normalizer,
        private IdentifierExtractor $identifiers,
        private ImageIngestor $images,
        private ProviderManager $providers,
    ) {}

    public function build(Tenant $tenant, Search $search): ProductQuery
    {
        $input = $search->input;
        $title = isset($input['title']) ? trim((string) $input['title']) : null;
        $brandDictionary = (array) $tenant->setting('brands', []);

        $gtin = GtinValidator::normalize($input['gtin'] ?? null) ?? $this->identifiers->gtin($title);
        $brand = $input['brand'] ?? $this->identifiers->brand($title, $brandDictionary);
        $mpn = isset($input['mpn']) ? trim((string) $input['mpn']) : null;
        $model = $mpn ?: $this->identifiers->model($title, $brand);

        $query = new ProductQuery(
            title: $title,
            cleanedTitle: $this->normalizer->clean($title),
            tokens: $this->normalizer->tokens($title),
            brand: $brand,
            model: $model,
            gtin: GtinValidator::isValid($gtin) ? $gtin : null,
            mpn: $mpn,
            imageUrl: $input['image_url'] ?? null,
            attributes: $this->identifiers->attributes($title),
            condition: $search->option('condition'),
            referenceUrl: $input['reference_url'] ?? null,
        );

        $this->ingestImage($tenant, $search, $query);

        // Derive a title from the image when none was given.
        if (! $query->title && ($query->labels || $query->visualMatches)) {
            $derived = $query->labels[0] ?? ($query->visualMatches[0]['title'] ?? null);
            if ($derived) {
                $query->title = $derived;
                $query->cleanedTitle = $this->normalizer->clean($derived);
                $query->tokens = $this->normalizer->tokens($derived);
                $query->brand ??= $this->identifiers->brand($derived.' '.implode(' ', array_column($query->visualMatches, 'title')), $brandDictionary);
                $query->model ??= $this->identifiers->model($derived, $query->brand);
                $query->attributes = $query->attributes ?: $this->identifiers->attributes($derived);
            }
        }

        return $query;
    }

    private function ingestImage(Tenant $tenant, Search $search, ProductQuery $query): void
    {
        $uploadPath = $search->input['image_upload_path'] ?? null;
        if (! $uploadPath && ! $query->imageUrl) {
            return;
        }

        $bytes = $uploadPath
            ? Storage::disk(config('filesystems.default'))->get($uploadPath)
            : $this->images->download((string) $query->imageUrl);

        if ($bytes) {
            $stored = $this->images->store($tenant, $search, $bytes);
            $query->imagePath = $stored['path'];
            $query->imagePhash = $stored['phash'];
        }

        if (! (bool) data_get($search->input, 'sources.reverse_image', false) || ! Feature::for($tenant)->active('image-search')) {
            return;
        }

        $publicUrl = $query->imageUrl ?? $this->temporaryUrl($query->imagePath);
        if (! $publicUrl) {
            return;
        }

        $provider = (string) $tenant->setting('providers.reverse_image', config('pricelens.providers.default_reverse_image'));
        try {
            $result = $this->providers->search($tenant, $provider, new ProviderQuery(
                mode: 'reverse_image', imageUrl: $publicUrl, country: $search->country,
                language: (string) $search->option('language', $tenant->default_language), limit: 20,
            ), (bool) $search->option('fresh', false));
            $query->labels = $result->labels;
            $query->visualMatches = array_map(fn ($r) => ['url' => $r->url, 'title' => $r->title], $result->results);
        } catch (ProviderException $e) {
            Log::info('reverse_image_unavailable', ['search' => $search->ulid, 'error' => $e->errorCode]);
        }
    }

    private function temporaryUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        try {
            return Storage::disk(config('filesystems.default'))->temporaryUrl($path, now()->addMinutes(30));
        } catch (\Throwable) {
            return null; // local disk: no public URL for the provider to fetch
        }
    }
}
