<?php

namespace Nodeflow\Execution;

use RuntimeException;

class UniformAudienceResultValidator
{
    public function assertValid(
        string $nodeType,
        string $nodeId,
        string $expectedOutput,
        array $declaredOutputs,
        array $expectedSubjectIds,
        NodeResult $result,
    ): void {
        if (trim($expectedOutput) === '' || ! in_array($expectedOutput, $declaredOutputs, true)) {
            $this->fail($nodeType, $nodeId, $expectedOutput, 'invalid_output');
        }

        if ($result->failures() !== []) {
            $this->fail($nodeType, $nodeId, $expectedOutput, 'failures');
        }

        $outputs = $result->outputs();
        $outputKeys = array_map('strval', array_keys($outputs));
        if ($outputKeys !== [$expectedOutput]) {
            $this->fail($nodeType, $nodeId, $expectedOutput, 'unexpected_output_keys');
        }

        $actual = array_map('strval', $outputs[$expectedOutput]);
        if (count(array_unique($actual, SORT_STRING)) !== count($actual)) {
            $this->fail($nodeType, $nodeId, $expectedOutput, 'duplicate_ids');
        }

        $expected = array_map('strval', $expectedSubjectIds);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            $this->fail($nodeType, $nodeId, $expectedOutput, 'missing_or_extra_ids');
        }
    }

    private function fail(
        string $nodeType,
        string $nodeId,
        string $expectedOutput,
        string $category,
    ): never {
        throw new RuntimeException(
            "Uniform audience result for node type [{$nodeType}], node [{$nodeId}], "
            ."expected output [{$expectedOutput}]: {$category}."
        );
    }
}
