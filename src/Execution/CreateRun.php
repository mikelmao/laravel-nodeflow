<?php

namespace Nodeflow\Execution;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\FlowInterpreter;

class CreateRun
{
    public function __construct(
        private AudienceMaterialiser $materialiser,
        private WorkflowEngine $engine,
    ) {}

    public function forVersion(
        FlowVersion $version,
        string $subjectType,
        iterable $subjectIds,
        string $entryNodeId,
        array $options,
    ): Run {
        $key = $options['idempotency_key'] ?? null;

        // Preserve the public idempotency contract before consuming a caller's
        // iterable or doing any creation work. A retry returns the already
        // committed representation of the occurrence; it never rematerialises
        // a possibly different audience or starts a second workflow.
        if ($key !== null) {
            $existing = $this->existing($version, (string) $key);

            if ($existing !== null) {
                return $existing;
            }
        }

        $triggerData = $this->validatedTriggerData($options['trigger_data'] ?? null);
        $startedVia = $this->requiredOriginString($options, 'started_via');
        $triggerNodeId = $this->requiredOriginString($options, 'trigger_node_id');
        $ids = array_values(array_map(
            static fn (mixed $subjectId): string => (string) $subjectId,
            is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds),
        ));

        try {
            $run = DB::transaction(function () use (
                $version,
                $options,
                $key,
                $subjectType,
                $ids,
                $entryNodeId,
                $startedVia,
                $triggerNodeId,
                $triggerData,
            ) {
                $run = TenancyGuardSuspension::run(fn () => Run::create([
                    'flow_version_id' => $version->id,
                    'tenant_id' => $version->tenant_id,
                    'correlation_id' => $options['correlation_id'] ?? null,
                    'strategy' => $options['strategy'] ?? (count($ids) === 1 ? 'subject' : 'cohort'),
                    'status' => 'pending',
                    'is_test' => (bool) ($options['is_test'] ?? false),
                    'idempotency_key' => $key,
                    'started_via' => $startedVia,
                    'trigger_node_id' => $triggerNodeId,
                    'trigger_data' => $triggerData,
                ]));

                $this->materialiser->materialise($run, $subjectType, $ids, $entryNodeId);

                return $run;
            });
        } catch (QueryException $e) {
            if ($key === null || ! UniqueConstraintViolation::matches($e)) {
                throw $e;
            }

            $winner = $this->existing($version, (string) $key);

            if ($winner === null) {
                throw $e;
            }

            return $winner;
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            $this->startEngine($run, $entryNodeId);

            return $run->refresh();
        }

        // An outer caller owns the remaining transaction. Starting now can
        // launch a workflow for a Run that the outer transaction later rolls
        // back. Laravel discards afterCommit callbacks on rollback and invokes
        // this exactly once when the outermost commit succeeds.
        $connection->afterCommit(fn () => $this->startEngine($run, $entryNodeId));

        return $run;
    }

    private function existing(FlowVersion $version, string $key): ?Run
    {
        return Run::withoutTenancy()
            ->where('flow_version_id', $version->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function startEngine(Run $run, string $entryNodeId): void
    {
        $workflowId = $this->engine->start(FlowInterpreter::class, [
            'run_id' => $run->id,
            'max_steps' => (int) config('nodeflow.limits.max_steps_per_run', 1000),
            'entry_node_id' => $entryNodeId,
        ]);

        $run->update(['engine_workflow_id' => $workflowId]);
    }

    private function validatedTriggerData(mixed $data): ?array
    {
        $configured = config('nodeflow.limits.trigger_data_bytes', 65_536);

        if (is_int($configured)) {
            $limit = $configured;
        } elseif (is_string($configured) && ctype_digit($configured)) {
            $limit = (int) $configured;
        } else {
            throw new InvalidArgumentException('nodeflow.limits.trigger_data_bytes must be a positive integer.');
        }

        if ($limit <= 0) {
            throw new InvalidArgumentException('nodeflow.limits.trigger_data_bytes must be a positive integer.');
        }

        if ($data !== null && ! is_array($data)) {
            throw new InvalidArgumentException('Run trigger_data must be null or an array.');
        }

        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException(
                "Run trigger_data must be JSON-safe: {$e->getMessage()}",
                previous: $e,
            );
        }

        $bytes = strlen($encoded);

        if ($bytes > $limit) {
            throw new InvalidArgumentException(
                "Run trigger_data is {$bytes} bytes; the configured maximum is {$limit} bytes."
            );
        }

        return $data;
    }

    private function requiredOriginString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Run option [{$key}] must be a non-empty string.");
        }

        return $value;
    }
}
