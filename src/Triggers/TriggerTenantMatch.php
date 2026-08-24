<?php

namespace Nodeflow\Triggers;

use Closure;
use InvalidArgumentException;
use Nodeflow\Execution\ReplayableSubjectIds;

final readonly class TriggerTenantMatch
{
    public function __construct(
        string $tenantId,
        string $subjectType,
        iterable|Closure $subjectIds,
        array $triggerData = [],
        ?string $occurrenceId = null,
    ) {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank tenant ID.');
        }

        if (trim($subjectType) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank subject type.');
        }

        if ($occurrenceId !== null && trim($occurrenceId) === '') {
            throw new InvalidArgumentException('A trigger tenant match occurrence ID must be null or nonblank.');
        }

        $this->tenantId = $tenantId;
        $this->subjectType = $subjectType;
        $this->subjectIds = ReplayableSubjectIds::from($subjectIds);
        $this->triggerData = $triggerData;
        $this->occurrenceId = $occurrenceId;
    }

    public string $tenantId;

    public string $subjectType;

    public ReplayableSubjectIds $subjectIds;

    public array $triggerData;

    public ?string $occurrenceId;
}
