<?php

namespace App\Http\Requests;

use App\Rules\PublicHttpUrl;

class TestSourceRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'url' => ['required_without:query', 'nullable', new PublicHttpUrl],
            'query' => ['required_without:url', 'nullable', 'string', 'max:300'],
            'render' => ['sometimes', 'boolean'],
        ];
    }
}
