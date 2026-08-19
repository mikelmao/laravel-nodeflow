<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;
use Workflow\V2\Activity;

class RunNodeActivity extends Activity
{
    public function handle(int $runId, string $nodeId): array
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);

        $run->increment('steps_taken');

        return app(NodeRunner::class)->run($run, Graph::fromArray($run->flowVersion->graph), $nodeId);
    }
}
