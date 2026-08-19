<?php

namespace Nodeflow\Models;

use RuntimeException;

class CrossTenantWriteException extends RuntimeException
{
    public function __construct(string $modelClass, string $ambientTenant, string $attemptedTenant)
    {
        parent::__construct(
            "Cross-tenant write attempted: {$modelClass} creation for tenant '{$attemptedTenant}' while resolved to '{$ambientTenant}'"
        );
    }
}
