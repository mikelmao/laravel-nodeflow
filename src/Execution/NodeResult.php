<?php

namespace Nodeflow\Execution;

class NodeResult
{
    private function __construct(
        private array $outputs = [],
        private array $failures = [],
    ) {}

    public static function forSubject(string $subjectId, string $output = 'default'): self
    {
        return new self([$output => [$subjectId]]);
    }

    public static function partition(array $outputToSubjectIds): self
    {
        return new self(array_map(
            fn (array $ids) => array_values(array_map('strval', $ids)),
            $outputToSubjectIds,
        ));
    }

    public static function failed(string $subjectId, string $message): self
    {
        return new self([], [$subjectId => $message]);
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function merge(self ...$results): self
    {
        $outputs = [];
        $failures = [];

        foreach ($results as $result) {
            foreach ($result->outputs as $output => $ids) {
                $outputs[$output] = array_merge($outputs[$output] ?? [], $ids);
            }

            $failures += $result->failures;
        }

        return new self($outputs, $failures);
    }

    /** @return array<string, string[]> */
    public function outputs(): array
    {
        return $this->outputs;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function subjectCount(): int
    {
        return array_sum(array_map('count', $this->outputs));
    }
}
