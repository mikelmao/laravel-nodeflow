<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;

final readonly class TriggerTenantMatch
{
    public function __construct(
        string $tenantId,
        string $subjectType,
        array $subjectIds,
        public array $triggerData = [],
        ?string $occurrenceId = null,
    ) {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank tenant ID.');
        }

        if (trim($subjectType) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank subject type.');
        }

        $subjectIds = array_values(array_map(
            static fn (mixed $subjectId): string => (string) $subjectId,
            $subjectIds,
        ));

        foreach ($subjectIds as $subjectId) {
            if (trim($subjectId) === '') {
                throw new InvalidArgumentException('A trigger tenant match must not contain a blank subject ID.');
            }
        }

        if ($occurrenceId !== null && trim($occurrenceId) === '') {
            throw new InvalidArgumentException('A trigger tenant match occurrence ID must be null or nonblank.');
        }

        $this->tenantId = $tenantId;
        $this->subjectType = $subjectType;
        $this->subjectIds = $subjectIds;
        $this->occurrenceId = $occurrenceId;
    }

    public string $tenantId;

    public string $subjectType;

    /** @var string[] */
    public array $subjectIds;

    public ?string $occurrenceId;
}
