<?php

namespace Nodeflow\Execution;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use JsonException;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Jobs\RetryRunDispatch;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\FlowInterpreter;
use RuntimeException;
use Throwable;

class CreateRun
{
    private const DISPATCH_FAILURE = 'Workflow dispatch failed; recovery required.';

    /** @var array<string, list<Run>> */
    private static array $pendingDispatches = [];

    public function __construct(
        private AudienceMaterialiser $materialiser,
        private WorkflowEngine $engine,
        private GraphTypeCatalog $types,
    ) {}

    public function forVersion(
        FlowVersion $version,
        string $subjectType,
        iterable $subjectIds,
        string $entryNodeId,
        array $options,
    ): Run {
        $entryNodeId = $this->validatedEntryNodeId($version, $entryNodeId);
        $key = $this->validatedIdempotencyKey($options['idempotency_key'] ?? null);

        // Preserve the public idempotency contract before consuming a caller's
        // iterable or doing any creation work. A retry returns the already
        // committed representation of the occurrence; it never rematerialises
        // a possibly different audience or starts a second workflow.
        if ($key !== null) {
            $existing = $this->existing($version, (string) $key);

            if ($existing !== null) {
                return $this->ensureEngineStarted($existing, expectedEntryNodeId: $entryNodeId);
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
                    'engine_entry_node_id' => $entryNodeId,
                    'engine_dispatch_status' => 'pending',
                    'engine_dispatch_error' => null,
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

            return $this->ensureEngineStarted($winner, expectedEntryNodeId: $entryNodeId);
        }

        return $this->ensureEngineStarted($run, expectedEntryNodeId: $entryNodeId);
    }

    public function resume(int|string $runId): Run
    {
        $run = Run::withoutTenancy()->findOrFail($runId);
        $version = FlowVersion::withoutTenancy()->findOrFail($run->flow_version_id);

        if ((string) $version->tenant_id !== (string) $run->tenant_id) {
            throw CrossTenantExecutionException::forRunVersion($run, $version);
        }

        $entryNodeId = $this->expectedEntryNodeId($version);

        return $this->ensureEngineStarted($run, scheduleRetry: false, expectedEntryNodeId: $entryNodeId);
    }

    private function ensureEngineStarted(
        Run $run,
        bool $scheduleRetry = true,
        ?string $expectedEntryNodeId = null,
    ): Run
    {
        $entryNodeId = $this->persistedEntryNodeId($run);

        if ($expectedEntryNodeId !== null && $entryNodeId !== $expectedEntryNodeId) {
            throw new InvalidArgumentException(
                "Run [{$run->id}] engine entry [{$entryNodeId}] does not match pinned graph entry [{$expectedEntryNodeId}]."
            );
        }

        if ($run->engine_workflow_id !== null) {
            return $this->markDispatched($run);
        }

        $connection = DB::connection();

        if ($connection->transactionLevel() === 0) {
            try {
                $this->startEngine($run, $entryNodeId);
            } catch (Throwable $e) {
                $run = $this->handleDispatchFailure($run, $scheduleRetry);

                if ($run->engine_workflow_id !== null) {
                    return $run;
                }

                throw $e;
            }

            return $run->refresh();
        }

        // An outer caller owns the remaining transaction. Starting now can
        // launch a workflow for a Run that the outer transaction later rolls
        // back. Coalesce retries for the same Run into one callback while
        // retaining every returned model so all callers observe the handle
        // once the outermost transaction commits.
        $dispatchKey = spl_object_id($connection).':'.$run->id;

        if (isset(self::$pendingDispatches[$dispatchKey])) {
            self::$pendingDispatches[$dispatchKey][] = $run;

            return $run;
        }

        self::$pendingDispatches[$dispatchKey] = [$run];

        try {
            $connection->afterCommit(function () use ($dispatchKey, $run, $entryNodeId, $scheduleRetry) {
                try {
                    $this->startEngine($run, $entryNodeId);
                } catch (Throwable $e) {
                    $this->handleDispatchFailure($run, $scheduleRetry);
                    $this->safeReport($e);
                } finally {
                    $this->synchronizePendingRuns($dispatchKey, $run);
                    unset(self::$pendingDispatches[$dispatchKey]);
                }
            });
            $connection->afterRollBack(fn () => $this->forgetPendingDispatch($dispatchKey));
        } catch (Throwable $e) {
            unset(self::$pendingDispatches[$dispatchKey]);

            throw $e;
        }

        return $run;
    }

    private function forgetPendingDispatch(string $dispatchKey): void
    {
        unset(self::$pendingDispatches[$dispatchKey]);
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
        ], $this->workflowInstanceId($run));

        $run->refresh();

        if ($run->engine_workflow_id !== null) {
            $this->markDispatched($run);

            return;
        }

        DB::table($run->getTable())
            ->where('id', $run->id)
            ->whereNull('engine_workflow_id')
            ->update([
                'engine_workflow_id' => $workflowId,
                'engine_dispatch_status' => 'dispatched',
                'engine_dispatch_error' => null,
            ]);
        $run->refresh();

        if ($run->engine_workflow_id === null) {
            throw new RuntimeException("Run [{$run->id}] workflow handle could not be persisted.");
        }
    }

    private function workflowInstanceId(Run $run): string
    {
        return "nodeflow-run:{$run->id}";
    }

    private function handleDispatchFailure(Run $run, bool $scheduleRetry): Run
    {
        $shouldSchedule = $this->recordDispatchFailure($run);

        if (! $scheduleRetry || ! $shouldSchedule) {
            return $run;
        }

        try {
            Queue::push(new RetryRunDispatch($run->id));
        } catch (Throwable $queueFailure) {
            $this->safeReport($queueFailure);
        }

        try {
            return $run->refresh();
        } catch (Throwable $refreshFailure) {
            $this->safeReport($refreshFailure);

            return $run;
        }
    }

    private function recordDispatchFailure(Run $run): bool
    {
        try {
            $updated = DB::table($run->getTable())
                ->where('id', $run->id)
                ->whereNull('engine_workflow_id')
                ->where(function ($query) {
                    $query->whereNull('engine_dispatch_status')
                        ->orWhere('engine_dispatch_status', 'pending');
                })
                ->update([
                    'engine_dispatch_status' => 'failed',
                    'engine_dispatch_error' => self::DISPATCH_FAILURE,
                ]);
            $run->refresh();

            if ($run->engine_workflow_id !== null) {
                $this->markDispatched($run);

                return false;
            }

            return $updated === 1;
        } catch (Throwable $persistenceFailure) {
            // Preserve the dispatch exception. A persistence failure must not
            // replace the actionable cause returned to the caller.
            $this->safeReport($persistenceFailure);

            return true;
        }
    }

    private function markDispatched(Run $run): Run
    {
        DB::table($run->getTable())
            ->where('id', $run->id)
            ->whereNotNull('engine_workflow_id')
            ->update([
                'engine_dispatch_status' => 'dispatched',
                'engine_dispatch_error' => null,
            ]);
        $run->refresh();

        return $run;
    }

    private function persistedEntryNodeId(Run $run): string
    {
        $entryNodeId = $run->engine_entry_node_id;

        if (! is_string($entryNodeId) || trim($entryNodeId) === '') {
            throw new InvalidArgumentException("Run [{$run->id}] has no persisted engine entry intent.");
        }

        return $entryNodeId;
    }

    private function validatedEntryNodeId(FlowVersion $version, string $entryNodeId): string
    {
        $expected = $this->expectedEntryNodeId($version);

        if ($entryNodeId !== $expected) {
            throw new InvalidArgumentException(
                "Run engine entry [{$entryNodeId}] does not match pinned graph entry [{$expected}] for flow version [{$version->id}]."
            );
        }

        return $expected;
    }

    private function expectedEntryNodeId(FlowVersion $version): string
    {
        $graph = Graph::fromArray($version->graph);
        $start = $graph->startNodeId();
        $family = $this->types->family($graph->node($start)['type'] ?? '');

        if ($family === 'trigger') {
            return $graph->entryNodeId($this->types);
        }

        if ($family === 'executable') {
            return $start;
        }

        throw new RuntimeException(
            "Graph start node [{$start}] must be a registered trigger or executable node."
        );
    }

    private function synchronizePendingRuns(string $dispatchKey, Run $run): void
    {
        foreach (self::$pendingDispatches[$dispatchKey] ?? [] as $pendingRun) {
            if ($pendingRun !== $run) {
                $pendingRun->setRawAttributes($run->getAttributes(), true);
            }
        }
    }

    private function safeReport(Throwable $e): void
    {
        try {
            report($e);
        } catch (Throwable) {
            // Reporting is secondary and must never replace dispatch failure.
        }
    }

    private function validatedIdempotencyKey(mixed $key): ?string
    {
        if ($key === null) {
            return null;
        }

        if (! is_string($key) || $key === '' || strlen($key) > 255) {
            throw new InvalidArgumentException('Run idempotency_key must be a non-empty string of at most 255 bytes.');
        }

        return $key;
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
