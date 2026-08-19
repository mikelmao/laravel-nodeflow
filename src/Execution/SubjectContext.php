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

    public function run(): Run
    {
        return $this->run;
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
