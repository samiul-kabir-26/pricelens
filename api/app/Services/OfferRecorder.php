<?php

namespace App\Services;

use App\Events\OfferFound;
use App\Http\Resources\OfferResource;
use App\Models\Offer;
use App\Models\Search;
use App\Models\SearchSource;
use App\Models\Source;
use App\Models\Tenant;
use App\Observability\Metrics;
use App\Scraping\Data\ExtractedProduct;
use App\Scraping\Data\ProductQuery;
use App\Scraping\Fetching\SafeHttpClient;
use App\Scraping\Matching\ImageSimilarity;
use App\Scraping\Matching\Matcher;
use App\Scraping\Normalization\AvailabilityMapper;
use App\Scraping\Normalization\ConditionMapper;
use App\Scraping\Normalization\CurrencyConverter;
use App\Scraping\Normalization\PriceParser;
use App\Scraping\Planning\GtinValidator;
use App\Support\Url;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Stages 5–6 for one listing: normalize price/currency/availability, convert, score, upsert the offer
 * (keyed by search + canonical URL), then broadcast / webhook it.
 */
class OfferRecorder
{
    private const STRUCTURED_METHODS = ['json_ld', 'microdata', 'meta', 'embedded_state', 'feed'];

    public function __construct(
        private PriceParser $prices,
        private CurrencyConverter $fx,
        private Matcher $matcher,
        private ImageSimilarity $images,
        private SafeHttpClient $http,
        private WebhookDispatcher $webhooks,
    ) {}

    /**
     * @return array{offer: ?Offer, error: ?string}
     */
    public function record(Tenant $tenant, Search $search, ?SearchSource $searchSource, string $url, ExtractedProduct $product, ?string $snapshotPath = null, ?string $siteName = null): array
    {
        $source = $searchSource?->source_id ? Source::acrossTenants()->find($searchSource->source_id) : null;
        $sources = $product->fieldSources();
        $currencyHint = $product->get('currency') ?? $source->currency ?? $search->target_currency;

        $priceField = $product->has('sale_price') ? 'sale_price' : 'price';
        $parsed = $this->prices->parse($product->get($priceField), $currencyHint, in_array($sources[$priceField] ?? '', self::STRUCTURED_METHODS, true));
        if ($parsed === null || $parsed['minor'] <= 0) {
            return ['offer' => null, 'error' => 'no_price_found'];
        }
        $currency = $parsed['currency'] ?? $currencyHint;
        $priceMinor = $parsed['minor'];

        $listMinor = null;
        foreach (['list_price', $priceField === 'sale_price' ? 'price' : null] as $field) {
            if ($field && $product->has($field)) {
                $lp = $this->prices->parse($product->get($field), $currency, in_array($sources[$field] ?? '', self::STRUCTURED_METHODS, true));
                if ($lp && $lp['minor'] > $priceMinor) {
                    $listMinor = $lp['minor'];
                    break;
                }
            }
        }

        $shippingMinor = null;
        if ($product->has('shipping_price')) {
            $raw = $product->get('shipping_price');
            if (is_string($raw) && preg_match('/free|gratis|kostenlos/i', $raw)) {
                $shippingMinor = 0;
            } else {
                $sp = $this->prices->parse($raw, $product->get('shipping_currency') ?? $currency, true);
                $shippingMinor = $sp && ($sp['currency'] ?? $currency) === $currency ? $sp['minor'] : ($raw === '0' || $raw === 0 ? 0 : null);
            }
        }

        $converted = $this->fx->convert($priceMinor, $currency, $search->target_currency);
        $convertedTotal = $this->fx->convert($priceMinor + ($shippingMinor ?? 0), $currency, $search->target_currency);

        $query = ProductQuery::fromArray($search->product_query ?? []);
        $offerPhash = $this->offerImageHash($tenant, $query, $product);
        $bands = array_merge(config('pricelens.matching.bands'), (array) $tenant->setting('matching.bands', []));
        $match = $this->matcher->score($query, $product, $search->option('condition'), $offerPhash, $bands);

        $canonical = Url::canonicalize((string) ($this->sameSiteCanonical($product->get('canonical_url'), $url) ?? $url));
        $domain = (string) Url::siteDomain($canonical);
        $method = $product->method();
        $gtin = GtinValidator::normalize((string) $product->get('gtin', ''));

        $attributes = [
            'tenant_id' => $tenant->id,
            'search_id' => $search->id,
            'source_id' => $source?->id,
            'site_domain' => $domain,
            'site_name' => mb_substr((string) ($source && $source->type === 'site' ? $source->name : ($siteName ?? $product->get('seller_name') ?? $domain)), 0, 255),
            'url' => $canonical,
            'url_hash' => sha1($canonical),
            'title' => $this->str($product->get('title'), 512),
            'brand' => $this->str($product->get('brand')),
            'gtin' => $gtin && strlen($gtin) <= 14 ? $gtin : null,
            'mpn' => $this->str($product->get('mpn')),
            'sku' => $this->str($product->get('sku')),
            'image_url' => $this->str($product->get('image_url'), 2048),
            'image_phash' => $offerPhash,
            'price_minor' => $priceMinor,
            'list_price_minor' => $listMinor,
            'shipping_minor' => $shippingMinor,
            'currency' => $currency,
            'converted_price_minor' => $converted['minor'] ?? null,
            'converted_total_minor' => $convertedTotal['minor'] ?? null,
            'fx_rate_date' => $converted['rate_date'] ?? null,
            'availability' => AvailabilityMapper::map($product->get('availability')),
            'condition' => ConditionMapper::map($product->get('condition')),
            'seller_name' => $this->str($product->get('seller_name')),
            'rating' => is_numeric($product->get('rating')) ? min(9.99, (float) $product->get('rating')) : null,
            'review_count' => is_numeric($product->get('review_count')) ? (int) $product->get('review_count') : null,
            'variant' => $product->get('variant_attributes') ?: null,
            'match_confidence' => $match->confidence,
            'match_band' => $match->band,
            'match_type' => $match->type,
            'match_breakdown' => $match->breakdown,
            'extraction_method' => $method,
            'snapshot_path' => $snapshotPath,
            'observed_at' => now(),
        ];

        $offer = DB::transaction(function () use ($attributes, $search, $method) {
            /** @var Offer|null $existing */
            $existing = Offer::query()->where('search_id', $search->id)->where('url_hash', $attributes['url_hash'])->lockForUpdate()->first();
            if ($existing) {
                // Page-extracted data beats provider/feed snippets; never downgrade.
                $existingIsSnippet = in_array($existing->extraction_method, ['provider', 'feed'], true);
                $newIsSnippet = in_array($method, ['provider', 'feed'], true);
                if (! $existingIsSnippet && $newIsSnippet) {
                    return [$existing, false];
                }
                $existing->fill($attributes)->save();

                return [$existing, false];
            }

            return [Offer::query()->create($attributes), true];
        });
        [$offer, $created] = $offer;

        if ($created && $searchSource && $offer->match_band !== 'rejected') {
            SearchSource::query()->whereKey($searchSource->id)->increment('offers_found');
        }

        Metrics::increment('pricelens_offers_total', ['match_band' => $offer->match_band]);
        Metrics::increment('pricelens_extraction_method_total', ['method' => $method]);

        if ($this->isVisible($offer, $search)) {
            $payload = (new OfferResource($offer))->resolve();
            try {
                event(new OfferFound($search->channelName(), $payload));
            } catch (Throwable $e) {
                report($e);
            }
            if ($created) {
                $this->webhooks->dispatch($tenant, 'search.offer_found', ['search_id' => $search->ulid, 'offer' => $payload]);
            }
        }

        return ['offer' => $offer, 'error' => null];
    }

    public function isVisible(Offer $offer, Search $search): bool
    {
        $min = (float) $search->option('min_confidence', 0);
        $bands = $search->option('include_possible', false) ? ['exact', 'likely', 'possible'] : ['exact', 'likely'];

        return in_array($offer->match_band, $bands, true) && $offer->match_confidence >= $min;
    }

    private function offerImageHash(Tenant $tenant, ProductQuery $query, ExtractedProduct $product): ?string
    {
        $imageUrl = $product->get('image_url');
        if (! $query->imagePhash || ! is_string($imageUrl) || ! ImageSimilarity::available() || ! Feature::for($tenant)->active('image-search')) {
            return null;
        }

        try {
            $result = $this->http->get($imageUrl, ['max_bytes' => 3 * 1024 * 1024, 'timeout' => 8, 'headers' => ['User-Agent' => config('pricelens.scraper.user_agent')]]);

            return $result['response']->successful() ? $this->images->hash($result['response']->body()) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Only trust a page's canonical URL when it stays on the same site (prevents cross-site URL spoofing). */
    private function sameSiteCanonical(mixed $canonical, string $url): ?string
    {
        if (! is_string($canonical) || ! preg_match('#^https?://#i', $canonical)) {
            return null;
        }

        return Url::siteDomain($canonical) === Url::siteDomain($url) ? $canonical : null;
    }

    private function str(mixed $value, int $max = 255): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? mb_substr(trim((string) $value), 0, $max) : null;
    }
}
