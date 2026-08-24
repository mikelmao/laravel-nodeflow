<?php

namespace Nodeflow\Execution;

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Models\Run;

class AudienceContext
{
    public function __construct(
        private Run $run,
        private string $nodeId,
        private array $config,
        private string $subjectType,
        private array $subjectIds,
    ) {}

    /**
     * The run's identity, not the run itself. Node bodies get the id (for
     * idempotency keys, logging, and correlating third-party calls), the
     * correlation id, and isTest() — deliberately not the mutable Run model,
     * which would make `$c->run()->delete()` inside a node body legal. Narrowing
     * this later would be a breaking change for every host node written in the
     * meantime, so it is narrow from the start.
     */
    public function runId(): int
    {
        return $this->run->id;
    }

    /**
     * The host-supplied correlation id, e.g. the alert that triggered this
     * journey. Also carries sub-flow lineage as a `>`-joined chain.
     */
    public function correlationId(): ?string
    {
        return $this->run->correlation_id;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /** @return string[] */
    public function subjectIds(): array
    {
        return $this->subjectIds;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    /** @return array<string, mixed> subjectId => model */
    public function subjects(): array
    {
        return app(SubjectResolver::class)->resolve($this->subjectType, $this->subjectIds);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }

    public function triggerData(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->run->trigger_data ?? [];

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    public function isTest(): bool
    {
        return $this->run->is_test;
    }

    public function partition(array $outputToSubjectIds): NodeResult
    {
        return NodeResult::partition($outputToSubjectIds);
    }

    public function all(string $output = 'default'): NodeResult
    {
        return NodeResult::partition([$output => $this->subjectIds]);
    }
}
