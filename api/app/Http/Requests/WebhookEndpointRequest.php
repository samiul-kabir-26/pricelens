<?php

namespace App\Http\Requests;

use App\Models\WebhookEndpoint;
use App\Rules\PublicHttpUrl;
use Illuminate\Validation\Rule;

class WebhookEndpointRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'url' => [$req, new PublicHttpUrl, function ($a, $v, $fail) {
                if (app()->isProduction() && ! str_starts_with((string) $v, 'https://')) {
                    $fail('Webhook URLs must use https.');
                }
            }],
            'events' => [$req, 'array', 'min:1'],
            'events.*' => [Rule::in([...WebhookEndpoint::EVENTS, '*'])],
            'enabled' => ['sometimes', 'boolean'],
            'rotate_secret' => ['sometimes', 'boolean'],
        ];
    }
}
