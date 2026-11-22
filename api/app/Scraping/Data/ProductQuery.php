<?php

namespace App\Scraping\Data;

/**
 * Normalized search input. Persisted on searches.product_query.
 */
final class ProductQuery
{
    /**
     * @param  array<int, string>  $tokens
     * @param  array<string, string|int>  $attributes  storage, size, color, pack
     * @param  array<int, array{url: string, title: ?string}>  $visualMatches
     * @param  array<int, string>  $labels
     */
    public function __construct(
        public ?string $title = null,
        public ?string $cleanedTitle = null,
        public array $tokens = [],
        public ?string $brand = null,
        public ?string $model = null,
        public ?string $gtin = null,
        public ?string $mpn = null,
        public ?string $imageUrl = null,
        public ?string $imagePath = null,
        public ?string $imagePhash = null,
        public array $attributes = [],
        public ?string $condition = null,
        public array $visualMatches = [],
        public array $labels = [],
        public ?string $referenceUrl = null,
    ) {}

    /**
     * Query variants, most precise first: GTIN, brand + model, cleaned title.
     *
     * @return array<int, string>
     */
    public function variants(int $max = 3): array
    {
        $variants = [];
        if ($this->gtin) {
            $variants[] = $this->gtin;
        }
        $model = $this->model ?? $this->mpn;
        if ($model) {
            $variants[] = trim(($this->brand ? $this->brand.' ' : '').$model);
        }
        if ($this->cleanedTitle) {
            $variants[] = $this->cleanedTitle;
        }

        return array_slice(array_values(array_unique(array_filter($variants))), 0, max(1, $max));
    }

    /** The best single human-readable query (for sites whose search can't handle GTINs well). */
    public function bestTextQuery(): ?string
    {
        $model = $this->model ?? $this->mpn;
        if ($model && $this->brand) {
            return "{$this->brand} {$model}";
        }

        return $this->cleanedTitle ?? $model ?? $this->gtin;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'cleaned_title' => $this->cleanedTitle,
            'tokens' => $this->tokens,
            'brand' => $this->brand,
            'model' => $this->model,
            'gtin' => $this->gtin,
            'mpn' => $this->mpn,
            'image_url' => $this->imageUrl,
            'image_path' => $this->imagePath,
            'image_phash' => $this->imagePhash,
            'attributes' => $this->attributes,
            'condition' => $this->condition,
            'visual_matches' => $this->visualMatches,
            'labels' => $this->labels,
            'reference_url' => $this->referenceUrl,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            cleanedTitle: $data['cleaned_title'] ?? null,
            tokens: $data['tokens'] ?? [],
            brand: $data['brand'] ?? null,
            model: $data['model'] ?? null,
            gtin: $data['gtin'] ?? null,
            mpn: $data['mpn'] ?? null,
            imageUrl: $data['image_url'] ?? null,
            imagePath: $data['image_path'] ?? null,
            imagePhash: $data['image_phash'] ?? null,
            attributes: $data['attributes'] ?? [],
            condition: $data['condition'] ?? null,
            visualMatches: $data['visual_matches'] ?? [],
            labels: $data['labels'] ?? [],
            referenceUrl: $data['reference_url'] ?? null,
        );
    }
}
