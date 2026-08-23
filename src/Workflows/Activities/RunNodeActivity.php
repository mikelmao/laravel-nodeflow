<?php

namespace Nodeflow\Workflows\Activities;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Workflow\V2\Activity;

class RunNodeActivity extends Activity
{
    public function handle(int $runId, string $nodeId): array
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);
        $version = $run->flowVersion;

        if ($version === null) {
            throw (new ModelNotFoundException)->setModel(FlowVersion::class, [$run->flow_version_id]);
        }

        if ((string) $run->tenant_id !== (string) $version->tenant_id) {
            throw CrossTenantExecutionException::forRunVersion($run, $version);
        }

        $run->increment('steps_taken');

        return app(NodeRunner::class)->run($run, Graph::fromArray($version->graph), $nodeId);
    }
}
