<?php

namespace Nodeflow\Workflows\Activities;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use RuntimeException;
use Workflow\V2\Activity;

class ResolveRunEntryNodeActivity extends Activity
{
    public function handle(int $runId): string
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);
        $version = $run->flowVersion;

        if ($version === null) {
            throw (new ModelNotFoundException)->setModel(FlowVersion::class, [$run->flow_version_id]);
        }

        if ((string) $run->tenant_id !== (string) $version->tenant_id) {
            throw CrossTenantExecutionException::forRunVersion($run, $version);
        }

        $graph = Graph::fromArray($version->graph);
        $types = app(GraphTypeCatalog::class);
        $start = $graph->startNodeId();
        $family = $types->family($graph->node($start)['type'] ?? '');

        if ($family === 'trigger') {
            return $graph->entryNodeId($types);
        }

        if ($family === 'executable') {
            return $start;
        }

        throw new RuntimeException("Graph start node [{$start}] must be a registered trigger or executable node.");
    }
}
