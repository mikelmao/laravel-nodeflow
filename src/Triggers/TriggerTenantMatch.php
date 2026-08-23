<?php

namespace Nodeflow\Triggers;

final readonly class TriggerTenantMatch
{
    public function __construct(
        public string $tenantId,
        public string $subjectType,
        public array $subjectIds,
        public array $triggerData = [],
        public ?string $occurrenceId = null,
    ) {}
}
