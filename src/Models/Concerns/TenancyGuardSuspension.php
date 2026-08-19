<?php

namespace Nodeflow\Models\Concerns;

/**
 * @internal Package-internal escape hatch. Not part of Nodeflow's public API
 * and not for host application use.
 *
 * BelongsToTenant's `creating()` contradiction check exists to stop *host*
 * code writing an attacker-influenced `tenant_id` in a request context (e.g.
 * `Flow::create($request->all())` where `$request` supplied `tenant_id`).
 * It is not meant to block the package's own system-authored writes, which
 * carry a `tenant_id` already read from a trusted row in the database (e.g.
 * `StartRun` creating a `Run` with `$flow->tenant_id`) while reacting to a
 * system event — such as a fan-out across tenants — that has no ambient
 * tenant of its own.
 *
 * This class suspends *only* that contradiction check, for the duration of a
 * closure, on every model using BelongsToTenant. It does not touch the read
 * scope: `withoutTenancy()` already exists for opting a query out of tenant
 * scoping, and this is not a second way to do that job. The stamping of
 * `tenant_id` on a model with none set is also unaffected — suspension only
 * disables the *throw*, not the default-assignment behaviour.
 *
 * Never call this to make a genuinely contradicting write "succeed" against
 * the ambient tenant on behalf of the host application. It exists solely for
 * the package's own internal writes where the tenant_id did not come from
 * external input.
 */
final class TenancyGuardSuspension
{
    private static int $depth = 0;

    /**
     * Run $callback with the contradiction check suspended. Re-entrant and
     * exception-safe: nested calls compose correctly, and the suspension is
     * always restored — including when $callback throws — so a leaked flag
     * can never disable the guard process-wide.
     */
    public static function run(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }

    public static function isActive(): bool
    {
        return self::$depth > 0;
    }
}
