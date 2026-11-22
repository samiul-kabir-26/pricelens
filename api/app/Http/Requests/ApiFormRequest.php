<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ability middleware + policies authorize
    }

    protected function tenant(): Tenant
    {
        return CurrentTenant::instance()->get();
    }

    /** Multipart clients send nested objects as JSON strings. */
    protected function decodeJsonFields(string ...$fields): void
    {
        foreach ($fields as $field) {
            $value = $this->input($field);
            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $this->merge([$field => $decoded]);
                }
            }
        }
    }
}
