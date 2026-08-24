<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\FlowVersion;

class TriggerActivationValidator
{
    public function __construct(
        private readonly GraphTypeCatalog $types,
        private readonly TriggerNodeRegistry $triggerNodes,
        private readonly TriggerActivationSnapshotComparator $snapshots,
    ) {}

    /** @return array{0: FlowVersion, 1: string} */
    public function validatePinned(TriggerActivationSnapshot $activation): array
    {
        $version = FlowVersion::withoutTenancy()->findOrFail($activation->flowVersionId);

        if ((int) $version->id !== $activation->flowVersionId
            || (int) $version->flow_id !== $activation->flowId) {
            throw new InvalidArgumentException('Trigger activation flow/version tuple does not match the persisted version.');
        }

        if ((string) $version->tenant_id !== $activation->tenantId) {
            throw new InvalidArgumentException('Trigger activation tenant does not match the persisted version tenant.');
        }

        $graph = Graph::fromArray($version->graph);
        $triggerNodeId = $graph->startNodeId();

        if ($triggerNodeId !== $activation->triggerNodeId) {
            throw new InvalidArgumentException(
                "Trigger activation node [{$activation->triggerNodeId}] does not match graph start [{$triggerNodeId}]."
            );
        }

        $node = $graph->node($triggerNodeId);

        if ($this->types->family($node['type'] ?? '') !== 'trigger') {
            throw new InvalidArgumentException("Graph start node [{$triggerNodeId}] is not a trigger node.");
        }

        $trigger = $this->triggerNodes->resolve($node['type']);
        $descriptor = $trigger->compile($node['config'] ?? []);

        if (! $this->snapshots->matchesDescriptor($activation, $descriptor)) {
            throw new InvalidArgumentException(
                'Trigger activation routing metadata does not match the descriptor compiled from its pinned graph.'
            );
        }

        return [$version, $graph->entryNodeId($this->types)];
    }
}
