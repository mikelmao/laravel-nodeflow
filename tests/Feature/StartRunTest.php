<?php

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Models\InvalidFlowVersionReferenceException;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Workflows\FlowInterpreter;
use Tests\Support\FakeSendNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return $i !== '666'; }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->flow = Flow::create(['name' => 'F', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->flow, triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]));
});

it('creates a run pinned to the current version with subjects at the start node', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);

    expect($run->flow_version_id)->toBe($this->flow->fresh()->current_version_id)
        ->and($run->subjects()->count())->toBe(2)
        ->and($run->subjects()->first()->current_node_id)->toBe('n1')
        ->and($run->started_via)->toBe('manual')
        ->and($run->trigger_node_id)->toBe('trigger')
        ->and($run->trigger_data)->toBeNull()
        ->and($run->nodeExecutions()->count())->toBe(0)
        ->and($run->engine_workflow_id)->not->toBeNull()
        ->and($run->engine_entry_node_id)->toBe('n1')
        ->and($run->engine_dispatch_status)->toBe('dispatched')
        ->and($run->engine_dispatch_error)->toBeNull();
});

it('refuses a raw same-tenant current-version pointer to another flow', function () {
    $other = Flow::create(['name' => 'Other', 'status' => 'draft']);
    app(PublishFlow::class)->publish($other, triggeredExitGraph());
    $foreignVersionId = $other->fresh()->current_version_id;

    DB::table('nodeflow_flows')->where('id', $this->flow->id)->update([
        'current_version_id' => $foreignVersionId,
    ]);

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(InvalidFlowVersionReferenceException::class, 'does not belong to Flow');

    expect(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(0);
});

it('refuses a raw cross-tenant current version that still belongs to the flow', function () {
    $versionId = $this->flow->fresh()->current_version_id;

    DB::table('nodeflow_flow_versions')->where('id', $versionId)->update([
        'tenant_id' => 'org-2',
    ]);

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(CrossTenantExecutionException::class, 'Cross-tenant execution refused');

    expect(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(0);
});

it('starts the interpreter workflow with the run id', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    $started = app(WorkflowEngine::class)->started();

    expect($started[0]['workflow'])->toBe(FlowInterpreter::class)
        ->and($started[0]['args']['run_id'])->toBe($run->id)
        ->and($started[0]['args']['entry_node_id'])->toBe('n1');
});

it('starts the engine only after its own transaction has committed', function () {
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public array $transactionLevels = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->transactionLevels[] = DB::connection()->transactionLevel();

            return $instanceId ?? 'ordered-workflow';
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return true; }
    });

    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);
    $engine = app(WorkflowEngine::class);

    expect($engine->transactionLevels)->toBe([0])
        ->and($run->engine_workflow_id)->toBe("nodeflow-run:{$run->id}");
});

it('recovers an idempotent run after the first engine dispatch fails', function () {
    Queue::fake();
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public int $attempts = 0;
        public array $executions = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('secret broker credential was rejected');
            }

            $id = $instanceId ?? 'generated-'.$this->attempts;
            $this->executions[$id] ??= compact('workflowClass', 'args');

            return $id;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return isset($this->executions[$workflowId]); }
    });

    expect(fn () => app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'recover-dispatch'],
    ))->toThrow(RuntimeException::class, 'secret broker credential');

    $stranded = \Nodeflow\Models\Run::withoutTenancy()->firstOrFail();
    expect($stranded->engine_workflow_id)->toBeNull()
        ->and($stranded->engine_entry_node_id)->toBe('n1')
        ->and($stranded->engine_dispatch_status)->toBe('failed')
        ->and($stranded->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($stranded->engine_dispatch_error)->not->toContain('credential')
        ->and($stranded->error)->toBeNull();

    $recovered = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['2'],
        ['idempotency_key' => 'recover-dispatch'],
    );
    $engine = app(WorkflowEngine::class);

    expect($recovered->id)->toBe($stranded->id)
        ->and($recovered->engine_workflow_id)->toBe("nodeflow-run:{$stranded->id}")
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull()
        ->and($recovered->error)->toBeNull()
        ->and($recovered->subjects()->pluck('subject_id')->all())->toBe(['1'])
        ->and($engine->executions)->toHaveCount(1);
});

it('recovers the stable engine handle when persisting it failed after dispatch', function () {
    Queue::fake();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_idempotent_run_engine_handle
        BEFORE UPDATE OF engine_workflow_id ON nodeflow_runs
        WHEN NEW.engine_workflow_id IS NOT NULL
        BEGIN
            SELECT RAISE(FAIL, 'secret database write detail');
        END
        SQL);

    expect(fn () => app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'recover-handle'],
    ))->toThrow(RuntimeException::class, 'secret database write detail');

    $stranded = \Nodeflow\Models\Run::withoutTenancy()->firstOrFail();
    expect(app(WorkflowEngine::class)->started())->toHaveCount(1)
        ->and($stranded->engine_workflow_id)->toBeNull()
        ->and($stranded->engine_dispatch_status)->toBe('failed')
        ->and($stranded->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($stranded->error)->toBeNull();

    DB::unprepared('DROP TRIGGER fail_idempotent_run_engine_handle');

    $recovered = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['2'],
        ['idempotency_key' => 'recover-handle'],
    );

    expect($recovered->id)->toBe($stranded->id)
        ->and($recovered->engine_workflow_id)->toBe("nodeflow-run:{$stranded->id}")
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull()
        ->and($recovered->error)->toBeNull()
        ->and($recovered->subjects()->pluck('subject_id')->all())->toBe(['1'])
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1);
});

it('records and recovers a dispatch that fails after an ambient commit', function () {
    Queue::fake();
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public int $attempts = 0;
        public array $executions = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('secret after-commit dispatch detail');
            }

            $id = $instanceId ?? 'generated-'.$this->attempts;
            $this->executions[$id] ??= compact('workflowClass', 'args');

            return $id;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return isset($this->executions[$workflowId]); }
    });

    DB::beginTransaction();
    $created = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'recover-after-commit'],
    );

    DB::commit();

    $stranded = $created->fresh();
    expect($stranded->engine_workflow_id)->toBeNull()
        ->and($stranded->engine_dispatch_status)->toBe('failed')
        ->and($stranded->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($stranded->error)->toBeNull();

    $recovered = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['2'],
        ['idempotency_key' => 'recover-after-commit'],
    );

    expect($recovered->id)->toBe($stranded->id)
        ->and($recovered->engine_workflow_id)->toBe("nodeflow-run:{$stranded->id}")
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull()
        ->and($recovered->error)->toBeNull()
        ->and($recovered->subjects()->pluck('subject_id')->all())->toBe(['1'])
        ->and(app(WorkflowEngine::class)->executions)->toHaveCount(1);
});

it('never treats execution errors as dispatch state when an engine handle exists', function () {
    $run = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'clear-dispatch-sentinel'],
    );
    $run->update(['error' => 'Workflow dispatch failed; retry the idempotent run start.']);

    $recovered = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'clear-dispatch-sentinel'],
    );
    expect($recovered->error)->toBe('Workflow dispatch failed; retry the idempotent run start.')
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull();

    $recovered->update(['error' => 'Node execution failed']);
    $preserved = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'clear-dispatch-sentinel'],
    );

    expect($preserved->error)->toBe('Node execution failed');
});

it('returns the reconciled run after another actor persisted the stable handle', function () {
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            DB::table('nodeflow_runs')->where('id', $args['run_id'])->update([
                'engine_workflow_id' => $instanceId,
                'engine_dispatch_status' => 'failed',
                'engine_dispatch_error' => 'Workflow dispatch failed; recovery required.',
                'error' => 'Node execution failed concurrently.',
            ]);

            throw new RuntimeException('late losing callback failure');
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return true; }
    });

    $run = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'concurrent-handle'],
    );

    expect($run->engine_workflow_id)->toBe("nodeflow-run:{$run->id}")
        ->and($run->engine_dispatch_status)->toBe('dispatched')
        ->and($run->engine_dispatch_error)->toBeNull()
        ->and($run->error)->toBe('Node execution failed concurrently.');
});

it('defers engine start to an ambient transaction commit and synchronizes the returned run', function () {
    DB::beginTransaction();

    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    expect(app(WorkflowEngine::class)->started())->toBe([])
        ->and($run->engine_workflow_id)->toBeNull();

    DB::commit();

    expect(app(WorkflowEngine::class)->started())->toHaveCount(1)
        ->and($run->engine_workflow_id)->toBe("nodeflow-run:{$run->id}")
        ->and($run->fresh()->engine_workflow_id)->toBe("nodeflow-run:{$run->id}");
});

it('does not start or update the engine when an ambient transaction rolls back', function () {
    DB::beginTransaction();
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);
    DB::rollBack();

    expect(app(WorkflowEngine::class)->started())->toBe([])
        ->and($run->engine_workflow_id)->toBeNull()
        ->and(\Nodeflow\Models\Run::withoutTenancy()->find($run->id))->toBeNull();
});

it('can schedule the same idempotency key after an ambient rollback', function () {
    DB::beginTransaction();
    app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'retry-after-rollback'],
    );
    DB::rollBack();

    $retry = app(StartRun::class)->forFlow(
        $this->flow->fresh(),
        'user',
        ['1'],
        ['idempotency_key' => 'retry-after-rollback'],
    );

    expect(app(WorkflowEngine::class)->started())->toHaveCount(1)
        ->and($retry->engine_workflow_id)->toBe("nodeflow-run:{$retry->id}")
        ->and(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(1);
});

it('registers only one deferred start for an idempotent retry inside an ambient transaction', function () {
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public int $attempts = 0;

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->attempts++;

            return $instanceId ?? 'generated';
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return true; }
    });

    DB::beginTransaction();

    $first = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'outer-retry']);
    $retry = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['2'], ['idempotency_key' => 'outer-retry']);

    expect($retry->id)->toBe($first->id)
        ->and(app(WorkflowEngine::class)->attempts)->toBe(0);

    DB::commit();

    expect(app(WorkflowEngine::class)->attempts)->toBe(1)
        ->and($retry->engine_workflow_id)->toBe("nodeflow-run:{$first->id}")
        ->and($first->subjects()->pluck('subject_id')->all())->toBe(['1']);
});

it('marks a per-user run as the subject strategy automatically', function () {
    $cohort = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);
    $single = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['3']);

    expect($cohort->strategy)->toBe('cohort')
        ->and($single->strategy)->toBe('subject');
});

it('refuses to start when a subject fails the tenant check and creates no run subjects', function () {
    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '666']))
        ->toThrow(\Nodeflow\Execution\CrossTenantSubjectException::class);

    expect(\Nodeflow\Models\RunSubject::count())->toBe(0)
        ->and(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(0);
});

it('is idempotent for a repeated trigger identity', function () {
    $first = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);
    $second = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);

    expect($second->id)->toBe($first->id)
        ->and(\Nodeflow\Models\Run::count())->toBe(1);
});

it('rejects invalid idempotency keys before opening a transaction', function () {
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) { $transactions++; });

    foreach (['', str_repeat('x', 256), []] as $invalid) {
        expect(fn () => app(StartRun::class)->forFlow(
            $this->flow->fresh(),
            'user',
            ['1'],
            ['idempotency_key' => $invalid],
        ))->toThrow(InvalidArgumentException::class, 'idempotency_key');
    }

    expect($transactions)->toBe(0)
        ->and(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(0);
});

it('creates a run for a different tenant than the ambient one via the internal guard suspension', function () {
    // This file's beforeEach binds the ambient tenant to 'org-1'. Build the
    // org-2 flow with the ambient tenant switched to null just for this setup
    // step, so the fixture itself isn't blocked by the very guard being
    // tested — that guard only fires when the ambient tenant is non-null.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return null; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $otherTenantFlow = Flow::create(['tenant_id' => 'org-2', 'name' => 'Other', 'status' => 'draft']);

    app(PublishFlow::class)->publish($otherTenantFlow, triggeredGraph([
        'start' => 'o1',
        'nodes' => [['id' => 'o1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    // Switch back to a concrete, non-null ambient tenant — 'org-1' — that
    // differs from the flow's own tenant_id. Outside StartRun's internal
    // guard suspension this contradiction would throw CrossTenantWriteException
    // (see the next test); through StartRun it must succeed, because the run's
    // tenant_id comes from the trusted $flow->tenant_id, not from ambient or
    // request state, and StartRun is reacting to a system event with no
    // ambient tenant of its own.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $run = app(StartRun::class)->forFlow($otherTenantFlow->fresh(), 'user', ['1']);

    expect($run->tenant_id)->toBe('org-2')
        ->and($run->subjects()->count())->toBe(1);
});

it('still throws for a genuine contradicting write outside the guard suspension', function () {
    // Same ambient tenant ('org-1', from this file's beforeEach) as the test
    // above, but this is an ordinary, unsuspended model write directly — the
    // guard must still block it. This is what proves the suspension is scoped
    // to StartRun's own insert and does not leak into surrounding code.
    expect(fn () => Flow::create([
        'tenant_id' => 'org-2',
        'name' => 'Bad',
        'status' => 'draft',
    ]))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);
});
