<?php

namespace Nodeflow\Triggers;

use Closure;

final readonly class TriggerMatch
{
    /** @param  array<string, TriggerTenantMatch>  $matches */
    private function __construct(private array $matches = []) {}

    public static function make(): self
    {
        return new self;
    }

    public function forTenant(
        string $tenantId,
        string $subjectType,
        iterable|Closure $subjectIds,
        array $triggerData = [],
        ?string $occurrenceId = null,
    ): self {
        $tenantId = (string) $tenantId;
        $matches = $this->matches;
        $matches[$tenantId] = new TriggerTenantMatch(
            tenantId: $tenantId,
            subjectType: (string) $subjectType,
            subjectIds: $subjectIds,
            triggerData: $triggerData,
            occurrenceId: $occurrenceId,
        );

        return new self($matches);
    }

    /** @return TriggerTenantMatch[] */
    public function tenants(): array
    {
        return array_values($this->matches);
    }
}
