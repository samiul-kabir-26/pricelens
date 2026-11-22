<?php

namespace App\Http\Requests;

use App\Models\Source;
use App\Rules\Hostname;
use App\Rules\PublicHttpUrl;
use App\Rules\ValidGtin;
use App\Scraping\Providers\ProviderManager;
use App\Support\Money;
use App\Support\Url;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravel\Pennant\Feature;

class StoreSearchRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->decodeJsonFields('sources', 'options');

        // Drop blocklisted domains silently (spec: "filtered against tenant blocklist").
        $domains = $this->input('sources.domains');
        if (is_array($domains)) {
            $blocklist = (array) $this->tenant()->setting('domains.blocklist', []);
            $filtered = array_values(array_filter(array_map(fn ($d) => is_string($d) ? strtolower(trim($d)) : $d, $domains),
                fn ($d) => ! is_string($d) || ! collect($blocklist)->contains(fn ($b) => Url::matchesDomain($d, (string) $b))));
            $sources = $this->input('sources');
            $sources['domains'] = $filtered;
            $this->merge(['sources' => $sources]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->tenant();
        $maxResults = $tenant->limit('max_results_per_search') ?? 500;

        return [
            'title' => ['nullable', 'string', 'max:500'],
            'gtin' => ['nullable', new ValidGtin],
            'mpn' => ['nullable', 'string', 'max:100'],
            'brand' => ['nullable', 'string', 'max:100'],
            'image_url' => ['nullable', new PublicHttpUrl],
            'image' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'reference_url' => ['nullable', new PublicHttpUrl],

            'sources' => ['nullable', 'array'],
            'sources.source_ids' => ['nullable', 'array', 'max:100'],
            'sources.source_ids.*' => ['string', 'ulid'],
            'sources.domains' => ['nullable', 'array', 'max:100'],
            'sources.domains.*' => [new Hostname],
            'sources.search_engines' => ['nullable', 'array', 'max:10'],
            'sources.search_engines.*.provider' => ['required', Rule::in(ProviderManager::DRIVERS)],
            'sources.search_engines.*.mode' => ['required', Rule::in(['web', 'shopping'])],
            'sources.search_engines.*.scope' => ['nullable', Rule::in(['open', 'allowed_domains'])],
            'sources.search_engines.*.verify' => ['nullable', 'boolean'],
            'sources.reverse_image' => ['nullable', 'boolean'],

            'options' => ['nullable', 'array'],
            'options.country' => ['nullable', 'string', 'size:2', 'alpha'],
            'options.language' => ['nullable', 'string', 'regex:/^[a-z]{2}(-[A-Za-z]{2})?$/'],
            'options.target_currency' => ['nullable', 'string', 'size:3', function ($attr, $value, $fail) {
                if (! Money::isValidCurrency(strtoupper((string) $value))) {
                    $fail('The :attribute must be an ISO 4217 currency code.');
                }
            }],
            'options.condition' => ['nullable', Rule::in(['new', 'refurbished', 'used', 'any'])],
            'options.max_results' => ['nullable', 'integer', 'min:1', "max:{$maxResults}"],
            'options.include_possible' => ['nullable', 'boolean'],
            'options.min_confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'options.fresh' => ['nullable', 'boolean'],
            'options.callback_url' => ['nullable', new PublicHttpUrl],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $v) {
            $tenant = $this->tenant();
            if (! $this->filled('title') && ! $this->filled('image_url') && ! $this->hasFile('image')
                && ! $this->filled('gtin') && ! $this->filled('mpn') && ! $this->filled('reference_url')) {
                $v->errors()->add('title', 'At least one of title, image_url/image, gtin, mpn or reference_url is required.');
            }

            $ids = (array) $this->input('sources.source_ids', []);
            if ($ids !== []) {
                $found = Source::query()->whereIn('ulid', $ids)->get();
                foreach ($ids as $i => $id) {
                    $source = $found->firstWhere('ulid', $id);
                    if (! $source || (! $source->isOwnedBy($tenant->id) && ! ($source->is_global && $source->enabled))) {
                        $v->errors()->add("sources.source_ids.{$i}", 'Unknown or unavailable source.');
                    }
                }
            }

            $count = count($ids) + count((array) $this->input('sources.domains', [])) + count((array) $this->input('sources.search_engines', []));
            $max = $tenant->limit('max_sources_per_search');
            if ($max !== null && $count > $max) {
                $v->errors()->add('sources', "Your plan allows at most {$max} sources per search.");
            }

            if ($this->boolean('sources.reverse_image') && ! Feature::for($tenant)->active('image-search')) {
                $v->errors()->add('sources.reverse_image', 'Image search is not enabled for your plan.');
            }
            if ($this->hasFile('image') && ! Feature::for($tenant)->active('image-search')) {
                $v->errors()->add('image', 'Image search is not enabled for your plan.');
            }
            if ($this->filled('options.callback_url') && ! $tenant->callback_secret) {
                $v->errors()->add('options.callback_url', 'Create a callback signing secret first: POST /api/v1/callbacks/secret.');
            }
            if ($this->input('sources.search_engines') && ! Feature::for($tenant)->active('search-engines')) {
                $v->errors()->add('sources.search_engines', 'Search-engine providers are not enabled for your plan.');
            }
        }];
    }
}
