<?php

use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;

it('serializes the complete trigger definition using node field wire conventions', function () {
    $field = Field::select('source')->options(['orders' => 'Orders']);
    $definition = TriggerDefinition::make('Order placed')
        ->icon('bolt')
        ->description('Starts when an order is placed.')
        ->fields([$field]);

    expect($definition->fieldObjects())->toBe([$field])
        ->and($definition->toArray())->toEqual([
            'label' => 'Order placed',
            'icon' => 'bolt',
            'description' => 'Starts when an order is placed.',
            'fields' => [$field->toWireArray()],
        ]);
});

it('combines reserved and source-contributed fields without mutating either definition', function () {
    $node = TriggerDefinition::make('Webhook')->fields([
        Field::select('source')->required(),
        Field::boolean('enabled')->default(false),
    ]);
    $source = TriggerDefinition::make('Orders')->fields([
        Field::select('account')->required()->default('primary'),
    ]);

    $combined = $node->combinedWith($source);

    expect(array_column($combined->fieldObjects(), 'key'))->toBe(['source', 'enabled', 'account'])
        ->and(array_column($node->fieldObjects(), 'key'))->toBe(['source', 'enabled'])
        ->and(array_column($source->fieldObjects(), 'key'))->toBe(['account'])
        ->and($combined->defaultConfig())->toBe(['enabled' => false, 'account' => 'primary'])
        ->and($combined->rules())->toHaveKeys(['source', 'enabled', 'account']);
});

it('reports and refuses trigger source field collisions', function () {
    $node = TriggerDefinition::make('Webhook')->fields([Field::select('source')]);
    $source = TriggerDefinition::make('Colliding')->fields([Field::text('source')]);

    expect($node->collidingFieldKeys($source))->toBe(['source'])
        ->and(fn () => $node->combinedWith($source))
        ->toThrow(InvalidArgumentException::class, 'source');
});
