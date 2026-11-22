<?php

namespace App\Scraping\Data;

/**
 * Partial product data with a per-field source marker (json_ld, microdata, meta, embedded_state, selector,
 * llm, provider, feed). Earlier extractors win; later extractors only fill missing fields.
 */
final class ExtractedProduct
{
    public const FIELDS = [
        'title', 'brand', 'gtin', 'mpn', 'sku', 'model', 'image_url', 'price', 'sale_price', 'list_price',
        'currency', 'availability', 'condition', 'seller_name', 'shipping_price', 'shipping_currency', 'rating',
        'review_count', 'variant_attributes', 'canonical_url',
    ];

    /** @var array<string, mixed> */
    private array $fields = [];

    /** @var array<string, string> */
    private array $sources = [];

    /** @var array<string, float> */
    private array $confidence = [];

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function make(array $fields, string $source): self
    {
        $p = new self;
        foreach ($fields as $k => $v) {
            $p->fill($k, $v, $source);
        }

        return $p;
    }

    /** Set a field only if it is currently empty and the value is non-empty. */
    public function fill(string $field, mixed $value, string $source, ?float $confidence = null): self
    {
        if (! in_array($field, self::FIELDS, true) || $this->has($field) || self::isEmpty($value)) {
            return $this;
        }

        $this->fields[$field] = is_string($value) ? trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5)) : $value;
        $this->sources[$field] = $source;
        if ($confidence !== null) {
            $this->confidence[$field] = $confidence;
        }

        return $this;
    }

    public function merge(self $other): self
    {
        foreach ($other->fields as $field => $value) {
            $this->fill($field, $value, $other->sources[$field], $other->confidence[$field] ?? null);
        }

        return $this;
    }

    public function get(string $field, mixed $default = null): mixed
    {
        return $this->fields[$field] ?? $default;
    }

    public function has(string $field): bool
    {
        return array_key_exists($field, $this->fields);
    }

    public function hasPrice(): bool
    {
        return $this->has('price') || $this->has('sale_price');
    }

    public function isEmpty_(): bool
    {
        return $this->fields === [];
    }

    /** Extraction method reported on the offer: the source of the price, else the title. */
    public function method(): string
    {
        return $this->sources['sale_price'] ?? $this->sources['price'] ?? $this->sources['title'] ?? 'none';
    }

    /** @return array<string, string> */
    public function fieldSources(): array
    {
        return $this->sources;
    }

    /** @return array<string, mixed> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array{fields: array<string, mixed>, sources: array<string, string>, confidence: array<string, float>} */
    public function toArray(): array
    {
        return ['fields' => $this->fields, 'sources' => $this->sources, 'confidence' => $this->confidence];
    }

    /** @param array{fields?: array<string, mixed>, sources?: array<string, string>, confidence?: array<string, float>} $data */
    public static function fromArray(array $data): self
    {
        $p = new self;
        $p->fields = $data['fields'] ?? [];
        $p->sources = $data['sources'] ?? [];
        $p->confidence = $data['confidence'] ?? [];

        return $p;
    }

    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
    }
}
