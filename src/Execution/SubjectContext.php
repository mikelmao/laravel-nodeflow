<?php

namespace Nodeflow\Execution;

use Nodeflow\Models\Run;

class SubjectContext
{
    public function __construct(
        private Run $run,
        private string $nodeId,
        private array $config,
        private string $subjectId,
        private mixed $subject,
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

    public function subject(): mixed
    {
        return $this->subject;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }

    /**
     * True when this run must not cause externally visible side effects.
     * Every node that sends, charges, or writes to a third party MUST honour this.
     */
    public function isTest(): bool
    {
        return $this->run->is_test;
    }

    public function continue(string $output = 'default'): NodeResult
    {
        return NodeResult::forSubject($this->subjectId, $output);
    }

    public function fail(string $message): NodeResult
    {
        return NodeResult::failed($this->subjectId, $message);
    }
}
