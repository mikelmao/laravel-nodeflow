<?php

namespace Nodeflow\Models;

use RuntimeException;

/**
 * A write whose tenant_id contradicts the tenant the write's own context says
 * it must be.
 *
 * Three shapes, one exception, because a caller catching this always wants the
 * same thing — refuse the write — and only the message differs:
 *
 * - forCreate(): an insert carrying an explicit tenant_id that contradicts the
 *   ambient tenant (the classic `Flow::create($request->all())` with an
 *   attacker-supplied tenant_id).
 * - forTenantChange(): an update that would move an existing row from one
 *   tenant to another.
 * - forParentMismatch(): an insert whose tenant_id contradicts the tenant of
 *   the parent row it hangs off, which is the real authority (a FlowVersion
 *   against its Flow).
 *
 * Named constructors rather than one constructor with a mode flag: each shape
 * needs different values in the message, and a message that says "creation"
 * while describing an update is exactly the kind of misdirection that costs an
 * hour in an incident.
 */
class CrossTenantWriteException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    /**
     * An insert whose explicit tenant_id contradicts the ambient tenant.
     */
    public static function forCreate(string $modelClass, mixed $ambientTenant, mixed $attemptedTenant): self
    {
        return new self(
            "Cross-tenant write attempted: {$modelClass} creation for tenant "
            .self::describe($attemptedTenant).' while resolved to '.self::describe($ambientTenant)
        );
    }

    /**
     * An update that would move an existing row to a different tenant.
     */
    public static function forTenantChange(string $modelClass, mixed $originalTenant, mixed $newTenant): self
    {
        return new self(
            "Cross-tenant write attempted: {$modelClass} tenant_id may not change after creation ("
            .self::describe($originalTenant).' -> '.self::describe($newTenant).'). Reassigning a row to '
            .'another tenant would take every child row reachable through it along — including the ones '
            .'that carry no tenant_id of their own. Create the row in the target tenant instead.'
        );
    }

    /**
     * An insert whose tenant_id contradicts the parent row it belongs to.
     */
    public static function forParentMismatch(
        string $modelClass,
        string $parentDescription,
        mixed $parentTenant,
        mixed $attemptedTenant,
    ): self {
        return new self(
            "Cross-tenant write attempted: {$modelClass} for tenant ".self::describe($attemptedTenant)
            ." while its {$parentDescription} belongs to tenant ".self::describe($parentTenant)
            .'. The parent is the authority on which tenant the child belongs to.'
        );
    }

    private static function describe(mixed $tenant): string
    {
        return $tenant === null ? 'null' : "'".$tenant."'";
    }
}
