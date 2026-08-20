<?php

namespace Nodeflow\Tenancy;

use Nodeflow\Contracts\TenantResolver;

/**
 * The resolver a host gets when it binds none of its own.
 *
 * This class exists to be *recognisable*. `nodeflow.tenancy` has to decide what a
 * null current tenant means — "this application has no tenancy" or "tenancy could
 * not be resolved here" — and those want opposite handling: read unscoped, or
 * refuse. Under the `auto` mode the package answers that question by asking which
 * resolver is in the container: if it is this one, the host never expressed an
 * opinion about tenancy and a null means the first thing. If the host bound its
 * own, a null means the second.
 *
 * `ownsSubject()` returns false, not true. It is the mandatory audience isolation
 * check, and a resolver that knows nothing about tenants must not be the reason a
 * subject is admitted to a run.
 */
class NoTenancyResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        return null;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        return false;
    }
}
