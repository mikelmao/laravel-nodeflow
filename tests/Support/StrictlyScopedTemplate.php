<?php

namespace Tests\Support;

use Nodeflow\Models\Template;

/**
 * Test-only model that extends Template but disables global row inclusion.
 * Used to cover the scope's exclusion branch: allowsGlobalTenantRows() = false
 * paired with a nullable tenant_id column.
 */
class StrictlyScopedTemplate extends Template
{
    protected $table = 'nodeflow_templates';

    public function allowsGlobalTenantRows(): bool
    {
        return false;
    }
}
