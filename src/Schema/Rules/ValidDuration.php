<?php

namespace Nodeflow\Schema\Rules;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Rejects, at publish time, any duration the engine would not honour.
 *
 * A duration field's value is eventually handed to the engine's
 * `awaitWithTimeout()`, which resolves it as
 * `(int) ceil(CarbonInterval::fromString($value)->totalSeconds)`. Carbon's
 * behaviour there is neither total nor safe: "1 fortnight" throws, while
 * "banana" and "" parse happily to **zero seconds**. Zero is the dangerous
 * outcome — a non-technical author typing `1 dya` for a day-2 follow-up would
 * publish successfully and the SMS would reach real bank customers seconds
 * after the flood alert instead of a day later.
 *
 * This rule performs exactly the parse the engine will perform, so a value that
 * passes here cannot surprise the interpreter, and it fails in front of the
 * author rather than in front of the customer. It lives on Field::duration() so
 * every duration field inherits it, not only core.wait.
 */
class ValidDuration implements ValidationRule
{
    /**
     * Laravel skips non-implicit rules entirely when trim($value) === '' (see
     * Validator::presentOrRuleIsImplicit()), so without this an empty or
     * whitespace-only duration would sail through unvalidated on any field
     * that isn't also required() — required() happens to catch '' itself via
     * its own implicit rule, which is the only reason WaitNode's duration was
     * ever safe. Any future optional duration field needs this rule to fire
     * on its own.
     *
     * Read by Illuminate\Validation\InvokableValidationRule::make(), which
     * wraps this rule as implicit if this property is true. Being implicit
     * also makes Laravel invoke validate() when the attribute is absent
     * entirely (value is then null) — guarded below so an absent optional
     * duration field still passes.
     */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent (or explicitly null) is not this rule's concern: required()
        // /nullable() already govern presence. Without this guard, making the
        // rule implicit would fail every optional duration field simply for
        // not being provided.
        if ($value === null) {
            return;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('The :attribute must be a relative duration such as "5 minutes", "1 day" or "2 weeks".');

            return;
        }

        $seconds = self::seconds((string) $value);

        if ($seconds === null) {
            $fail(sprintf(
                'The :attribute [%s] is not a duration this system can understand. '
                .'Use a relative duration such as "5 minutes", "1 day" or "2 weeks".',
                (string) $value,
            ));

            return;
        }

        if ($seconds <= 0) {
            $fail(sprintf(
                'The :attribute [%s] resolves to %d seconds, which would make the step happen '
                .'immediately rather than after a delay. Use a positive relative duration such '
                .'as "5 minutes", "1 day" or "2 weeks".',
                (string) $value,
                $seconds,
            ));
        }
    }

    /**
     * The engine's own resolution, mirrored: null means "Carbon refused it",
     * a non-positive integer means "Carbon accepted it and it means nothing".
     */
    public static function seconds(string $value): ?int
    {
        try {
            return (int) ceil(CarbonInterval::fromString($value)->totalSeconds);
        } catch (Throwable) {
            return null;
        }
    }
}
