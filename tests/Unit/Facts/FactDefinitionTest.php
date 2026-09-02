<?php

use Nodeflow\Facts\FactCatalogue;
use Nodeflow\Facts\FactDefinition;
use Nodeflow\Facts\FactOption;
use Nodeflow\Facts\FactValueType;
use Nodeflow\Facts\MissingFactBehavior;

it('serialises a versioned fact definition deterministically', function () {
    $definition = new FactDefinition(
        key: 'profile.segment',
        version: 2,
        label: 'Customer segment',
        type: FactValueType::Text,
        capabilities: ['runtime_condition', 'audience_filter'],
        operators: [
            'runtime_condition' => ['not_equals', 'equals'],
            'audience_filter' => ['in'],
        ],
        options: [
            new FactOption('retail', 'Retail', false),
            new FactOption('agriculture', 'Agriculture'),
        ],
        missingBehavior: MissingFactBehavior::RouteNo,
    );

    expect($definition->toArray())->toBe([
        'key' => 'profile.segment',
        'version' => 2,
        'label' => 'Customer segment',
        'type' => 'text',
        'capabilities' => ['audience_filter', 'runtime_condition'],
        'operators' => [
            'audience_filter' => ['in'],
            'runtime_condition' => ['equals', 'not_equals'],
        ],
        'options' => [
            ['value' => 'agriculture', 'label' => 'Agriculture', 'active' => true],
            ['value' => 'retail', 'label' => 'Retail', 'active' => false],
        ],
        'missing_behavior' => 'route_no',
    ]);
});

it('rejects definitions whose capabilities and operators disagree', function () {
    expect(fn () => new FactDefinition(
        key: 'profile.segment',
        version: 1,
        label: 'Segment',
        type: FactValueType::Text,
        capabilities: ['runtime_condition'],
        operators: ['audience_filter' => ['in']],
    ))->toThrow(InvalidArgumentException::class, 'operators');
});

it('rejects duplicate option values', function () {
    expect(fn () => new FactDefinition(
        key: 'profile.score',
        version: 1,
        label: 'Score',
        type: FactValueType::Number,
        capabilities: ['runtime_condition'],
        operators: ['runtime_condition' => ['equals']],
        options: [new FactOption(1, 'One'), new FactOption(1, 'Again')],
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects option values that do not match the fact type', function () {
    expect(fn () => new FactDefinition(
        key: 'profile.score',
        version: 1,
        label: 'Score',
        type: FactValueType::Number,
        capabilities: ['runtime_condition'],
        operators: ['runtime_condition' => ['equals']],
        options: [new FactOption('1', 'One')],
    ))->toThrow(InvalidArgumentException::class);
});

it('indexes catalogue definitions by exact key and version', function () {
    $v1 = new FactDefinition(
        'profile.segment', 1, 'Segment', FactValueType::Text,
        ['runtime_condition'], ['runtime_condition' => ['equals']],
    );
    $v2 = new FactDefinition(
        'profile.segment', 2, 'Segment', FactValueType::Text,
        ['runtime_condition'], ['runtime_condition' => ['equals']],
    );
    $catalogue = new FactCatalogue('crm', 'revision-42', [$v2, $v1]);

    expect($catalogue->definition('profile.segment', 1))->toBe($v1)
        ->and($catalogue->definition('profile.segment', 2))->toBe($v2)
        ->and($catalogue->toArray()['facts'][0]['version'])->toBe(1);
});

it('serialises the exact versioned contract consumed by the reusable editor', function () {
    $definition = new FactDefinition(
        'profile.segment', 1, 'Segment', FactValueType::Text,
        ['runtime_condition'], ['runtime_condition' => ['equals']],
    );
    $catalogue = new FactCatalogue('crm', 'revision-42', [$definition]);

    expect($catalogue->toEditorArray())->toBe([
        'contract_version' => 1,
        'revision' => 'revision-42',
        'facts' => [$definition->toArray()],
    ]);
});
