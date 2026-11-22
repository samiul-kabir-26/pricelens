<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Framework payloads (`__NEXT_DATA__`, `window.__INITIAL_STATE__`) via JSON paths from the adapter's
 * `embedded_state` block: { script_selector, price_path, currency_path, title_path, ... }.
 */
class EmbeddedStateExtractor implements Extractor
{
    private const FIELD_PATHS = [
        'title' => 'title_path', 'price' => 'price_path', 'sale_price' => 'sale_price_path', 'list_price' => 'list_price_path',
        'currency' => 'currency_path', 'availability' => 'availability_path', 'gtin' => 'gtin_path', 'mpn' => 'mpn_path',
        'sku' => 'sku_path', 'brand' => 'brand_path', 'image_url' => 'image_path', 'seller_name' => 'seller_path',
    ];

    public function name(): string
    {
        return 'embedded_state';
    }

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct
    {
        $out = new ExtractedProduct;
        $config = $source?->cfg('embedded_state');
        if (! is_array($config) || empty($config['script_selector'])) {
            return $out;
        }

        try {
            $scripts = $page->filter((string) $config['script_selector']);
        } catch (\Throwable) {
            return $out;
        }
        if ($scripts->count() === 0) {
            return $out;
        }

        $data = JsonPath::decodeLenient($scripts->first()->text('', false));
        if ($data === null) {
            return $out;
        }

        foreach (self::FIELD_PATHS as $field => $key) {
            if (! empty($config[$key])) {
                $value = JsonPath::get($data, (string) $config[$key]);
                $out->fill($field, is_scalar($value) ? $value : null, $this->name());
            }
        }

        return $out;
    }
}
