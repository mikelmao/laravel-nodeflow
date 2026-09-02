<?php

use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactPredicateEvaluator;

it('evaluates typed fact operators without coercion', function (string $operator, mixed $value, mixed $actual, bool $matches) {
    $predicate = CompiledFactPredicate::fromArray([
        'provider' => 'crm',
        'key' => 'profile.value',
        'version' => 1,
        'type' => is_bool($actual) ? 'boolean' : (is_string($actual) ? 'text' : 'number'),
        'operator' => $operator,
        'value' => $value,
        'missing_behavior' => 'route_no',
        'catalogue_revision' => 'revision',
    ]);

    expect((new FactPredicateEvaluator)->matches($actual, $predicate))->toBe($matches);
})->with([
    'equal text' => ['equals', 'agriculture', 'agriculture', true],
    'different text' => ['not_equals', 'retail', 'agriculture', true],
    'in list' => ['in', ['agriculture', 'retail'], 'agriculture', true],
    'greater number' => ['greater_than', 10, 11, true],
    'not greater number' => ['greater_than', 10, 10, false],
    'less number' => ['less_than', 10, 9, true],
    'boolean is strict' => ['equals', true, false, false],
]);

