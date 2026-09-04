<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Accepts only the amounts Money can carry exactly, so a value that passes
 * validation can never fail conversion deeper in the request.
 *
 * This is stricter than `decimal:0,2`, which also accepts forms like '+10.00',
 * '.5' and '10.' that are numerically fine but are not amounts this API
 * promises to understand.
 */
final class MonetaryAmount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A JSON number is parsed as a float, and most two-decimal amounts have
        // no exact float representation, so the value would already have been
        // altered before it arrived. Whole numbers are exact, so they are fine.
        if (is_float($value)) {
            $fail('The :attribute must be sent as a string, so that no rounding can happen in transit.');

            return;
        }

        if (! is_string($value) && ! is_int($value)) {
            $fail('The :attribute must be an amount such as "1000.00".');

            return;
        }

        try {
            Money::fromDecimalString((string) $value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be an amount with at most 2 decimal places, such as "1000.00".');
        }
    }
}
