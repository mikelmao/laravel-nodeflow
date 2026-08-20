<?php

use Nodeflow\Schema\Field;
use Tests\Support\FakeOptionSource;

it('compiles a custom field type through to the editor payload', function () {
    // FieldType is an enum a host cannot add a case to, so a bespoke control needs
    // a type string that bypasses it. Counterfactual: drop custom() and a host
    // cannot declare a town picker at all.
    $field = Field::custom('destination', 'town')->label('Destination')->required();

    expect($field->toArray())->toMatchArray([
        'key' => 'destination',
        'type' => 'town',
        'label' => 'Destination',
        'required' => true,
    ]);
});

it('validates a custom field with the base rule it was given', function () {
    // Publish-time validation has to work for a type the package has never heard
    // of. Counterfactual: hard-code 'string' and a numeric custom field accepts
    // anything.
    $rules = Field::custom('altitude', 'elevation', 'numeric')->required()->rules();

    expect($rules['altitude'])->toBe(['required', 'numeric']);
});

it('defaults a custom field to a string rule', function () {
    $rules = Field::custom('destination', 'town')->rules();

    expect($rules['destination'])->toBe(['nullable', 'string']);
});

it('tells the editor a field is dynamic without naming the class behind it', function () {
    // Spec E6: the browser needs to know THAT a field is dynamic, never what PHP
    // class backs it. Leaking the name buys nothing and invites an endpoint that
    // accepts it. Counterfactual: keep emitting options_source and the class name
    // is in every palette payload.
    $field = Field::select('template')->optionsFrom(FakeOptionSource::class);

    expect($field->toArray())
        ->toHaveKey('dynamic_options', true)
        ->and($field->toArray())->not->toHaveKey('options_source');
});

it('keeps the declared source reachable server-side', function () {
    // The options endpoint resolves it from the node's own definition. Without an
    // accessor there is no way to reach it except the payload we just removed it
    // from.
    $field = Field::select('template')->optionsFrom(FakeOptionSource::class);

    expect($field->optionsSourceClass())->toBe(FakeOptionSource::class);
});

it('reports a static-optioned field as not dynamic', function () {
    $field = Field::select('channel')->options(['sms' => 'SMS']);

    expect($field->toArray())->toHaveKey('dynamic_options', false)
        ->and($field->optionsSourceClass())->toBeNull();
});
