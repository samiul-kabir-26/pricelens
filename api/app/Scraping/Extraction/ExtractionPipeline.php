<?php

namespace App\Scraping\Extraction;

use App\Models\Source;
use App\Models\Tenant;
use App\Scraping\Data\ExtractedProduct;
use Laravel\Pennant\Feature;
use Symfony\Component\DomCrawler\Crawler;

/**
 * JSON-LD → Microdata/RDFa → Meta → Embedded state → Adapter selectors → LLM (flagged).
 * Earlier extractors win; later ones fill missing fields only.
 */
class ExtractionPipeline
{
    /** @var array<string, array<int, string>> */
    public array $selectorHits = [];

    public function __construct(
        private JsonLdExtractor $jsonLd,
        private MicrodataExtractor $microdata,
        private MetaTagExtractor $meta,
        private EmbeddedStateExtractor $embedded,
        private SelectorExtractor $selectors,
        private LlmExtractor $llm,
    ) {}

    public function run(string $html, string $url, ?Source $source = null, ?Tenant $tenant = null, bool $allowLlm = true): ExtractedProduct
    {
        $page = new Crawler($html, $url);
        $result = new ExtractedProduct;

        foreach ([$this->jsonLd, $this->microdata, $this->meta, $this->embedded, $this->selectors] as $extractor) {
            $result->merge($extractor->extract($page, $url, $source));
        }
        $this->selectorHits = $this->selectors->lastHits;

        if (! $result->hasPrice() && $allowLlm && $tenant && Feature::for($tenant)->active('llm-extraction')) {
            $result->merge($this->llm->extract($page, $url, $source));
        }

        if (! $result->has('title')) {
            $title = $page->filter('h1')->count() ? trim($page->filter('h1')->first()->text('')) : null;
            $title ??= $page->filter('title')->count() ? trim($page->filter('title')->text('')) : null;
            $result->fill('title', $title, 'html');
        }

        return $result;
    }

    /** Failure reason when the extraction can't produce an offer, else null. */
    public static function failureReason(ExtractedProduct $product): ?string
    {
        if ($product->hasPrice()) {
            return null;
        }

        $structured = array_intersect(array_values($product->fieldSources()), ['json_ld', 'microdata', 'meta', 'embedded_state', 'selector']);

        return $structured === [] && ! $product->has('gtin') ? 'not_product_page' : 'no_price_found';
    }
}
