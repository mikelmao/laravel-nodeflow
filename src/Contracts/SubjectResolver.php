<?php

namespace Nodeflow\Contracts;

interface SubjectResolver
{
    /**
     * @param  string[]  $subjectIds
     * @return array<string, mixed> subjectId => the host's model
     */
    public function resolve(string $subjectType, array $subjectIds): array;
}
