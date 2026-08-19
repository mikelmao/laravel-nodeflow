<?php

namespace Nodeflow\Models;

use RuntimeException;

/**
 * Thrown when a tenant-scoped read happens with no resolvable tenant, and the
 * application has declared (via nodeflow.tenancy = 'resolver') that it has
 * tenancy — so a null tenant means "could not resolve", not "not applicable".
 *
 * The message has to be actionable. An error a reader cannot act on gets
 * answered by switching the mode back to 'disabled', which would defeat the
 * point of failing closed at all.
 */
class TenancyUnresolvedException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "Tenancy is unresolved: reading {$modelClass} requires a current tenant because "
            ."nodeflow.tenancy is set to 'resolver' and TenantResolver::currentTenantId() returned null. "
            ."Either resolve a tenant for this context, or call {$modelClass}::withoutTenancy() if this "
            .'read is a deliberate system-wide operation such as a cross-tenant fan-out. Set '
            .'nodeflow.tenancy to "disabled" only if the application genuinely has no tenancy at all.'
        );
    }
}
