<?php

namespace Nodeflow\Execution;

use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use RuntimeException;

class CrossTenantExecutionException extends RuntimeException
{
    public static function forRunVersion(Run $run, FlowVersion $version): self
    {
        return new self(
            "Cross-tenant execution refused: Run [{$run->id}] belongs to tenant "
            .self::describe($run->tenant_id)." but its FlowVersion [{$version->id}] belongs to "
            .self::describe($version->tenant_id).'. No execution-side mutation was performed.'
        );
    }

    private static function describe(mixed $tenant): string
    {
        return $tenant === null ? 'null' : "'{$tenant}'";
    }
}
