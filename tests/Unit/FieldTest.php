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
