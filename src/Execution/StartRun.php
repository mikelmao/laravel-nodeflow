<?php

namespace Nodeflow\Execution;

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use RuntimeException;

class StartRun
{
    public function __construct(
        private CreateRun $createRun,
        private GraphTypeCatalog $types,
    ) {}

    public function forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run
    {
        if ($flow->current_version_id === null) {
            throw new RuntimeException("Flow [{$flow->id}] has no published version.");
        }

        $version = $flow->currentVersion()->firstOrFail();
        $graph = Graph::fromArray($version->graph);
        $triggerNodeId = $graph->startNodeId();

        if ($this->types->family($graph->node($triggerNodeId)['type'] ?? '') !== 'trigger') {
            throw new RuntimeException("Graph start node [{$triggerNodeId}] must be a trigger node.");
        }

        return $this->createRun->forVersion(
            $version,
            $subjectType,
            $subjectIds,
            $graph->entryNodeId($this->types),
            [
                ...$options,
                'started_via' => 'manual',
                'trigger_node_id' => $triggerNodeId,
                'trigger_data' => null,
            ],
        );
    }
}
