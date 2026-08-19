<?php

namespace Nodeflow\Execution;

use Illuminate\Database\QueryException;

/**
 * Classifies whether a QueryException represents specifically a unique-
 * constraint violation, as opposed to any other integrity-constraint failure
 * (foreign key, not null, check). StartRun's idempotency-race recovery exists
 * only for the unique-key collision; treating any class-23 SQLSTATE as a
 * match would silently swallow a genuine, unrelated data-integrity bug (e.g.
 * a NOT NULL violation in the materialiser's own insert) and return a stale
 * pre-existing run instead of surfacing the real error.
 *
 * Extracted to its own class, rather than kept as a private method on
 * StartRun, specifically so this classification can be unit tested against
 * constructed QueryException instances without a real database connection.
 */
final class UniqueConstraintViolation
{
    public static function matches(QueryException $e): bool
    {
        $sqlState = (string) $e->getCode();

        // Postgres: unique_violation has its own precise SQLSTATE, distinct from
        // foreign_key_violation (23503), not_null_violation (23502), and
        // check_violation (23514). No further check is needed.
        if ($sqlState === '23505') {
            return true;
        }

        $driverCode = $e->errorInfo[1] ?? null;

        // MySQL reports SQLSTATE 23000 for the whole integrity-constraint class,
        // so the SQLSTATE alone can't distinguish a duplicate key from a foreign
        // key or not-null failure. Its own driver-specific code narrows it:
        // 1062 is specifically "Duplicate entry ... for key ...".
        if ($sqlState === '23000' && (int) $driverCode === 1062) {
            return true;
        }

        // SQLite also reports 23000 for the whole class, and — confirmed against
        // a real PDO SQLite connection — its driver code is the same generic 19
        // for unique, not-null, foreign-key, and check violations alike, so the
        // code cannot disambiguate here either:
        //
        //   UNIQUE:   SQLSTATE[23000]: ... 19 UNIQUE constraint failed: t.k
        //   NOT NULL: SQLSTATE[23000]: ... 19 NOT NULL constraint failed: t.n
        //   FOREIGN KEY: SQLSTATE[23000]: ... 19 FOREIGN KEY constraint failed
        //   CHECK:    SQLSTATE[23000]: ... 19 CHECK constraint failed: age > 0
        //
        // The driver's message text is the only reliable signal SQLite gives.
        if ($sqlState === '23000' && str_contains(strtolower($e->getMessage()), 'unique constraint failed')) {
            return true;
        }

        return false;
    }
}
