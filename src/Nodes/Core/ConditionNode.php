<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Schema\SubjectAttributeRegistry;

class ConditionNode extends Node implements HandlesSubject
{
    public const OPERATORS = [
        'is_true' => 'is true',
        'is_false' => 'is false',
        'equals' => 'equals',
        'not_equals' => 'does not equal',
        'in' => 'is one of',
        'greater_than' => 'is greater than',
        'less_than' => 'is less than',
    ];

    public static function type(): string
    {
        return 'core.condition';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Condition')
            ->group('Flow')
            ->outputs(['yes', 'no'])
            ->fields([
                Field::select('attribute')
                    ->label('Attribute')
                    ->optionsFrom(SubjectAttributeRegistry::class)
                    ->required(),
                Field::select('operator')->options(self::OPERATORS)->required(),
                Field::text('value')
                    ->label('Value')
                    ->help('For "is one of", use comma-separated values: "red, orange, yellow"'),
            ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        $registry = app(SubjectAttributeRegistry::class);
        $operator = $context->config('operator');

        // Validate operator is in the known list
        if (! isset(self::OPERATORS[$operator])) {
            throw new \RuntimeException(
                "Unknown condition operator [{$operator}]. Known operators: "
                .implode(', ', array_keys(self::OPERATORS)).'.'
            );
        }

        $attributeKey = $context->config('attribute');
        $actual = $registry->value($attributeKey, $context->subject());
        $expected = $context->config('value');

        // Get the attribute to read its type for proper coercion
        $attribute = $registry->get($attributeKey);
        $type = $attribute?->type ?? 'text';

        $matches = match ($operator) {
            'is_true' => (bool) $actual === true,
            'is_false' => (bool) $actual === false,
            'equals' => $this->equals($actual, $expected, $type),
            'not_equals' => ! $this->equals($actual, $expected, $type),
            'in' => $this->in($actual, $expected, $type),
            'greater_than' => $this->greaterThan($actual, $expected),
            'less_than' => $this->lessThan($actual, $expected),
        };

        return $context->continue($matches ? 'yes' : 'no');
    }

    private function equals(mixed $actual, mixed $expected, string $type): bool
    {
        // Null actual never matches equals
        if ($actual === null) {
            return false;
        }

        return match ($type) {
            'boolean' => filter_var($actual, FILTER_VALIDATE_BOOL) === filter_var($expected, FILTER_VALIDATE_BOOL),
            'number' => is_numeric($actual) && is_numeric($expected) && (float) $actual === (float) $expected,
            default => (string) $actual === (string) $expected,
        };
    }

    private function in(mixed $actual, mixed $expected, string $type): bool
    {
        // Null actual never matches in
        if ($actual === null) {
            return false;
        }

        // Parse expected as comma-separated string if it's a string
        $values = is_string($expected)
            ? array_map('trim', explode(',', $expected))
            : (array) $expected;

        // Check if actual matches any value in the list
        foreach ($values as $value) {
            if ($this->equals($actual, $value, $type)) {
                return true;
            }
        }

        return false;
    }

    private function greaterThan(mixed $actual, mixed $expected): bool
    {
        // Null actual never matches greater_than
        if ($actual === null) {
            return false;
        }

        // Both must be numeric for comparison
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return (float) $actual > (float) $expected;
    }

    private function lessThan(mixed $actual, mixed $expected): bool
    {
        // Null actual never matches less_than
        if ($actual === null) {
            return false;
        }

        // Both must be numeric for comparison
        if (! is_numeric($actual) || ! is_numeric($expected)) {
            return false;
        }

        return (float) $actual < (float) $expected;
    }
}
