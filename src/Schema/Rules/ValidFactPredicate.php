<?php

namespace Nodeflow\Schema\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nodeflow\Facts\FactPredicate;
use Throwable;

final class ValidFactPredicate implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('The :attribute must be a fact predicate.');

            return;
        }

        try {
            FactPredicate::fromArray($value);
        } catch (Throwable) {
            $fail('The :attribute must be a valid fact predicate.');
        }
    }
}
