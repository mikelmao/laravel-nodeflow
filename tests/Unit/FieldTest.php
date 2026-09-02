<?php

use Illuminate\Support\Facades\Validator;
use Nodeflow\Schema\Field;

it('serialises a select field for the editor', function () {
    $field = Field::select('channel')
        ->label('Channel')
        ->options(['sms' => 'SMS', 'whatsapp' => 'WhatsApp'])
        ->default('sms')
        ->required();

    expect($field->toArray())->toBe([
        'key' => 'channel',
        'type' => 'select',
        'label' => 'Channel',
        'help' => null,
        'required' => true,
        'default' => 'sms',
        'options' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'],
        'dynamic_options' => false,
    ]);
});

it('derives a label from the key when not given', function () {
    expect(Field::text('template_key')->toArray()['label'])->toBe('Template key');
});

it('produces validation rules', function () {
    expect(Field::select('channel')->options(['sms' => 'SMS'])->required()->rules())
        ->toBe(['channel' => ['required', 'string', 'in:sms']]);

    expect(Field::number('attempts')->rules())
        ->toBe(['attempts' => ['nullable', 'numeric']]);

    // A duration field carries a parse check as well as the base string rule:
    // Carbon resolves an unparseable duration to zero seconds without throwing,
    // so ['required', 'string'] alone let a zero-second wait publish.
    $duration = Field::duration('delay')->required()->rules()['delay'];

    expect($duration[0])->toBe('required')
        ->and($duration[1])->toBe('string')
        ->and($duration[2])->toBeInstanceOf(Nodeflow\Schema\Rules\ValidDuration::class)
        ->and($duration)->toHaveCount(3);
});

it('records a dynamic options source instead of inline options', function () {
    $field = Field::select('template')->optionsFrom('App\\Nodeflow\\YayaTemplates');

    expect($field->toArray()['options'])->toBe([])
        ->and($field->toArray()['dynamic_options'])->toBeTrue()
        ->and($field->toArray())->not->toHaveKey('options_source')
        ->and($field->rules())->toBe(['template' => ['nullable', 'string']]);
});

it('validates every multiselect choice against its declared options', function () {
    // Counterfactual: change Multiselect's base rule away from array, drop the
    // `in:` rule, or upgrade to a validator where top-level in is not array-aware;
    // then either a valid emitted array fails or an undeclared member passes.
    $rules = Field::multiselect('towns')
        ->options(['a' => 'Ada', 'b' => 'Bek'])
        ->required()
        ->rules();

    expect(Validator::make(['towns' => ['a', 'b']], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['towns' => ['a', 'z']], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['towns' => 'a'], $rules)->passes())->toBeFalse();
});

it('validates dotted field keys as literal flat config keys', function () {
    $field = Field::text('template.variant')->required();
    $rules = $field->rules();

    expect($rules)->toHaveKey('template\.variant')
        ->and($field->toWireArray()['key'])->toBe('template.variant')
        ->and(Validator::make(['template.variant' => 'welcome'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['template' => ['variant' => 'welcome']], $rules)->passes())->toBeFalse()
        ->and(Validator::make([], $rules)->errors()->keys())->toBe(['template.variant']);
});

it('describes a singular fact predicate and validates its authored shape', function () {
    $field = Field::factPredicate('predicate', 'runtime_condition')->required();
    $wire = $field->toWireArray();
    $valid = [
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'equals',
        'value' => 'agriculture',
    ];

    expect($wire['type'])->toBe('fact_predicate')
        ->and($wire['fact_capability'])->toBe('runtime_condition')
        ->and($wire['max_items'])->toBe(1)
        ->and(Validator::make(['predicate' => $valid], $field->rules())->passes())->toBeTrue()
        ->and(Validator::make(['predicate' => [...$valid, 'type' => 'text']], $field->rules())->passes())->toBeFalse();
});

it('describes a bounded fact predicate list and validates every item', function () {
    $field = Field::factPredicates('filters', 'audience_filter', 2)->required();
    $valid = [
        'provider' => 'crm',
        'key' => 'profile.segment',
        'version' => 1,
        'operator' => 'in',
        'value' => ['agriculture'],
    ];

    expect($field->toWireArray()['type'])->toBe('fact_predicates')
        ->and($field->toWireArray()['fact_capability'])->toBe('audience_filter')
        ->and($field->toWireArray()['max_items'])->toBe(2)
        ->and(Validator::make(['filters' => [$valid, [...$valid, 'key' => 'profile.score']]], $field->rules())->passes())->toBeTrue()
        ->and(Validator::make(['filters' => [$valid, $valid, $valid]], $field->rules())->passes())->toBeFalse()
        ->and(Validator::make(['filters' => [['provider' => 'crm']]], $field->rules())->passes())->toBeFalse();
});
