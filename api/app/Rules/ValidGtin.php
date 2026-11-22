<?php

namespace App\Rules;

use App\Scraping\Planning\GtinValidator;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidGtin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) && ! is_int($value) || ! GtinValidator::isValid((string) $value)) {
            $fail('The :attribute must be a valid GTIN-8/12/13/14 with a correct check digit.');
        }
    }
}
