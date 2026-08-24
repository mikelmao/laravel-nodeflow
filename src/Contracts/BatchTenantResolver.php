<?php

namespace Nodeflow\Contracts;

interface BatchTenantResolver extends TenantResolver
{
    /**
     * @param  list<string>  $subjectIds
     * @return list<string>
     *
     * Returns every requested ID owned by the tenant. Requested IDs omitted
     * from the result fail closed, and returned IDs must be a subset of the
     * request.
     */
    public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array;
}
