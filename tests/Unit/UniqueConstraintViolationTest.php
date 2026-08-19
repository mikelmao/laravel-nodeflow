<?php

use Illuminate\Database\QueryException;
use Nodeflow\Execution\UniqueConstraintViolation;

/**
 * Builds a QueryException that carries the given SQLSTATE and driver-specific
 * errorInfo, the way Illuminate\Database\QueryException does when it wraps a
 * real PDOException: it copies $previous->getCode() into its own code, and
 * $previous->errorInfo into its own errorInfo, when $previous is a
 * PDOException. No real database connection is needed to construct one.
 */
function fakeQueryException(string $sqlState, ?int $driverCode, string $driverMessage): QueryException
{
    $previous = new PDOException("SQLSTATE[{$sqlState}]: {$driverMessage}", (int) $sqlState);
    $previous->errorInfo = [$sqlState, $driverCode, $driverMessage];

    return new QueryException('testing', 'insert into "t" ...', [], $previous);
}

it('matches a Postgres-shaped unique violation', function () {
    $e = fakeQueryException('23505', 7, 'ERROR:  duplicate key value violates unique constraint "nodeflow_runs_flow_version_id_idempotency_key_unique"');

    expect(UniqueConstraintViolation::matches($e))->toBeTrue();
});

it('matches a MySQL-shaped unique violation', function () {
    $e = fakeQueryException('23000', 1062, "Duplicate entry '1-alert-218' for key 'nodeflow_runs_flow_version_id_idempotency_key_unique'");

    expect(UniqueConstraintViolation::matches($e))->toBeTrue();
});

it('matches a SQLite-shaped unique violation', function () {
    $e = fakeQueryException('23000', 19, 'UNIQUE constraint failed: nodeflow_runs.flow_version_id, nodeflow_runs.idempotency_key');

    expect(UniqueConstraintViolation::matches($e))->toBeTrue();
});

it('does not match a Postgres-shaped foreign key violation', function () {
    $e = fakeQueryException('23503', 7, 'ERROR:  insert or update on table "nodeflow_runs" violates foreign key constraint');

    expect(UniqueConstraintViolation::matches($e))->toBeFalse();
});

it('does not match a MySQL-shaped not-null violation', function () {
    $e = fakeQueryException('23000', 1048, "Column 'tenant_id' cannot be null");

    expect(UniqueConstraintViolation::matches($e))->toBeFalse();
});

// These three are the ones that matter most: SQLite reports the same SQLSTATE
// (23000) and the same generic driver code (19) for every constraint type, so
// only the message text tells them apart. A classifier that stopped at
// "SQLSTATE class 23" — the exact regression this test guards against — would
// wrongly call each of these a unique violation too.
it('does not match a SQLite-shaped foreign key violation', function () {
    $e = fakeQueryException('23000', 19, 'FOREIGN KEY constraint failed');

    expect(UniqueConstraintViolation::matches($e))->toBeFalse();
});

it('does not match a SQLite-shaped not-null violation', function () {
    $e = fakeQueryException('23000', 19, 'NOT NULL constraint failed: nodeflow_runs.tenant_id');

    expect(UniqueConstraintViolation::matches($e))->toBeFalse();
});

it('does not match a SQLite-shaped check violation', function () {
    $e = fakeQueryException('23000', 19, 'CHECK constraint failed: age > 0');

    expect(UniqueConstraintViolation::matches($e))->toBeFalse();
});
