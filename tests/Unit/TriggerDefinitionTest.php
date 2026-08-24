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

it('rejects field keys that cannot be stable JSON keys or route segments', function (
    string $key,
    string $reason,
) {
    expect(fn () => Field::text($key))
        ->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'numeric leading key' => ['1field', 'must start with a lowercase letter'],
    'slash path ambiguity' => ['bad/field', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'percent path ambiguity' => ['bad%2ffield', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'whitespace' => ['bad field', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'control character' => ["bad\nfield", 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'invalid UTF-8' => ["bad\xFF", 'valid UTF-8'],
    'too long' => [str_repeat('f', 192), '191'],
]);

it('accepts route-addressable field keys at the storage boundary', function () {
    $key = 'tenant.template_variant-'.str_repeat('f', 167);

    expect(strlen($key))->toBe(191)
        ->and(Field::text($key)->toWireArray()['key'])->toBe($key);
});
