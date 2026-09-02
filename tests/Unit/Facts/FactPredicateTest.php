<?php

use Nodeflow\Facts\CompiledFactPredicate;
use Nodeflow\Facts\FactDefinition;
use Nodeflow\Facts\FactPredicate;
use Nodeflow\Facts\FactValueType;
use Nodeflow\Facts\MissingFactBehavior;

it('parses an authored predicate only when its shape is exact', function () {
    $predicate = FactPredicate::fromArray([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'in',
        'value' => ['retail', 'agriculture'],
    ]);

    expect($predicate->toArray())->toBe([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'in',
        'value' => ['retail', 'agriculture'],
    ]);

    expect(fn () => FactPredicate::fromArray([
        ...$predicate->toArray(),
        'type' => 'text',
    ]))->toThrow(InvalidArgumentException::class, 'exactly');
});

it('canonicalises and pins a predicate against the selected catalogue revision', function () {
    $definition = new FactDefinition(
        'profile.segment', 1, 'Segment', FactValueType::Text,
        ['runtime_condition'], ['runtime_condition' => ['equals', 'in']],
    );

    $compiled = CompiledFactPredicate::compile(
        FactPredicate::fromArray([
            'provider' => 'crm',
            'key' => 'profile.segment',
            'version' => 1,
            'operator' => 'in',
            'value' => ['retail', 'agriculture', 'retail'],
        ]),
        $definition,
        'runtime_condition',
        'revision-42',
    );

    expect($compiled->toArray())->toBe([
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'type' => 'text',
        'operator' => 'in',
        'value' => ['agriculture', 'retail'],
        'missing_behavior' => 'route_no',
        'catalogue_revision' => 'revision-42',
    ]);
});

it('rejects operators not supported by the selected definition', function () {
    $definition = new FactDefinition(
        'profile.score', 1, 'Score', FactValueType::Number,
        ['runtime_condition'], ['runtime_condition' => ['equals']],
        missingBehavior: MissingFactBehavior::Fail,
    );

    expect(fn () => CompiledFactPredicate::compile(
        new FactPredicate('crm', 'profile.score', 1, 'greater_than', 5),
        $definition,
        'runtime_condition',
        'revision-42',
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects predicate values that do not match the fact type', function () {
    $definition = new FactDefinition(
        'profile.score', 1, 'Score', FactValueType::Number,
        ['runtime_condition'], ['runtime_condition' => ['equals']],
    );

    expect(fn () => CompiledFactPredicate::compile(
        new FactPredicate('crm', 'profile.score', 1, 'equals', '5'),
        $definition,
        'runtime_condition',
        'revision-42',
    ))->toThrow(InvalidArgumentException::class);
});

it('parses only the exact compiled predicate shape', function () {
    $compiled = CompiledFactPredicate::fromArray([
        'provider' => 'crm',
        'key' => 'profile.score',
        'version' => 1,
        'type' => 'number',
        'operator' => 'greater_than',
        'value' => 10,
        'missing_behavior' => 'route_no',
        'catalogue_revision' => 'revision-42',
    ]);

    expect($compiled->type)->toBe(FactValueType::Number)
        ->and($compiled->catalogueRevision)->toBe('revision-42')
        ->and(fn () => CompiledFactPredicate::fromArray([
            ...$compiled->toArray(),
            'capability' => 'runtime_condition',
        ]))->toThrow(InvalidArgumentException::class, 'exactly');
});
