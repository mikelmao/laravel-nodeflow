<?php

use Illuminate\Support\Facades\Validator;
use Nodeflow\Schema\Field;

/**
 * Field::duration() used to compile to ['required', 'string'], so WaitNode
 * accepted any string at publish. InterpreterLoop then passed it verbatim to
 * awaitWithTimeout, which does
 * `(int) ceil(CarbonInterval::fromString($timeout)->totalSeconds)`. Against the
 * installed Carbon, "banana" and "" both yield 0 seconds with no exception while
 * "1 fortnight" throws — inconsistent as well as unsafe. A non-technical author
 * typing "1 dya" published successfully and the day-2 marketing SMS went to real
 * bank customers seconds after the flood alert.
 */
function durationErrors(mixed $value): array
{
    return Validator::make(
        ['delay' => $value],
        Field::duration('delay')->required()->rules(),
    )->errors()->get('delay');
}

it('accepts durations the engine can actually parse', function (string $value) {
    expect(durationErrors($value))->toBe([]);
})->with(['1 day', '5 minutes', '2 weeks', '3 days 4 hours', '1 month', '90 seconds']);

it('rejects a duration the engine cannot parse', function () {
    // Carbon throws InvalidIntervalException on this one.
    expect(durationErrors('1 dya'))->not->toBe([])
        ->and(implode(' ', durationErrors('1 dya')))->toContain('1 dya');
});

it('rejects a duration that silently parses to zero', function (string $value) {
    // The dangerous class: no exception, just an immediate wait.
    expect(durationErrors($value))->not->toBe([]);
})->with(['banana', '0 seconds', 'soon', 'tomorrow']);

it('rejects a negative duration', function () {
    expect(durationErrors('-1 day'))->not->toBe([]);
});

it('explains what a valid duration looks like', function () {
    expect(implode(' ', durationErrors('banana')))->toContain('1 day');
});

it('applies to every duration field, not only the wait node', function () {
    // The rule lives on Field::duration(), so any future duration field inherits it.
    $rules = Field::duration('cool_off')->rules();

    expect($rules['cool_off'])->toContain('nullable')
        ->and(collect($rules['cool_off'])->contains(fn ($r) => $r instanceof Nodeflow\Schema\Rules\ValidDuration))
        ->toBeTrue();
});

it('leaves an optional duration alone when it is absent', function () {
    $errors = Validator::make([], Field::duration('cool_off')->rules())->errors()->get('cool_off');

    expect($errors)->toBe([]);
});
