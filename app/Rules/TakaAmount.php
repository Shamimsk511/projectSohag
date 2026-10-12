<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * A positive taka amount as typed in a form ("1,25,000.50", Bengali digits allowed), parsed by Money.
 */
class TakaAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $valid = is_string($value) && Money::fromTaka($value)->isPositive();
        } catch (InvalidArgumentException) {
            $valid = false;
        }

        if (! $valid) {
            $fail('app.validation.taka_amount')->translate();
        }
    }
}
