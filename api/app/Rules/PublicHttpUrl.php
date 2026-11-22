<?php

namespace App\Rules;

use App\Scraping\Fetching\SafeUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** http(s) URL whose host does not resolve to a private/internal address (SSRF guard at write time). */
class PublicHttpUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 2048 || ! preg_match('#^https?://#i', $value) || filter_var($value, FILTER_VALIDATE_URL) === false) {
            $fail('The :attribute must be a valid http(s) URL.');

            return;
        }
        if (! app(SafeUrlGuard::class)->isSafe($value)) {
            $fail('The :attribute must point to a public host.');
        }
    }
}
