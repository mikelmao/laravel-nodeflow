<?php

namespace Nodeflow\Contracts;

interface TenantResolver
{
    public function currentTenantId(): ?string;

    /**
     * MANDATORY isolation check. Called for every subject before it is
     * materialised into an audience. Returning true for a subject the tenant
     * does not own is a cross-tenant data breach, not a bug.
     */
    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool;
}
