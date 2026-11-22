<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Scraping\Data\ExtractedProduct;
use App\Support\Url;
use DOMElement;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Microdata (itemtype=schema.org/Product, itemprop=...) and RDFa (typeof=Product, property=...).
 */
class MicrodataExtractor implements Extractor
{
    public function name(): string
    {
        return 'microdata';
    }

    public function extract(Crawler $page, string $url, ?Source $source): ExtractedProduct
    {
        $out = new ExtractedProduct;

        $scope = $page->filterXPath('//*[@itemscope and contains(translate(@itemtype, "PRODUCT", "product"), "schema.org/product")]');
        if ($scope->count() > 0) {
            $this->fromScope($scope->first(), 'itemprop', $url, $out);
        }

        $rdfa = $page->filterXPath('//*[contains(@typeof, "Product")]');
        if ($rdfa->count() > 0) {
            $this->fromScope($rdfa->first(), 'property', $url, $out);
        }

        return $out;
    }

    private function fromScope(Crawler $scope, string $attr, string $url, ExtractedProduct $out): void
    {
        $src = $this->name();
        $prop = fn (string $name) => $this->prop($scope, $attr, $name, $url);

        $out->fill('title', $prop('name'), $src);
        $brand = $scope->filterXPath(".//*[@{$attr}='brand' or @{$attr}='schema:brand']");
        if ($brand->count()) {
            $nested = $this->prop($brand->first(), $attr, 'name', $url);
            $out->fill('brand', $nested ?? $this->value($brand->getNode(0), $url), $src);
        }
        foreach (['gtin13', 'gtin12', 'gtin14', 'gtin8', 'gtin'] as $g) {
            $out->fill('gtin', $prop($g), $src);
        }
        $out->fill('mpn', $prop('mpn'), $src);
        $out->fill('sku', $prop('sku'), $src);
        $out->fill('model', $prop('model'), $src);
        $out->fill('image_url', $prop('image'), $src);
        $out->fill('price', $prop('price') ?? $prop('lowPrice'), $src);
        $out->fill('currency', $prop('priceCurrency'), $src);
        $out->fill('availability', $prop('availability'), $src);
        $out->fill('condition', $prop('itemCondition'), $src);
        $out->fill('rating', $prop('ratingValue'), $src);
        $out->fill('review_count', $prop('reviewCount') ?? $prop('ratingCount'), $src);

        $seller = $scope->filterXPath(".//*[@{$attr}='seller' or @{$attr}='schema:seller']");
        if ($seller->count()) {
            $out->fill('seller_name', $this->prop($seller->first(), $attr, 'name', $url), $src);
        }
    }

    private function prop(Crawler $scope, string $attr, string $name, string $url): ?string
    {
        $nodes = $scope->filterXPath(".//*[@{$attr}='{$name}' or @{$attr}='schema:{$name}' or @{$attr}='http://schema.org/{$name}']");

        return $nodes->count() ? $this->value($nodes->getNode(0), $url) : null;
    }

    private function value(?\DOMNode $node, string $url): ?string
    {
        if (! $node instanceof DOMElement) {
            return null;
        }
        foreach (['content', 'value'] as $a) {
            if ($node->hasAttribute($a)) {
                return trim($node->getAttribute($a));
            }
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['link', 'a'], true) && $node->hasAttribute('href')) {
            return Url::absolutize($node->getAttribute('href'), $url) ?? $node->getAttribute('href');
        }
        if (in_array($tag, ['img', 'source'], true) && $node->hasAttribute('src')) {
            return Url::absolutize($node->getAttribute('src'), $url);
        }
        if ($tag === 'meta') {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        return $text === '' ? null : $text;
    }
}
