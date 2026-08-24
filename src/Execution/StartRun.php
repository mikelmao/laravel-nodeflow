<?php

namespace Nodeflow\Execution;

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\Flow;
use Nodeflow\Models\InvalidFlowVersionReferenceException;
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

        if ((string) $version->tenant_id !== (string) $flow->tenant_id) {
            throw CrossTenantExecutionException::forFlowVersion($flow, $version);
        }

        if ((string) $version->flow_id !== (string) $flow->id) {
            throw InvalidFlowVersionReferenceException::forFlowMismatch(
                $flow::class,
                'current_version_id',
                $version->id,
                $version->flow_id,
                $flow->id,
            );
        }

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
