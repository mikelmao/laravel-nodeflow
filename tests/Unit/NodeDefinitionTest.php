<?php

use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

it('serialises a definition for the editor palette', function () {
    $definition = NodeDefinition::make('Send Message')
        ->group('Messaging')
        ->outputs(['sent', 'failed'])
        ->fields([Field::select('channel')->options(['sms' => 'SMS'])]);

    $array = $definition->toArray();

    expect($array['label'])->toBe('Send Message')
        ->and($array['group'])->toBe('Messaging')
        ->and($array['outputs'])->toBe(['sent', 'failed'])
        ->and($array['fields'][0]['key'])->toBe('channel');
});

it('defaults to a single output named default', function () {
    expect(NodeDefinition::make('Thing')->toArray()['outputs'])->toBe(['default']);
});
