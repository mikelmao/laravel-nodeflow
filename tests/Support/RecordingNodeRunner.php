<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;

class RecordingNodeRunner extends NodeRunner
{
    public int $calls = 0;

    /** @param string[] $result */
    public function __construct(public array $result = []) {}

    public function run(Run $run, Graph $graph, string $nodeId): array
    {
        $this->calls++;

        return $this->result;
    }
}
