<?php

namespace App\Rules;

use App\Scraping\Extraction\SelectorEngine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeRegex implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! SelectorEngine::isValidRegex($value)) {
            $fail('The :attribute must be a valid regular expression of at most 300 characters.');
        }
    }
}
