<?php

namespace Nodeflow\Execution;

use RuntimeException;

class CrossTenantSubjectException extends RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {
        parent::__construct(
            "Tenant [{$tenantId}] does not own {$subjectType} [{$subjectId}]. ".
            'Audience materialisation aborted; no subjects were written.'
        );
    }
}
