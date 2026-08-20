<?php

use Nodeflow\Schema\Rules\ValidDuration;

/**
 * The duration control's unit list lives in TypeScript and its validity is
 * decided in PHP, so nothing but this test connects them.
 *
 * It reads DURATION_UNITS out of the .tsx rather than restating it, because a
 * restated list is a second source of truth: renaming a unit in the control
 * would leave a hand-copied PHP array agreeing with itself while every host's
 * publish rejected the value. This is the same failure mode as open issue F-2,
 * where renaming ->help( in one stub left 203 tests green and the stub fatal in
 * every host.
 */
function durationUnitsFromControl(): array
{
    $path = __DIR__.'/../../resources/js/controls/Duration.tsx';

    expect(file_exists($path))->toBeTrue("Duration.tsx is missing at {$path}.");

    // Anchored on the declaration, and asserted to have matched: a regex that
    // silently matched nothing would make this whole test vacuously green,
    // which is the failure mode this project has recorded eight times.
    $matched = preg_match('/export const DURATION_UNITS = \[([^\]]+)\]/', (string) file_get_contents($path), $m);

    expect($matched)->toBe(1, 'DURATION_UNITS was not found in Duration.tsx - the declaration was renamed or reformatted, and this test can no longer see it.');

    preg_match_all("/'([a-z]+)'/", $m[1], $units);

    return $units[1];
}

function maximumDurationAmountFromControl(): int
{
    $source = (string) file_get_contents(__DIR__.'/../../resources/js/controls/Duration.tsx');
    $matched = preg_match('/export const MAX_DURATION_AMOUNT = (\d+)/', $source, $amount);

    expect($matched)->toBe(1, 'MAX_DURATION_AMOUNT was not found in Duration.tsx, so this test can no longer prove every emitted amount.');

    return (int) $amount[1];
}

it('finds the unit list the duration control actually offers', function () {
    // Counterfactual: rename DURATION_UNITS in Duration.tsx and this fails
    // rather than the next test passing on an empty list.
    expect(durationUnitsFromControl())->toHaveCount(5);
});

it('offers only amount and unit combinations the engine resolves to positive seconds', function () {
    // ValidDuration rejects <= 0, and Carbon resolves both '' and 'banana' to
    // zero without complaint - so a unit the control offers and Carbon does not
    // understand would publish a zero-second wait.
    // The range is finite on purpose: this loop proves every string the control
    // can emit, including its upper boundary, rather than sampling three values.
    // Counterfactual: add 'fortnights' to DURATION_UNITS, raise the maximum past
    // Carbon's accepted range, or drop the TypeScript range guard and this pin
    // either fails or no longer matches the production declaration.
    foreach (durationUnitsFromControl() as $unit) {
        foreach (range(1, maximumDurationAmountFromControl()) as $amount) {
            $serializedUnit = $amount === 1 ? substr($unit, 0, -1) : $unit;
            $duration = "{$amount} {$serializedUnit}";

            expect(ValidDuration::seconds($duration))
                ->toBeGreaterThan(0, "The duration control can emit '{$duration}', which ValidDuration rejects.");
        }
    }
});

it('rejects the zero amount the control refuses to emit', function () {
    // The reason Duration.tsx emits null for an empty amount rather than
    // '0 minutes'. If Carbon ever starts treating '0 minutes' as a real
    // duration, the control's guard becomes unnecessary and this test says so.
    expect(ValidDuration::seconds('0 minutes'))->toBe(0);
});
