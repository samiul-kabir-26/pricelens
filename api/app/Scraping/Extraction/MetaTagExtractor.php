<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use App\Support\Url;
use Symfony\Component\DomCrawler\Crawler;

/**
 * OpenGraph / Facebook product meta tags and <link rel=canonical>.
 */
class MetaTagExtractor implements Extractor
{
    public function name(): string
    {
        return 'meta';
    }

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct
    {
        $meta = [];
        $page->filter('meta[property], meta[name]')->each(function (Crawler $n) use (&$meta) {
            $key = strtolower($n->attr('property') ?? $n->attr('name') ?? '');
            if ($key !== '' && ! isset($meta[$key])) {
                $meta[$key] = trim((string) $n->attr('content'));
            }
        });

        $out = new ExtractedProduct;
        $src = $this->name();
        $isProduct = in_array(strtolower($meta['og:type'] ?? ''), ['product', 'og:product', 'product.item'], true)
            || isset($meta['product:price:amount']) || isset($meta['og:price:amount']);

        $out->fill('price', $meta['product:sale_price:amount'] ?? $meta['product:price:amount'] ?? $meta['og:price:amount'] ?? null, $src);
        $out->fill('currency', $meta['product:sale_price:currency'] ?? $meta['product:price:currency'] ?? $meta['og:price:currency'] ?? null, $src);
        if (isset($meta['product:sale_price:amount'])) {
            $out->fill('list_price', $meta['product:price:amount'] ?? null, $src);
        }
        $out->fill('availability', $meta['product:availability'] ?? $meta['og:availability'] ?? null, $src);
        $out->fill('condition', $meta['product:condition'] ?? null, $src);
        $out->fill('brand', $meta['product:brand'] ?? $meta['og:brand'] ?? null, $src);
        $out->fill('sku', $meta['product:retailer_item_id'] ?? null, $src);
        $out->fill('gtin', $meta['product:gtin'] ?? $meta['product:upc'] ?? $meta['product:ean'] ?? null, $src);
        $out->fill('mpn', $meta['product:mfr_part_no'] ?? null, $src);

        if ($isProduct || $out->hasPrice()) {
            $out->fill('title', $meta['og:title'] ?? $meta['twitter:title'] ?? null, $src);
        }
        $image = $meta['og:image'] ?? $meta['og:image:secure_url'] ?? $meta['twitter:image'] ?? null;
        $out->fill('image_url', $image ? Url::absolutize($image, $url) : null, $src);

        $canonical = $page->filter('link[rel="canonical"]');
        $canonicalHref = $canonical->count() ? $canonical->attr('href') : ($meta['og:url'] ?? null);
        $out->fill('canonical_url', $canonicalHref ? Url::absolutize($canonicalHref, $url) : null, $src);

        return $out;
    }
}
