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
