<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\CreateRun;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Execution\StartRun;
use Nodeflow\Jobs\RetryRunDispatch;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Publishing\PublishFlow;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
    });

    $this->flow = Flow::create(['name' => 'Dispatch recovery', 'status' => 'draft']);
    app(PublishFlow::class)->publish($this->flow, triggeredGraph([
        'start' => 'entry',
        'nodes' => [['id' => 'entry', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));
});

it('queues and resumes a failed keyless run from its persisted entry intent', function () {
    Queue::fake();
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public bool $available = false;
        public array $executions = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            if (! $this->available) {
                throw new RuntimeException('secret broker outage');
            }

            $id = $instanceId ?? 'generated';
            $this->executions[$id] ??= compact('workflowClass', 'args');

            return $id;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return isset($this->executions[$workflowId]); }
    });

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(RuntimeException::class, 'secret broker outage');

    $failed = Run::withoutTenancy()->firstOrFail();
    expect($failed->idempotency_key)->toBeNull()
        ->and($failed->engine_entry_node_id)->toBe('entry')
        ->and($failed->engine_dispatch_status)->toBe('failed')
        ->and($failed->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($failed->engine_dispatch_error)->not->toContain('secret')
        ->and($failed->error)->toBeNull()
        ->and($failed->subjects()->pluck('subject_id')->all())->toBe(['1']);

    Queue::assertPushed(RetryRunDispatch::class, 1);

    $engine = app(WorkflowEngine::class);
    $engine->available = true;
    (new RetryRunDispatch($failed->id))->handle(app(CreateRun::class));

    $recovered = $failed->fresh();
    expect($recovered->engine_workflow_id)->toBe("nodeflow-run:{$failed->id}")
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull()
        ->and($recovered->error)->toBeNull()
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and($recovered->subjects()->pluck('subject_id')->all())->toBe(['1'])
        ->and($engine->executions)->toHaveCount(1);
});

it('recovers through the job when the stable engine handle write initially fails', function () {
    Queue::fake();
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER fail_run_engine_handle
        BEFORE UPDATE OF engine_workflow_id ON nodeflow_runs
        WHEN NEW.engine_workflow_id IS NOT NULL
        BEGIN
            SELECT RAISE(FAIL, 'secret handle persistence failure');
        END
        SQL);

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(\Illuminate\Database\QueryException::class, 'secret handle persistence failure');

    $failed = Run::withoutTenancy()->firstOrFail();
    expect($failed->engine_workflow_id)->toBeNull()
        ->and($failed->engine_entry_node_id)->toBe('entry')
        ->and($failed->engine_dispatch_status)->toBe('failed')
        ->and($failed->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1);
    Queue::assertPushed(RetryRunDispatch::class, 1);

    DB::unprepared('DROP TRIGGER fail_run_engine_handle');
    $failed->update(['error' => 'Node execution error must survive dispatch recovery.']);
    (new RetryRunDispatch($failed->id))->handle(app(CreateRun::class));

    $recovered = $failed->fresh();
    expect($recovered->engine_workflow_id)->toBe("nodeflow-run:{$failed->id}")
        ->and($recovered->engine_dispatch_status)->toBe('dispatched')
        ->and($recovered->engine_dispatch_error)->toBeNull()
        ->and($recovered->error)->toBe('Node execution error must survive dispatch recovery.')
        ->and($recovered->subjects()->count())->toBe(1)
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1);
});

it('lets deferred dispatch failure complete every host after-commit callback and event', function () {
    Queue::fake();
    $order = [];
    $reported = [];

    $engine = new class($order) implements WorkflowEngine
    {
        private array $order;

        public function __construct(array &$order) { $this->order = &$order; }

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->order[] = 'engine';

            throw new RuntimeException('deferred engine failure');
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return false; }
    };
    app()->instance(WorkflowEngine::class, $engine);
    app()->instance(ExceptionHandler::class, new class($reported, $order) implements ExceptionHandler
    {
        private array $reported;
        private array $order;

        public function __construct(array &$reported, array &$order)
        {
            $this->reported = &$reported;
            $this->order = &$order;
        }

        public function report(Throwable $e) { $this->reported[] = $e; $this->order[] = 'reported'; }
        public function shouldReport(Throwable $e) { return true; }
        public function render($request, Throwable $e) { throw $e; }
        public function renderForConsole($output, Throwable $e): void {}
    });
    DB::beginTransaction();
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);
    DB::afterCommit(function () use (&$order) { $order[] = 'host-after-commit'; });
    Event::listen(TransactionCommitted::class, function () use (&$order) { $order[] = 'committed-event'; });
    DB::commit();

    expect($order)->toBe(['engine', 'reported', 'host-after-commit', 'committed-event'])
        ->and($reported)->toHaveCount(1)
        ->and($reported[0]->getMessage())->toBe('deferred engine failure')
        ->and($run->engine_dispatch_status)->toBe('failed')
        ->and($run->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($run->error)->toBeNull();
    Queue::assertPushed(RetryRunDispatch::class, 1);
});

it('synchronizes a deferred returned run after the sync queue recovers it inline', function () {
    config()->set('queue.default', 'sync');
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public int $attempts = 0;
        public array $executions = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('first deferred attempt fails');
            }

            $id = $instanceId ?? 'generated';
            $this->executions[$id] ??= compact('workflowClass', 'args');

            return $id;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return isset($this->executions[$workflowId]); }
    });

    DB::beginTransaction();
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);
    DB::commit();

    expect($run->engine_workflow_id)->toBe("nodeflow-run:{$run->id}")
        ->and($run->engine_dispatch_status)->toBe('dispatched')
        ->and($run->engine_dispatch_error)->toBeNull()
        ->and($run->error)->toBeNull()
        ->and(app(WorkflowEngine::class)->attempts)->toBe(2)
        ->and(app(WorkflowEngine::class)->executions)->toHaveCount(1);
});

it('returns an immediately recovered keyless run when the sync queue succeeds inline', function () {
    config()->set('queue.default', 'sync');
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public int $attempts = 0;
        public array $executions = [];

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->attempts++;

            if ($this->attempts === 1) {
                throw new RuntimeException('first immediate attempt fails');
            }

            $id = $instanceId ?? 'generated';
            $this->executions[$id] ??= compact('workflowClass', 'args');

            return $id;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return isset($this->executions[$workflowId]); }
    });

    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    expect($run->engine_workflow_id)->toBe("nodeflow-run:{$run->id}")
        ->and($run->engine_entry_node_id)->toBe('entry')
        ->and($run->engine_dispatch_status)->toBe('dispatched')
        ->and($run->engine_dispatch_error)->toBeNull()
        ->and($run->subjects()->pluck('subject_id')->all())->toBe(['1'])
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and(app(WorkflowEngine::class)->attempts)->toBe(2)
        ->and(app(WorkflowEngine::class)->executions)->toHaveCount(1);
});

it('relies on queue retries without recursively queuing from the job', function () {
    Queue::fake();
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            throw new RuntimeException('engine remains unavailable');
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return false; }
    });

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(RuntimeException::class, 'engine remains unavailable');
    $failed = Run::withoutTenancy()->firstOrFail();

    expect(fn () => (new RetryRunDispatch($failed->id))->handle(app(CreateRun::class)))
        ->toThrow(RuntimeException::class, 'engine remains unavailable');

    Queue::assertPushed(RetryRunDispatch::class, 1);
    $job = new RetryRunDispatch($failed->id);
    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60]);
});

it('reports queue scheduling failure without replacing the original start failure', function () {
    $reported = [];
    app()->instance(ExceptionHandler::class, new class($reported) implements ExceptionHandler
    {
        private array $reported;

        public function __construct(array &$reported) { $this->reported = &$reported; }
        public function report(Throwable $e) { $this->reported[] = $e; }
        public function shouldReport(Throwable $e) { return true; }
        public function render($request, Throwable $e) { throw $e; }
        public function renderForConsole($output, Throwable $e): void {}
    });
    app()->singleton(WorkflowEngine::class, fn () => new class implements WorkflowEngine
    {
        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            throw new RuntimeException('original engine start failure');
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}
        public function cancel(string $workflowId): void {}
        public function isRunning(string $workflowId): bool { return false; }
    });
    Queue::shouldReceive('push')
        ->once()
        ->andThrow(new RuntimeException('secret queue scheduling failure'));

    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']))
        ->toThrow(RuntimeException::class, 'original engine start failure');

    $run = Run::withoutTenancy()->firstOrFail();
    expect($reported)->toHaveCount(1)
        ->and($reported[0]->getMessage())->toBe('secret queue scheduling failure')
        ->and($run->engine_dispatch_status)->toBe('failed')
        ->and($run->engine_dispatch_error)->toBe('Workflow dispatch failed; recovery required.')
        ->and($run->engine_dispatch_error)->not->toContain('queue')
        ->and($run->error)->toBeNull();
});

it('validates persisted resume intent and the pinned run-version tenant tuple', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);
    DB::table('nodeflow_runs')->where('id', $run->id)->update([
        'engine_workflow_id' => null,
        'engine_entry_node_id' => null,
        'engine_dispatch_status' => 'failed',
    ]);

    expect(fn () => app(CreateRun::class)->resume($run->id))
        ->toThrow(InvalidArgumentException::class, 'persisted engine entry');

    DB::table('nodeflow_runs')->where('id', $run->id)->update(['engine_entry_node_id' => 'entry']);
    DB::table('nodeflow_flow_versions')->where('id', $run->flow_version_id)->update(['tenant_id' => 'org-2']);

    expect(fn () => app(CreateRun::class)->resume($run->id))
        ->toThrow(CrossTenantExecutionException::class, 'Cross-tenant execution refused');
});

it('rejects a caller-supplied entry that is not the pinned graph entry before a transaction', function () {
    $version = $this->flow->currentVersion()->firstOrFail();
    $transactions = 0;
    Event::listen(\Illuminate\Database\Events\TransactionBeginning::class, function () use (&$transactions) {
        $transactions++;
    });

    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['1'], 'trigger', [
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
    ]))->toThrow(InvalidArgumentException::class, 'does not match pinned graph entry [entry]');

    expect($transactions)->toBe(0)
        ->and(Run::withoutTenancy()->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->started())->toBe([]);
});

it('validates executable-only entries through the registered graph catalog', function () {
    [$version] = TenancyGuardSuspension::run(function () {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => 'org-1',
            'name' => 'Legacy executable-only flow',
            'status' => 'active',
        ]);
        $graph = [
            'start' => 'legacy-start',
            'nodes' => [['id' => 'legacy-start', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ];
        $version = FlowVersion::withoutTenancy()->create([
            'tenant_id' => 'org-1',
            'flow_id' => $flow->id,
            'version' => 1,
            'graph' => $graph,
            'content_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
        ]);

        return [$version];
    });

    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['1'], 'other', [
        'started_via' => 'manual',
        'trigger_node_id' => 'legacy-start',
        'trigger_data' => null,
    ]))->toThrow(InvalidArgumentException::class, 'pinned graph entry [legacy-start]');

    $run = app(CreateRun::class)->forVersion($version, 'user', ['1'], 'legacy-start', [
        'started_via' => 'manual',
        'trigger_node_id' => 'legacy-start',
        'trigger_data' => null,
    ]);

    expect($run->engine_entry_node_id)->toBe('legacy-start')
        ->and(app(WorkflowEngine::class)->started()[0]['args']['entry_node_id'])->toBe('legacy-start');
});

it('rejects raw-corrupted recovery entries before engine dispatch or queue recursion', function (string $entry) {
    Queue::fake();
    $version = $this->flow->currentVersion()->firstOrFail();
    $runId = DB::table('nodeflow_runs')->insertGetId([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'engine_entry_node_id' => 'entry',
        'engine_dispatch_status' => 'failed',
        'strategy' => 'subject',
        'status' => 'pending',
        'is_test' => false,
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'steps_taken' => 0,
    ]);
    DB::table('nodeflow_runs')->where('id', $runId)->update(['engine_entry_node_id' => $entry]);

    expect(fn () => app(CreateRun::class)->resume($runId))
        ->toThrow(InvalidArgumentException::class, 'does not match pinned graph entry [entry]');

    expect(app(WorkflowEngine::class)->started())->toBe([]);
    Queue::assertNothingPushed();
})->with(['trigger node' => 'trigger', 'wrong node' => 'missing-entry']);

it('freezes the persisted engine entry intent on eloquent updates', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    expect(fn () => $run->update(['engine_entry_node_id' => 'trigger']))
        ->toThrow(LogicException::class, 'engine entry intent is immutable');

    expect($run->fresh()->engine_entry_node_id)->toBe('entry');
});
