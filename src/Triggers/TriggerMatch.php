<?php

namespace Nodeflow\Triggers;

class TriggerMatch
{
    private array $tenants = [];

    public static function make(): self
    {
        return new self;
    }

    public function forTenant(string $tenantId, string $subjectType, iterable $subjectIds): self
    {
        $this->tenants[$tenantId] = [
            'subject_type' => $subjectType,
            'subject_ids' => is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds),
        ];

        return $this;
    }

    /** @return array<string, array{subject_type: string, subject_ids: array}> */
    public function tenants(): array
    {
        return $this->tenants;
    }
}
