<?php

namespace App\Http\Requests;

use App\Models\Source;
use App\Rules\Hostname;
use App\Rules\PublicHttpUrl;
use App\Rules\SafeRegex;
use App\Scraping\Extraction\SelectorEngine;
use App\Scraping\Providers\ProviderManager;
use App\Support\Money;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create/update a source. Accepts the spec's flat adapter JSON (adapter keys at top level) or an explicit
 * `config` object.
 */
class SourceRequest extends ApiFormRequest
{
    private const TOP_LEVEL = ['name', 'type', 'domain', 'country', 'currency', 'enabled', 'render', 'config'];

    private const SELECTOR_KEYS = ['title', 'price', 'sale_price', 'list_price', 'availability', 'condition', 'gtin', 'mpn', 'sku',
        'model', 'brand', 'image', 'seller', 'seller_name', 'shipping', 'shipping_price', 'rating', 'review_count', 'currency'];

    protected function prepareForValidation(): void
    {
        if (! $this->has('config')) {
            $extra = collect($this->all())->except(self::TOP_LEVEL)->all();
            if ($extra !== [] || $this->isMethod('POST')) {
                $this->merge(['config' => $extra]);
                $this->attributes->set('config_from_flat', true);
            }
        }
    }

    /**
     * Validated data with the full adapter config: validated() keeps only keys that have rules, but adapter
     * blocks (product, listing, embedded_state) are free-form and checked in after().
     *
     * @return array<string, mixed>
     */
    public function sourceData(): array
    {
        $data = $this->validated();
        if ($this->has('config')) {
            $data['config'] = (array) $this->input('config');
        }

        return $data;
    }

    public function isUpdate(): bool
    {
        return $this->isMethod('PATCH') || $this->isMethod('PUT');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isUpdate() ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255'],
            'type' => [$this->isUpdate() ? 'prohibited' : 'required', Rule::in(['site', 'search_engine', 'shopping_engine', 'reverse_image', 'feed'])],
            'domain' => ['nullable', new Hostname],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'currency' => ['nullable', 'string', 'size:3', fn ($a, $v, $f) => Money::isValidCurrency(strtoupper((string) $v)) ?: $f('Invalid currency.')],
            'enabled' => ['sometimes', 'boolean'],
            'render' => ['sometimes', Rule::in(['never', 'auto', 'always'])],
            'config' => ['sometimes', 'array'],
            'config.search_url_template' => ['nullable', 'string', 'max:500', 'regex:/^https?:\/\/[^\s]+\{query\}/'],
            'config.product_url_patterns' => ['nullable', 'array', 'max:20'],
            'config.product_url_patterns.*' => [new SafeRegex],
            'config.rate_limit' => ['nullable', 'array'],
            'config.rate_limit.requests' => ['nullable', 'integer', 'min:1', 'max:20'],
            'config.rate_limit.per_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'config.rate_limit.concurrency' => ['nullable', 'integer', 'min:1', 'max:10'],
            'config.listing' => ['nullable', 'array'],
            'config.listing.*' => ['nullable'],
            'config.listing.listing_mode' => ['nullable', 'boolean'],
            'config.product' => ['nullable', 'array'],
            'config.product.price_regex' => ['nullable', new SafeRegex],
            'config.embedded_state' => ['nullable', 'array'],
            'config.embedded_state.*' => ['nullable', 'string', 'max:500'],
            'config.headers' => ['nullable', 'array', 'max:10'],
            'config.headers.*' => ['string', 'max:500'],
            'config.wait_for_selector' => ['nullable', 'string', 'max:500'],
            'config.provider' => ['nullable', Rule::in(ProviderManager::DRIVERS)],
            'config.scope' => ['nullable', Rule::in(['open', 'allowed_domains'])],
            'config.allowed_domains' => ['nullable', 'array', 'max:100'],
            'config.allowed_domains.*' => [new Hostname],
            'config.verify' => ['nullable', 'boolean'],
            'config.feed_url' => ['nullable', new PublicHttpUrl],
            'config.format' => ['nullable', Rule::in(['csv', 'xml'])],
            'config.sitemap_urls' => ['nullable', 'array', 'max:20'],
            'config.sitemap_urls.*' => [new PublicHttpUrl],
            'config.sitemap_child_pattern' => ['nullable', new SafeRegex],
            'config.sitemap_url_pattern' => ['nullable', new SafeRegex],
            'config.sitemap_max_files' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'config.refresh_interval' => ['nullable', 'integer', 'min:15', 'max:10080'],
        ];
    }

    /** The source being updated (route-model bound), or null on create. */
    private function existingSource(): ?Source
    {
        $source = $this->route('source');

        return $source instanceof Source ? $source : null;
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            $existing = $this->existingSource();
            $type = $this->input('type') ?? $existing?->type;
            $config = (array) $this->input('config', $existing->config ?? []);
            $domain = $this->input('domain', $existing?->domain);

            match ($type) {
                'site' => (function () use ($v, $domain) {
                    if (! $domain) {
                        $v->errors()->add('domain', 'A site source requires a domain.');
                    }
                })(),
                'search_engine', 'shopping_engine', 'reverse_image' => empty($config['provider'])
                    ? $v->errors()->add('config.provider', 'A provider is required.') : null,
                'feed' => empty($config['feed_url']) && empty($config['upload_path'])
                    ? $v->errors()->add('config.feed_url', 'A feed source requires feed_url.') : null,
                default => null,
            };

            // Selector length guard (catastrophic selectors / payload abuse).
            foreach (['product' => self::SELECTOR_KEYS, 'listing' => ['item', 'item_link', 'item_title', 'item_price', 'item_image']] as $block => $keys) {
                foreach ($keys as $k) {
                    $value = $config[$block][$k] ?? null;
                    $selector = is_array($value) ? ($value['selector'] ?? null) : $value;
                    if ($selector !== null && (! is_string($selector) || strlen($selector) > 500)) {
                        $v->errors()->add("config.{$block}.{$k}", 'Selectors must be strings of at most 500 characters.');
                    }
                    if (is_array($value) && isset($value['regex']) && ! SelectorEngine::isValidRegex((string) $value['regex'])) {
                        $v->errors()->add("config.{$block}.{$k}.regex", 'Invalid regex.');
                    }
                }
            }
            if (strlen((string) json_encode($config)) > 64 * 1024) {
                $v->errors()->add('config', 'Adapter config must be smaller than 64 KB.');
            }
        }];
    }
}
