<?php

namespace Nodeflow\Contracts;

interface BatchTenantResolver extends TenantResolver
{
    /**
     * @param  list<string>  $subjectIds
     * @return list<string>
     */
    public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array;
}
