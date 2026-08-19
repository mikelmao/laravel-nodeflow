<?php

namespace Nodeflow\Execution;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
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

        try {
            $run = DB::transaction(function () use ($flow, $version, $options, $key, $subjectType, $ids, $startNodeId) {
                // The tenant_id here is $flow->tenant_id: already read from a
                // trusted row, not attacker-supplied input, and this run is being
                // created in response to a system event (a trigger firing, or a
                // sub-flow start) that has no ambient tenant of its own — that is
                // exactly the case TenancyGuardSuspension exists for. Only the
                // insert itself is suspended, not materialise(): the audience
                // materialiser doesn't write through BelongsToTenant at all, and
                // narrowing the suspension to the single call it's needed for
                // keeps every other write in this transaction subject to the
                // ordinary guard.
                $run = TenancyGuardSuspension::run(fn () => Run::create([
                    'flow_version_id' => $version->id,
                    'tenant_id' => $flow->tenant_id,
                    'correlation_id' => $options['correlation_id'] ?? null,
                    'strategy' => $options['strategy'] ?? (count($ids) === 1 ? 'subject' : 'cohort'),
                    'status' => 'pending',
                    'is_test' => (bool) ($options['is_test'] ?? false),
                    'idempotency_key' => $key,
                ]));

                $this->materialiser->materialise($run, $subjectType, $ids, $startNodeId);

                return $run;
            });
        } catch (QueryException $e) {
            // A redelivered event racing another request for the same (flow version,
            // idempotency key) can both pass the pre-check above before either commits.
            // The loser hits the unique constraint here rather than creating a
            // duplicate run; recover by returning the winner's row instead of a lock.
            // A null key is never part of this recovery: SQL treats NULLs as distinct,
            // so a keyless run's failure here is a genuine, unrelated error.
            if ($key === null || ! UniqueConstraintViolation::matches($e)) {
                throw $e;
            }

            $winner = Run::withoutTenancy()
                ->where('flow_version_id', $version->id)
                ->where('idempotency_key', $key)
                ->first();

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        $workflowId = $this->engine->start(FlowInterpreter::class, [
            'run_id' => $run->id,
            'max_steps' => (int) config('nodeflow.limits.max_steps_per_run', 1000),
        ]);

        $run->update(['engine_workflow_id' => $workflowId]);

        return $run->fresh();
    }
}
