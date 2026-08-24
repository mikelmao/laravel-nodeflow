<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\CreateRun;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;

class TriggerRunStarter
{
    public function __construct(
        private CreateRun $createRun,
        private GraphTypeCatalog $types,
        private TenantResolver $tenants,
        private TriggerNodeRegistry $triggerNodes,
        private TriggerActivationSnapshotComparator $snapshots,
    ) {}

    public function start(TriggerActivationSnapshot $activation, TriggerTenantMatch $match): Run
    {
        if ($match->tenantId !== $activation->tenantId) {
            throw new InvalidArgumentException(
                "Trigger match tenant [{$match->tenantId}] does not equal activation tenant [{$activation->tenantId}]."
            );
        }

        $subjectIds = array_values(array_map(
            static fn (mixed $subjectId): string => (string) $subjectId,
            $match->subjectIds,
        ));

        foreach ($subjectIds as $subjectId) {
            if (! $this->tenants->ownsSubject($activation->tenantId, $match->subjectType, $subjectId)) {
                throw new CrossTenantSubjectException($activation->tenantId, $match->subjectType, $subjectId);
            }
        }

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

        if ($this->types->family($graph->node($triggerNodeId)['type'] ?? '') !== 'trigger') {
            throw new InvalidArgumentException("Graph start node [{$triggerNodeId}] is not a trigger node.");
        }

        $this->assertPinnedDescriptor($graph, $triggerNodeId, $activation);

        return $this->createRun->forVersion(
            $version,
            $match->subjectType,
            $subjectIds,
            $graph->entryNodeId($this->types),
            [
                'started_via' => $activation->driver,
                'trigger_node_id' => $activation->triggerNodeId,
                'trigger_data' => $match->triggerData,
                'idempotency_key' => $this->idempotencyKey($activation, $match->occurrenceId),
            ],
        );
    }

    private function assertPinnedDescriptor(
        Graph $graph,
        string $triggerNodeId,
        TriggerActivationSnapshot $activation,
    ): void {
        $node = $graph->node($triggerNodeId);
        $trigger = $this->triggerNodes->resolve($node['type']);
        $descriptor = $trigger->compile($node['config'] ?? []);

        if (! $this->snapshots->matchesDescriptor($activation, $descriptor)) {
            throw new InvalidArgumentException(
                'Trigger activation routing metadata does not match the descriptor compiled from its pinned graph.'
            );
        }
    }

    private function idempotencyKey(TriggerActivationSnapshot $activation, ?string $occurrenceId): ?string
    {
        if ($occurrenceId === null) {
            return null;
        }

        $identity = '';

        foreach ([$activation->driver, $activation->source, $occurrenceId] as $component) {
            $identity .= pack('N', strlen($component)).$component;
        }

        return hash('sha256', $identity);
    }
}
