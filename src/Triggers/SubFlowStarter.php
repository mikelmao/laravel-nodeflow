<?php

namespace Nodeflow\Triggers;

use Nodeflow\Execution\CreateRun;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

class SubFlowStarter
{
    public const MAX_DEPTH = 5;

    public function __construct(
        private CreateRun $createRun,
        private GraphTypeCatalog $types,
    ) {}

    public function start(Run $parentRun, int $flowId, string $subjectType, array $subjectIds): ?Run
    {
        $lineage = array_filter(explode('>', (string) $parentRun->correlation_id));

        if (count($lineage) >= self::MAX_DEPTH) {
            return null;
        }

        $flow = Flow::withoutTenancy()
            ->where('id', $flowId)
            ->where('tenant_id', $parentRun->tenant_id)
            ->firstOrFail();

        if ($flow->current_version_id === null) {
            throw new \RuntimeException("Flow [{$flow->id}] has no published version.");
        }

        $version = $flow->currentVersion()->firstOrFail();
        $graph = Graph::fromArray($version->graph);
        $triggerNodeId = $graph->startNodeId();

        if ($this->types->family($graph->node($triggerNodeId)['type'] ?? '') !== 'trigger') {
            throw new \RuntimeException("Graph start node [{$triggerNodeId}] must be a trigger node.");
        }

        return $this->createRun->forVersion(
            $version,
            $subjectType,
            $subjectIds,
            $graph->entryNodeId($this->types),
            [
                'correlation_id' => trim(($parentRun->correlation_id ?? '').'>'.$parentRun->id, '>'),
                'is_test' => $parentRun->is_test,
                'started_via' => 'subflow',
                'trigger_node_id' => $triggerNodeId,
                'trigger_data' => $parentRun->trigger_data,
            ],
        );
    }
}
