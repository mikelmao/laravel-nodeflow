<?php

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
        'options_source' => null,
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

    expect(Field::duration('delay')->required()->rules())
        ->toBe(['delay' => ['required', 'string']]);
});

it('records a dynamic options source instead of inline options', function () {
    $field = Field::select('template')->optionsFrom('App\\Nodeflow\\YayaTemplates');

    expect($field->toArray()['options'])->toBe([])
        ->and($field->toArray()['options_source'])->toBe('App\\Nodeflow\\YayaTemplates')
        ->and($field->rules())->toBe(['template' => ['nullable', 'string']]);
});
