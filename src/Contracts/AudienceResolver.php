<?php

namespace Nodeflow\Contracts;

interface AudienceResolver
{
    /**
     * The subject type this resolver produces, e.g. 'user'.
     */
    public function subjectType(): string;

    /**
     * Subject ids for one tenant. May return a lazy iterable; it will be chunked.
     *
     * @return iterable<string>
     */
    public function subjectIds(string $tenantId, array $payload): iterable;
}
