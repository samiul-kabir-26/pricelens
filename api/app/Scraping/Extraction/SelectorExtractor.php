<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use App\Support\Url;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Per-source CSS/XPath selectors from the adapter's `product` block, with optional `price_regex`.
 */
class SelectorExtractor implements Extractor
{
    /** adapter key → ExtractedProduct field */
    public const FIELD_MAP = [
        'title' => 'title', 'price' => 'price', 'sale_price' => 'sale_price', 'list_price' => 'list_price',
        'currency' => 'currency', 'availability' => 'availability', 'condition' => 'condition', 'gtin' => 'gtin',
        'mpn' => 'mpn', 'sku' => 'sku', 'model' => 'model', 'brand' => 'brand', 'image' => 'image_url',
        'seller' => 'seller_name', 'seller_name' => 'seller_name', 'shipping' => 'shipping_price',
        'shipping_price' => 'shipping_price', 'rating' => 'rating', 'review_count' => 'review_count',
    ];

    private const PRICE_FIELDS = ['price', 'sale_price', 'list_price', 'shipping_price'];

    /** @var array<string, array<int, string>> raw hits from the last extraction (for POST /sources/{id}/test) */
    public array $lastHits = [];

    public function __construct(private SelectorEngine $engine) {}

    public function name(): string
    {
        return 'selector';
    }

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct
    {
        $this->lastHits = [];
        $out = new ExtractedProduct;
        $config = $source?->cfg('product');
        if (! is_array($config)) {
            return $out;
        }

        $priceRegex = isset($config['price_regex']) && is_string($config['price_regex']) ? $config['price_regex'] : null;

        foreach (self::FIELD_MAP as $key => $field) {
            if (! isset($config[$key]) || (! is_string($config[$key]) && ! is_array($config[$key]))) {
                continue;
            }
            $values = $this->engine->query($page, $config[$key], 5);
            $this->lastHits[$key] = $values;
            $value = $values[0] ?? null;

            if ($value !== null && $priceRegex && in_array($field, self::PRICE_FIELDS, true)) {
                $value = $this->engine->applyRegex([$value], $priceRegex)[0] ?? $value;
            }
            if ($field === 'image_url' && $value) {
                $value = Url::absolutize($value, $url);
            }

            $out->fill($field, $value, $this->name());
        }

        // Static currency fallback from source definition.
        if (! $out->has('currency') && $source?->currency) {
            $out->fill('currency', $source->currency, $this->name());
        }

        return $out;
    }
}
