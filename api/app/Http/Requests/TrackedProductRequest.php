<?php

namespace App\Http\Requests;

use App\Models\TrackedProduct;
use App\Rules\PublicHttpUrl;
use App\Rules\ValidGtin;
use App\Support\Money;
use Illuminate\Validation\Rule;

class TrackedProductRequest extends ApiFormRequest
{
    /** Prices may be sent as a decimal or in the response's Money shape {amount, currency}. */
    protected function prepareForValidation(): void
    {
        foreach (['reference_price', 'map_price'] as $field) {
            $value = $this->input($field);
            if (is_array($value) && array_key_exists('amount', $value)) {
                $this->merge([$field => $value['amount']]);
                if (! $this->filled('currency') && ! empty($value['currency'])) {
                    $this->merge(['currency' => $value['currency']]);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';
        /** @var TrackedProduct|null $current */
        $current = $this->route('trackedProduct');

        return [
            'external_ref' => ['nullable', 'string', 'max:255',
                Rule::unique('tracked_products', 'external_ref')->where('tenant_id', $this->tenant()->id)->ignore($current?->id)],
            'title' => [$req, 'string', 'max:512'],
            'brand' => ['nullable', 'string', 'max:255'],
            'gtin' => ['nullable', new ValidGtin],
            'mpn' => ['nullable', 'string', 'max:255'],
            'image_url' => ['nullable', new PublicHttpUrl],
            'reference_price' => ['nullable', 'numeric', 'gt:0'],
            'map_price' => ['nullable', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3', fn ($a, $v, $f) => Money::isValidCurrency(strtoupper((string) $v)) ?: $f('Invalid currency.')],
            'attributes' => ['nullable', 'array'],
            'archived' => ['sometimes', 'boolean'],
        ];
    }
}
