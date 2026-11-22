<?php

namespace App\Http\Requests;

use App\Models\ApiKey;
use Illuminate\Validation\Rule;

class ApiKeyRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(ApiKey::ABILITIES)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
