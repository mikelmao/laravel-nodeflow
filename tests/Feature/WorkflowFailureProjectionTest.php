<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\DurableWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Workflows\FlowInterpreter;
use Nodeflow\Workflows\ProjectWorkflowFailure;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\Serializers\Serializer;
use Workflow\V2\Enums\HistoryEventType;
use Workflow\V2\Enums\RunStatus;
use Workflow\V2\Enums\TaskStatus;
use Workflow\V2\Enums\TaskType;
use Workflow\V2\Events\WorkflowFailed;
use Workflow\V2\Jobs\RunWorkflowTask;
use Workflow\V2\Models\WorkflowFailure;
use Workflow\V2\Models\WorkflowHistoryEvent;
use Workflow\V2\Models\WorkflowRun;
use Workflow\V2\Models\WorkflowTask;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string { return 'org-1'; }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
    });
});

function failureProjectionRun(string $tenantId = 'org-1'): Run
{
    return TenancyGuardSuspension::run(function () use ($tenantId): Run {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => 'Failure projection',
            'status' => 'active',
        ]);
        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'tenant_id' => $tenantId,
            'version' => 1,
            'graph' => ['nodes' => [], 'edges' => []],
            'content_hash' => "failure-projection-{$flow->id}",
        ]);
        $run = Run::withoutTenancy()->create([
            'flow_version_id' => $version->id,
            'tenant_id' => $tenantId,
            'started_via' => 'manual',
            'trigger_node_id' => 'trigger',
            'trigger_data' => null,
            'strategy' => 'cohort',
            'status' => 'running',
        ]);

        RunSubject::create([
            'run_id' => $run->id,
            'subject_type' => 'user',
            'subject_id' => 'active-1',
            'current_node_id' => 'send',
            'status' => 'active',
        ]);
        RunSubject::create([
            'run_id' => $run->id,
            'subject_type' => 'user',
            'subject_id' => 'active-2',
            'current_node_id' => 'wait',
            'status' => 'active',
        ]);
        RunSubject::create([
            'run_id' => $run->id,
            'subject_type' => 'user',
            'subject_id' => 'completed',
            'current_node_id' => null,
            'status' => 'completed',
        ]);

        return $run;
    });
}

function workflowFailureFor(Run $run, ?string $instanceId = null, ?string $workflowClass = null, ?string $message = null, ?string $committedAt = null): WorkflowFailed
{
    return new WorkflowFailed(
        instanceId: $instanceId ?? "nodeflow-run:{$run->id}",
        runId: 'durable-run-4',
        workflowType: 'class',
        workflowClass: $workflowClass ?? FlowInterpreter::class,
        exceptionClass: RuntimeException::class,
        message: $message ?? 'Yaya remained unavailable',
        committedAt: $committedAt ?? '2026-08-25T14:15:16+00:00',
    );
}

function prepareFailureProjectionDurableRuntime($test): void
{
    app()->register(WorkflowServiceProvider::class);
    $test->artisan('migrate', [
        '--path' => realpath(__DIR__.'/../../vendor/durable-workflow/workflow/src/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
    config()->set('workflows.v2.task_dispatch_mode', 'poll');
    config()->set('queue.default', 'sync');
    app()->bind(WorkflowEngine::class, DurableWorkflowEngine::class);
}

/** @return array{0: Run, 1: WorkflowRun, 2: WorkflowTask} */
function prepareFailingFlowInterpreterTask(): array
{
    $flow = Flow::create(['name' => 'Durable failure', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flow, triggeredGraph([
        'start' => 'exit',
        'nodes' => [
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [],
    ]));

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1']);
    $engineRun = WorkflowRun::query()
        ->where('workflow_instance_id', $run->engine_workflow_id)
        ->sole();

    // The durable run still names FlowInterpreter, but this corrupted argument
    // forces its typed entrypoint to fail in the real RunWorkflowTask before it
    // can suspend. It is a compact way to exercise terminal event delivery.
    $engineRun->update([
        'arguments' => Serializer::serializeWithCodec((string) $engineRun->payload_codec, [
            'not-an-integer',
            1000,
            $run->engine_entry_node_id,
        ]),
    ]);

    $initial = WorkflowTask::query()
        ->where('workflow_run_id', $engineRun->id)
        ->where('task_type', TaskType::Workflow->value)
        ->where('status', TaskStatus::Ready->value)
        ->sole();

    return [$run, $engineRun, $initial];
}

/** @return array{0: Run, 1: WorkflowRun} */
function runFailingFlowInterpreterLifecycle(): array
{
    [$run, $engineRun, $task] = prepareFailingFlowInterpreterTask();
    app()->call([new RunWorkflowTask($task->id), 'handle']);

    return [$run, $engineRun];
}

it('projects a duplicate durable failure while the start handle has not yet been persisted', function () {
    $run = failureProjectionRun();
    $event = workflowFailureFor($run);

    Event::dispatch($event);

    $failed = Run::withoutTenancy()->findOrFail($run->id);
    $firstEndedAt = $failed->ended_at?->toIso8601String();
    $firstError = $failed->error;

    Event::dispatch($event);

    expect($failed->status)->toBe('failed')
        ->and($firstEndedAt)->toBe('2026-08-25T14:15:16+00:00')
        ->and($firstError)->toBe(RuntimeException::class.': Yaya remained unavailable');

    expect(Run::withoutTenancy()->findOrFail($run->id)->ended_at?->toIso8601String())->toBe($firstEndedAt)
        ->and(Run::withoutTenancy()->findOrFail($run->id)->error)->toBe($firstError);

    $subjects = RunSubject::where('run_id', $run->id)->orderBy('subject_id')->get()->keyBy('subject_id');

    expect($subjects['active-1']->status)->toBe('failed')
        ->and($subjects['active-1']->current_node_id)->toBeNull()
        ->and($subjects['active-1']->last_error)->toBe($firstError)
        ->and($subjects['active-2']->status)->toBe('failed')
        ->and($subjects['active-2']->current_node_id)->toBeNull()
        ->and($subjects['active-2']->last_error)->toBe($firstError)
        ->and($subjects['completed']->status)->toBe('completed');
});

it('projects a durable failure only when its persisted handle exactly matches the deterministic instance id', function () {
    $run = failureProjectionRun();
    $run->update(['engine_workflow_id' => "nodeflow-run:{$run->id}"]);

    Event::dispatch(workflowFailureFor($run));

    expect(Run::withoutTenancy()->findOrFail($run->id)->status)->toBe('failed');
});

it('ignores malformed, unrelated, mismatched, and non-nodeflow workflow failures', function () {
    $run = failureProjectionRun();
    $run->update(['engine_workflow_id' => 'nodeflow-run:another-run']);

    foreach ([
        workflowFailureFor($run, 'not-nodeflow'),
        workflowFailureFor($run, "nodeflow-run:0"),
        workflowFailureFor($run, "nodeflow-run:0{$run->id}"),
        workflowFailureFor($run, "nodeflow-run:{$run->id}x"),
        workflowFailureFor($run, 'nodeflow-run:999999999999999999999999999999'),
        workflowFailureFor($run, 'nodeflow-run:999999'),
        workflowFailureFor($run),
        workflowFailureFor($run, "nodeflow-run:{$run->id}", stdClass::class),
    ] as $event) {
        Event::dispatch($event);
    }

    $unchanged = Run::withoutTenancy()->findOrFail($run->id);
    $subjects = RunSubject::where('run_id', $run->id)->where('status', 'active')->get();

    expect($unchanged->status)->toBe('running')
        ->and($unchanged->ended_at)->toBeNull()
        ->and($unchanged->error)->toBeNull()
        ->and($subjects)->toHaveCount(2);
});

it('projects failures from every live run state', function (string $status) {
    $run = failureProjectionRun();
    $run->update(['status' => $status]);

    Event::dispatch(workflowFailureFor($run));

    expect(Run::withoutTenancy()->findOrFail($run->id)->status)->toBe('failed');
})->with(['pending', 'running', 'waiting', 'blocked']);

it('does not overwrite any terminal run after a durable failure is delivered', function (string $status) {
    $run = failureProjectionRun();
    $run->update([
        'status' => $status,
        'error' => 'original terminal error',
        'ended_at' => '2026-08-24 10:11:12',
    ]);

    Event::dispatch(workflowFailureFor($run));

    $unchanged = Run::withoutTenancy()->findOrFail($run->id);
    $active = RunSubject::where('run_id', $run->id)->where('subject_id', 'active-1')->firstOrFail();

    expect($unchanged->status)->toBe($status)
        ->and($unchanged->error)->toBe('original terminal error')
        ->and($unchanged->ended_at?->toIso8601String())->toBe('2026-08-24T10:11:12+00:00')
        ->and($active->status)->toBe('active')
        ->and($active->current_node_id)->toBe('send');
})->with(['completed', 'failed', 'cancelled']);

it('projects a deterministic cross-tenant run without selecting any ambient-tenant row', function () {
    $ambient = failureProjectionRun('org-1');
    $foreign = failureProjectionRun('org-2');

    Event::dispatch(workflowFailureFor($foreign));

    expect(Run::withoutTenancy()->findOrFail($foreign->id)->status)->toBe('failed')
        ->and(Run::withoutTenancy()->findOrFail($ambient->id)->status)->toBe('running');
});

it('bounds projected errors to portable text capacity without splitting valid utf8', function () {
    $run = failureProjectionRun();
    $message = str_repeat('€', 30_000);

    Event::dispatch(workflowFailureFor($run, message: $message));

    $error = (string) Run::withoutTenancy()->findOrFail($run->id)->error;

    expect(strlen($error))->toBeLessThanOrEqual(65_535)
        ->and(preg_match('//u', $error))->toBe(1)
        ->and($error)->toStartWith(RuntimeException::class.': ');
});

it('queues real durable failure projection after the workflow task transaction commits', function () {
    prepareFailureProjectionDurableRuntime($this);
    $projectionJobTransactionLevels = [];

    Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function () use (&$projectionJobTransactionLevels): void {
        $projectionJobTransactionLevels[] = DB::transactionLevel();
    });

    [$run, $engineRun] = runFailingFlowInterpreterLifecycle();

    expect(WorkflowRun::query()->findOrFail($engineRun->id)->status)->toBe(RunStatus::Failed)
        ->and(WorkflowFailure::query()->where('workflow_run_id', $engineRun->id)->count())->toBe(1)
        ->and(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $engineRun->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->count())->toBe(1)
        ->and(Run::withoutTenancy()->findOrFail($run->id)->status)->toBe('failed')
        ->and($projectionJobTransactionLevels)->toBe([0]);
});

it('does not emit a failure projection from a rolled-back transaction', function () {
    $run = failureProjectionRun();
    $statusBeforeRollback = null;

    try {
        DB::transaction(function () use ($run, &$statusBeforeRollback): void {
            Event::dispatch(workflowFailureFor($run));

            $statusBeforeRollback = Run::withoutTenancy()->findOrFail($run->id)->status;

            throw new RuntimeException('rollback event delivery');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('rollback event delivery');
    }

    expect($statusBeforeRollback)->toBe('running')
        ->and(Run::withoutTenancy()->findOrFail($run->id)->status)->toBe('running');
});

it('keeps durable terminal records committed when the queued projection fails', function () {
    prepareFailureProjectionDurableRuntime($this);
    [$run, $engineRun, $task] = prepareFailingFlowInterpreterTask();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER nodeflow_reject_failure_projection
        BEFORE UPDATE ON nodeflow_runs
        BEGIN
            SELECT RAISE(ABORT, 'projection blocked');
        END;
    SQL);

    try {
        expect(fn () => app()->call([new RunWorkflowTask($task->id), 'handle']))
            ->toThrow(\Illuminate\Database\QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER IF EXISTS nodeflow_reject_failure_projection');
    }

    expect((new ProjectWorkflowFailure)->tries)->toBe(3)
        ->and((new ProjectWorkflowFailure)->backoff)->toBe([5, 30, 120])
        ->and($engineRun->fresh()->status)->toBe(RunStatus::Failed)
        ->and(Run::withoutTenancy()->findOrFail($run->id)->status)->toBe('pending')
        ->and(WorkflowFailure::query()->where('workflow_run_id', $engineRun->id)->count())->toBe(1)
        ->and(WorkflowHistoryEvent::query()
            ->where('workflow_run_id', $engineRun->id)
            ->where('event_type', HistoryEventType::WorkflowFailed->value)
            ->count())->toBe(1);
});
