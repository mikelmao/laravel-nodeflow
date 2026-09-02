<?php

namespace Nodeflow\Facts;

use Nodeflow\Facts\Exceptions\FactContractException;

final class FactPredicateEvaluator
{
    public static function supports(FactValueType $type, string $operator): bool
    {
        return in_array($operator, ['equals', 'not_equals', 'in'], true)
            || $type === FactValueType::Number
                && in_array($operator, ['greater_than', 'less_than'], true);
    }

    public function matches(bool|int|float|string $actual, CompiledFactPredicate $predicate): bool
    {
        if (! $predicate->type->accepts($actual)) {
            throw new FactContractException('A resolved fact value does not match the pinned fact type.');
        }

        return match ($predicate->operator) {
            'equals' => $actual === $predicate->value,
            'not_equals' => $actual !== $predicate->value,
            'in' => in_array($actual, $predicate->value, true),
            'greater_than' => $this->number($actual) > $this->number($predicate->value),
            'less_than' => $this->number($actual) < $this->number($predicate->value),
            default => throw new FactContractException("Unsupported runtime fact operator [{$predicate->operator}]."),
        };
    }

    private function number(mixed $value): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            throw new FactContractException('A numeric fact comparison received a non-numeric value.');
        }

        return (float) $value;
    }
}
