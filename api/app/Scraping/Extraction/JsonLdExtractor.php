<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Normalization\AvailabilityMapper;
use App\Support\Url;
use Symfony\Component\DomCrawler\Crawler;

/**
 * schema.org/Product JSON-LD with Offer / AggregateOffer, @graph, arrays, ProductGroup / hasVariant.
 */
class JsonLdExtractor implements Extractor
{
    private const PRODUCT_TYPES = ['product', 'productgroup', 'individualproduct', 'productmodel', 'vehicle', 'book'];

    public function name(): string
    {
        return 'json_ld';
    }

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct
    {
        $products = [];
        $page->filter('script[type="application/ld+json"]')->each(function (Crawler $node) use (&$products) {
            $data = JsonPath::decodeLenient($node->text('', false));
            if ($data !== null) {
                $this->collectProducts($data, $products);
            }
        });

        if ($products === []) {
            return new ExtractedProduct;
        }

        $product = $this->pickProduct($products, $url);

        return $this->map($product, $url);
    }

    /**
     * @param  array<int, array<mixed>>  $out  decoded JSON-LD product nodes
     */
    private function collectProducts(mixed $node, array &$out, int $depth = 0): void
    {
        if (! is_array($node) || $depth > 6) {
            return;
        }
        if (array_is_list($node)) {
            foreach ($node as $item) {
                $this->collectProducts($item, $out, $depth + 1);
            }

            return;
        }
        if ($this->isProduct($node)) {
            $out[] = $node;

            return;
        }
        foreach (['@graph', 'mainEntity', 'mainEntityOfPage', 'itemReviewed', 'about'] as $key) {
            if (isset($node[$key])) {
                $this->collectProducts($node[$key], $out, $depth + 1);
            }
        }
    }

    /** @param array<string, mixed> $node */
    private function isProduct(array $node): bool
    {
        $types = (array) ($node['@type'] ?? []);
        foreach ($types as $t) {
            $t = strtolower(preg_replace('#^https?://schema\.org/#i', '', (string) $t) ?? '');
            if (in_array($t, self::PRODUCT_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer a product with offers; for a ProductGroup choose the variant matching the page URL or the first
     * variant with offers.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<string, mixed>
     */
    private function pickProduct(array $products, string $url): array
    {
        $canonical = Url::canonicalize($url);
        $withOffers = array_values(array_filter($products, fn ($p) => ! empty($p['offers'])));
        $product = $withOffers[0] ?? $products[0];

        foreach ($products as $p) {
            if (! empty($p['hasVariant']) && is_array($p['hasVariant'])) {
                $variants = array_is_list($p['hasVariant']) ? $p['hasVariant'] : [$p['hasVariant']];
                $chosen = null;
                foreach ($variants as $v) {
                    if (! is_array($v)) {
                        continue;
                    }
                    $vUrl = $v['url'] ?? ($v['offers']['url'] ?? null);
                    if (is_string($vUrl) && Url::canonicalize($vUrl) === $canonical) {
                        $chosen = $v;
                        break;
                    }
                    if ($chosen === null && ! empty($v['offers'])) {
                        $chosen = $v;
                    }
                }
                if ($chosen) {
                    // Variant inherits group-level fields it doesn't define.
                    return $chosen + array_diff_key($p, ['hasVariant' => 1, '@type' => 1]);
                }
            }
        }

        return $product;
    }

    /** @param array<string, mixed> $p */
    private function map(array $p, string $url): ExtractedProduct
    {
        $out = new ExtractedProduct;
        $src = $this->name();

        $out->fill('title', $this->str($p['name'] ?? null), $src);
        $out->fill('brand', $this->nameOf($p['brand'] ?? ($p['manufacturer'] ?? null)), $src);
        foreach (['gtin13', 'gtin12', 'gtin14', 'gtin8', 'gtin', 'isbn'] as $k) {
            $out->fill('gtin', $this->str($p[$k] ?? null), $src);
        }
        $out->fill('mpn', $this->str($p['mpn'] ?? null), $src);
        $out->fill('sku', $this->str($p['sku'] ?? ($p['productID'] ?? null)), $src);
        $out->fill('model', $this->nameOf($p['model'] ?? null), $src);
        $out->fill('image_url', $this->image($p['image'] ?? null, $url), $src);
        $out->fill('condition', $this->str($p['itemCondition'] ?? null), $src);
        $out->fill('canonical_url', $this->str($p['url'] ?? null), $src);

        if (isset($p['aggregateRating']) && is_array($p['aggregateRating'])) {
            $out->fill('rating', $this->num($p['aggregateRating']['ratingValue'] ?? null), $src);
            $out->fill('review_count', $this->num($p['aggregateRating']['reviewCount'] ?? ($p['aggregateRating']['ratingCount'] ?? null)), $src);
        }

        $variant = array_filter([
            'color' => $this->str($p['color'] ?? null),
            'size' => $this->nameOf($p['size'] ?? null),
        ]);
        $out->fill('variant_attributes', $variant, $src);

        $offer = $this->bestOffer($p['offers'] ?? null);
        if ($offer) {
            $this->mapOffer($offer, $out);
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function bestOffer(mixed $offers): ?array
    {
        if (! is_array($offers) || $offers === []) {
            return null;
        }
        $list = array_is_list($offers) ? $offers : [$offers];

        $flat = [];
        foreach ($list as $o) {
            if (! is_array($o)) {
                continue;
            }
            $type = strtolower((string) (is_array($o['@type'] ?? null) ? ($o['@type'][0] ?? '') : ($o['@type'] ?? '')));
            if (str_contains($type, 'aggregateoffer') && isset($o['offers']) && is_array($o['offers'])) {
                $inner = array_is_list($o['offers']) ? $o['offers'] : [$o['offers']];
                foreach ($inner as $i) {
                    if (is_array($i)) {
                        $flat[] = $i + ['priceCurrency' => $o['priceCurrency'] ?? null];
                    }
                }
                if (! isset($o['price']) && isset($o['lowPrice'])) {
                    $o['price'] = $o['lowPrice'];
                }
            }
            $flat[] = $o;
        }

        $priced = array_values(array_filter($flat, fn ($o) => $this->offerPrice($o) !== null));
        if ($priced === []) {
            return $flat[0] ?? null;
        }

        usort($priced, function ($a, $b) {
            $aIn = AvailabilityMapper::map($a['availability'] ?? null) === 'in_stock' ? 0 : 1;
            $bIn = AvailabilityMapper::map($b['availability'] ?? null) === 'in_stock' ? 0 : 1;

            return $aIn <=> $bIn ?: ((float) $this->offerPrice($a) <=> (float) $this->offerPrice($b));
        });

        return $priced[0];
    }

    /** @param array<string, mixed> $o */
    private function offerPrice(array $o): mixed
    {
        $price = $o['price'] ?? $o['lowPrice'] ?? null;
        if ($price === null && isset($o['priceSpecification'])) {
            $specs = is_array($o['priceSpecification']) && array_is_list($o['priceSpecification']) ? $o['priceSpecification'] : [$o['priceSpecification']];
            foreach ($specs as $s) {
                if (is_array($s) && isset($s['price']) && ! $this->isListPriceSpec($s)) {
                    return $s['price'];
                }
            }
        }

        return is_scalar($price) && $price !== '' ? $price : null;
    }

    /**
     * @param  array<string, mixed>  $o
     */
    private function mapOffer(array $o, ExtractedProduct $out): void
    {
        $src = $this->name();
        $out->fill('price', $this->offerPrice($o), $src);

        $currency = $o['priceCurrency'] ?? null;
        $specs = isset($o['priceSpecification']) && is_array($o['priceSpecification'])
            ? (array_is_list($o['priceSpecification']) ? $o['priceSpecification'] : [$o['priceSpecification']]) : [];
        foreach ($specs as $s) {
            if (! is_array($s)) {
                continue;
            }
            $currency ??= $s['priceCurrency'] ?? null;
            if ($this->isListPriceSpec($s)) {
                $out->fill('list_price', $s['price'] ?? null, $src);
            }
        }
        $out->fill('currency', $this->str($currency), $src);
        $out->fill('availability', $this->str($o['availability'] ?? null), $src);
        $out->fill('condition', $this->str($o['itemCondition'] ?? null), $src);
        $out->fill('seller_name', $this->nameOf($o['seller'] ?? null), $src);
        $out->fill('sku', $this->str($o['sku'] ?? null), $src);
        $out->fill('gtin', $this->str($o['gtin13'] ?? ($o['gtin'] ?? null)), $src);
        $out->fill('canonical_url', $this->str($o['url'] ?? null), $src);

        $shipping = $o['shippingDetails'] ?? null;
        if (is_array($shipping)) {
            $shipping = array_is_list($shipping) ? ($shipping[0] ?? null) : $shipping;
            $rate = is_array($shipping) ? ($shipping['shippingRate'] ?? null) : null;
            if (is_array($rate)) {
                $out->fill('shipping_price', $rate['value'] ?? ($rate['price'] ?? null), $src);
                $out->fill('shipping_currency', $this->str($rate['currency'] ?? ($rate['priceCurrency'] ?? null)), $src);
            }
        }
    }

    /** @param array<string, mixed> $spec */
    private function isListPriceSpec(array $spec): bool
    {
        $type = strtolower(preg_replace('#^https?://schema\.org/#i', '', (string) ($spec['priceType'] ?? '')) ?? '');

        return in_array($type, ['listprice', 'strikethroughprice', 'msrp', 'srp'], true);
    }

    private function nameOf(mixed $v): ?string
    {
        if (is_array($v)) {
            if (array_is_list($v)) {
                $v = $v[0] ?? null;

                return $this->nameOf($v);
            }

            return $this->str($v['name'] ?? null);
        }

        return $this->str($v);
    }

    private function image(mixed $v, string $base): ?string
    {
        if (is_array($v)) {
            $v = array_is_list($v) ? ($v[0] ?? null) : ($v['url'] ?? ($v['contentUrl'] ?? null));

            return $this->image($v, $base);
        }

        return is_string($v) && $v !== '' ? Url::absolutize($v, $base) : null;
    }

    private function str(mixed $v): ?string
    {
        if (is_array($v)) {
            $v = array_is_list($v) ? ($v[0] ?? null) : null;
        }

        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }

    private function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
