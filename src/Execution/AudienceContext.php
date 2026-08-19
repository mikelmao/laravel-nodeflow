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

    public function run(): Run
    {
        return $this->run;
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
