<?php

namespace Nodeflow\Execution;

use Illuminate\Support\Facades\DB;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\FlowInterpreter;
use RuntimeException;

class StartRun
{
    public function __construct(
        private AudienceMaterialiser $materialiser,
        private WorkflowEngine $engine,
    ) {}

    public function forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run
    {
        if ($flow->current_version_id === null) {
            throw new RuntimeException("Flow [{$flow->id}] has no published version.");
        }

        $key = $options['idempotency_key'] ?? null;

        if ($key !== null) {
            $existing = Run::withoutTenancy()
                ->where('flow_version_id', $flow->current_version_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $ids = is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds);

        $version = $flow->currentVersion()->firstOrFail();
        $startNodeId = Graph::fromArray($version->graph)->startNodeId();

        $run = DB::transaction(function () use ($flow, $version, $options, $key, $subjectType, $ids, $startNodeId) {
            $run = Run::create([
                'flow_version_id' => $version->id,
                'tenant_id' => $flow->tenant_id,
                'correlation_id' => $options['correlation_id'] ?? null,
                'strategy' => $options['strategy'] ?? (count($ids) === 1 ? 'subject' : 'cohort'),
                'status' => 'pending',
                'is_test' => (bool) ($options['is_test'] ?? false),
                'idempotency_key' => $key,
            ]);

            $this->materialiser->materialise($run, $subjectType, $ids, $startNodeId);

            return $run;
        });

        $workflowId = $this->engine->start(FlowInterpreter::class, [
            'run_id' => $run->id,
            'max_steps' => (int) config('nodeflow.limits.max_steps_per_run', 1000),
        ]);

        $run->update(['engine_workflow_id' => $workflowId]);

        return $run->fresh();
    }
}
