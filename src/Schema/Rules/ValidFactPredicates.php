<?php

namespace Nodeflow\Schema\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Nodeflow\Facts\FactPredicate;
use Throwable;

final readonly class ValidFactPredicates implements ValidationRule
{
    public function __construct(private int $maximum) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $this->maximum) {
            $fail("The :attribute must contain at most {$this->maximum} fact predicates.");

            return;
        }

        foreach ($value as $predicate) {
            try {
                if (! is_array($predicate)) {
                    throw new \InvalidArgumentException;
                }
                FactPredicate::fromArray($predicate);
            } catch (Throwable) {
                $fail('Every :attribute item must be a valid fact predicate.');

                return;
            }
        }
    }
}

