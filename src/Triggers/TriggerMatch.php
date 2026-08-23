<?php

namespace Nodeflow\Triggers;

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
        iterable $subjectIds,
        array $triggerData = [],
        ?string $occurrenceId = null,
    ): self {
        $tenantId = (string) $tenantId;
        $matches = $this->matches;
        $matches[$tenantId] = new TriggerTenantMatch(
            tenantId: $tenantId,
            subjectType: (string) $subjectType,
            subjectIds: array_values(array_map(
                static fn (mixed $subjectId): string => (string) $subjectId,
                is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds),
            )),
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
