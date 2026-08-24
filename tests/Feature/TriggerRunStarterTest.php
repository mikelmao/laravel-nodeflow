<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\CreateRun;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\TriggerActivationSnapshot;
use Nodeflow\Triggers\TriggerRunStarter;
use Nodeflow\Triggers\TriggerTenantMatch;

beforeEach(function () {
    $this->ownedSubjects = ['1', '2', '3'];
    $this->ownershipChecks = 0;

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string { return null; }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            $this->test->ownershipChecks++;

            return in_array($subjectId, $this->test->ownedSubjects, true);
        }
    });

    $this->flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Triggered', 'status' => 'draft']);
    $this->v1 = app(PublishFlow::class)->publish($this->flow, triggeredGraph([
        'start' => 'old-entry',
        'nodes' => [['id' => 'old-entry', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]))->version;

    $this->snapshot = new TriggerActivationSnapshot(
        activationId: 41,
        flowId: $this->flow->id,
        flowVersionId: $this->v1->id,
        tenantId: 'org-1',
        driver: 'test.fake',
        source: 'test.orders',
        qualifier: null,
        triggerNodeId: 'trigger',
        descriptor: [],
    );
});

it('starts the exact activation version at its executable entry with trigger origin data', function () {
    app(PublishFlow::class)->publish($this->flow->fresh(), triggeredGraph([
        'start' => 'new-entry',
        'nodes' => [['id' => 'new-entry', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    $match = new TriggerTenantMatch(
        tenantId: 'org-1',
        subjectType: 'user',
        subjectIds: [1, '2'],
        triggerData: ['delivery' => 'd-1'],
        occurrenceId: 'external-occurrence',
    );

    expect(iterator_to_array($match->subjectIds, false))->toBe(['1', '2']);

    $run = app(TriggerRunStarter::class)->start($this->snapshot, $match);

    expect($run->flow_version_id)->toBe($this->v1->id)
        ->and($run->started_via)->toBe('test.fake')
        ->and($run->trigger_node_id)->toBe('trigger')
        ->and($run->trigger_data)->toBe(['delivery' => 'd-1'])
        ->and($run->subjects()->pluck('current_node_id')->unique()->all())->toBe(['old-entry'])
        ->and($run->nodeExecutions()->count())->toBe(0)
        ->and($run->idempotency_key)->toHaveLength(64);
});

it('rejects a tenant mismatch and unowned normalized subjects before creating a run', function () {
    expect(fn () => app(TriggerRunStarter::class)->start(
        $this->snapshot,
        new TriggerTenantMatch('org-2', 'user', ['1']),
    ))->toThrow(InvalidArgumentException::class, 'tenant');

    expect(fn () => app(TriggerRunStarter::class)->start(
        $this->snapshot,
        new TriggerTenantMatch('org-1', 'user', [666]),
    ))->toThrow(CrossTenantSubjectException::class, '666');

    expect(Run::withoutTenancy()->count())->toBe(0);
});

it('rejects missing and mismatched activation version tuples', function (array $changes, string $message) {
    $snapshot = new TriggerActivationSnapshot(
        activationId: 41,
        flowId: $changes['flowId'] ?? $this->snapshot->flowId,
        flowVersionId: $changes['flowVersionId'] ?? $this->snapshot->flowVersionId,
        tenantId: $changes['tenantId'] ?? $this->snapshot->tenantId,
        driver: 'test.fake',
        source: 'test.orders',
        qualifier: null,
        triggerNodeId: $changes['triggerNodeId'] ?? $this->snapshot->triggerNodeId,
        descriptor: [],
    );

    expect(fn () => app(TriggerRunStarter::class)->start(
        $snapshot,
        new TriggerTenantMatch($snapshot->tenantId, 'user', ['1']),
    ))->toThrow($changes['exception'] ?? InvalidArgumentException::class, $message);

    expect(Run::withoutTenancy()->count())->toBe(0);
})->with([
    'missing version' => [['flowVersionId' => 999999, 'exception' => ModelNotFoundException::class], 'No query results'],
    'wrong flow' => [['flowId' => 999999], 'flow'],
    'wrong tenant' => [['tenantId' => 'org-2'], 'tenant'],
    'wrong trigger node' => [['triggerNodeId' => 'other'], 'trigger'],
]);

it('rejects activation routing metadata that differs from the pinned graph descriptor', function (array $changes) {
    $snapshot = new TriggerActivationSnapshot(
        activationId: 999_999,
        flowId: $this->snapshot->flowId,
        flowVersionId: $this->snapshot->flowVersionId,
        tenantId: $this->snapshot->tenantId,
        driver: $changes['driver'] ?? $this->snapshot->driver,
        source: $changes['source'] ?? $this->snapshot->source,
        qualifier: $changes['qualifier'] ?? $this->snapshot->qualifier,
        triggerNodeId: $this->snapshot->triggerNodeId,
        descriptor: $changes['descriptor'] ?? $this->snapshot->descriptor,
    );

    expect(fn () => app(TriggerRunStarter::class)->start(
        $snapshot,
        new TriggerTenantMatch('org-1', 'user', ['1']),
    ))->toThrow(InvalidArgumentException::class, 'pinned graph');

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->started())->toBe([])
        ->and($this->ownershipChecks)->toBe(0);
})->with([
    'driver' => [['driver' => 'forged.driver']],
    'source' => [['source' => 'forged.source']],
    'qualifier' => [['qualifier' => 'forged-qualifier']],
    'descriptor' => [['descriptor' => ['forged' => true]]],
]);

it('accepts recursively reordered descriptor object keys from JSON storage', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Reordered descriptor', 'status' => 'draft']);
    $version = app(PublishFlow::class)->publish($flow, triggeredExitGraph('test.orders', [
        'filters' => ['country' => 'RO', 'tier' => 'gold'],
        'rules' => [['field' => 'email', 'operator' => 'present']],
    ]))->version;
    $activation = new TriggerActivationSnapshot(
        activationId: 999_999,
        flowId: $flow->id,
        flowVersionId: $version->id,
        tenantId: 'org-1',
        driver: 'test.fake',
        source: 'test.orders',
        qualifier: null,
        triggerNodeId: 'trigger',
        descriptor: [
            'rules' => [['operator' => 'present', 'field' => 'email']],
            'filters' => ['tier' => 'gold', 'country' => 'RO'],
        ],
    );

    $run = app(TriggerRunStarter::class)->start(
        $activation,
        new TriggerTenantMatch('org-1', 'user', ['1']),
    );

    expect($run->flow_version_id)->toBe($version->id)
        ->and($run->engine_workflow_id)->not->toBeNull();
});

it('rejects descriptor value and list-order changes from the pinned graph', function (array $descriptor) {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Changed descriptor', 'status' => 'draft']);
    $version = app(PublishFlow::class)->publish($flow, triggeredExitGraph('test.orders', [
        'mode' => 'strict',
        'sequence' => ['first', 'second'],
    ]))->version;
    $activation = new TriggerActivationSnapshot(
        activationId: 999_999,
        flowId: $flow->id,
        flowVersionId: $version->id,
        tenantId: 'org-1',
        driver: 'test.fake',
        source: 'test.orders',
        qualifier: null,
        triggerNodeId: 'trigger',
        descriptor: $descriptor,
    );

    expect(fn () => app(TriggerRunStarter::class)->start(
        $activation,
        new TriggerTenantMatch('org-1', 'user', ['1']),
    ))->toThrow(InvalidArgumentException::class, 'pinned graph');
})->with([
    'changed scalar value' => [['mode' => 'relaxed', 'sequence' => ['first', 'second']]],
    'changed list order' => [['mode' => 'strict', 'sequence' => ['second', 'first']]],
]);

it('uses a fixed occurrence identity and leaves null occurrences non-idempotent', function () {
    $match = new TriggerTenantMatch('org-1', 'user', ['1'], [], str_repeat('x', 1000));
    $first = app(TriggerRunStarter::class)->start($this->snapshot, $match);
    $retry = app(TriggerRunStarter::class)->start($this->snapshot, $match);

    $withoutOccurrenceA = app(TriggerRunStarter::class)->start(
        $this->snapshot,
        new TriggerTenantMatch('org-1', 'user', ['1']),
    );
    $withoutOccurrenceB = app(TriggerRunStarter::class)->start(
        $this->snapshot,
        new TriggerTenantMatch('org-1', 'user', ['1']),
    );

    expect($retry->id)->toBe($first->id)
        ->and($first->idempotency_key)->toHaveLength(64)
        ->and($withoutOccurrenceA->idempotency_key)->toBeNull()
        ->and($withoutOccurrenceB->id)->not->toBe($withoutOccurrenceA->id)
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(3);
});

it('length-prefixes idempotency components so delimiter bytes cannot alias identities', function () {
    $firstSnapshot = new TriggerActivationSnapshot(
        activationId: 42,
        flowId: $this->snapshot->flowId,
        flowVersionId: $this->snapshot->flowVersionId,
        tenantId: $this->snapshot->tenantId,
        driver: 'a',
        source: "b\0c",
        qualifier: null,
        triggerNodeId: $this->snapshot->triggerNodeId,
        descriptor: [],
    );
    $secondSnapshot = new TriggerActivationSnapshot(
        activationId: 43,
        flowId: $this->snapshot->flowId,
        flowVersionId: $this->snapshot->flowVersionId,
        tenantId: $this->snapshot->tenantId,
        driver: 'a',
        source: 'b',
        qualifier: null,
        triggerNodeId: $this->snapshot->triggerNodeId,
        descriptor: [],
    );

    $starter = app(TriggerRunStarter::class);
    $method = new ReflectionMethod($starter, 'idempotencyKey');
    $first = $method->invoke($starter, $firstSnapshot, 'd');
    $second = $method->invoke($starter, $secondSnapshot, "c\0d");

    expect($first)->toHaveLength(64)
        ->and($second)->toHaveLength(64)
        ->and($second)->not->toBe($first);
});

it('allows an empty matched audience and still records the occurrence', function () {
    $run = app(TriggerRunStarter::class)->start(
        $this->snapshot,
        new TriggerTenantMatch('org-1', 'user', [], ['empty' => true], 'empty-1'),
    );

    expect($run->subjects()->count())->toBe(0)
        ->and($run->strategy)->toBe('cohort')
        ->and($run->trigger_data)->toBe(['empty' => true])
        ->and($run->engine_workflow_id)->not->toBeNull();
});

it('validates trigger data JSON and byte limits before creating anything', function () {
    config()->set('nodeflow.limits.trigger_data_bytes', 12);
    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) { $transactions++; });

    $version = FlowVersion::withoutTenancy()->findOrFail($this->v1->id);
    $base = [
        'started_via' => 'test.fake',
        'trigger_node_id' => 'trigger',
        'strategy' => 'cohort',
    ];

    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', [
        ...$base,
        'trigger_data' => 'not-an-array',
    ]))->toThrow(InvalidArgumentException::class, 'array');

    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', [
        ...$base,
        'trigger_data' => ["bad" => "\xB1\x31"],
    ]))->toThrow(InvalidArgumentException::class, 'JSON');

    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', [
        ...$base,
        'trigger_data' => ['value' => 'x'], // 13 encoded bytes
    ]))->toThrow(InvalidArgumentException::class, '12');

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and($transactions)->toBe(0);
});

it('enforces the actual default trigger data limit at the exact encoded byte boundary', function () {
    expect(config('nodeflow.limits.trigger_data_bytes'))->toBe(65_536);

    $version = FlowVersion::withoutTenancy()->findOrFail($this->v1->id);
    $overhead = strlen(json_encode(['data' => ''], JSON_THROW_ON_ERROR));
    $exact = ['data' => str_repeat('x', 65_536 - $overhead)];
    $tooLarge = ['data' => str_repeat('x', 65_537 - $overhead)];
    $options = [
        'started_via' => 'test.fake',
        'trigger_node_id' => 'trigger',
    ];

    $accepted = app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', [
        ...$options,
        'trigger_data' => $exact,
    ]);

    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) { $transactions++; });

    expect(strlen(json_encode($accepted->trigger_data, JSON_THROW_ON_ERROR)))->toBe(65_536);
    expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['2'], 'old-entry', [
        ...$options,
        'trigger_data' => $tooLarge,
    ]))->toThrow(InvalidArgumentException::class, '65537');

    expect($transactions)->toBe(0)
        ->and(Run::withoutTenancy()->count())->toBe(1);
});

it('recovers and starts a stranded committed winner of a real unique-key race once', function () {
    $version = FlowVersion::withoutTenancy()->findOrFail($this->v1->id);
    $armed = true;
    $winnerId = null;

    DB::listen(function (QueryExecuted $query) use (&$armed, &$winnerId, $version) {
        if (! $armed
            || ! str_starts_with(ltrim(strtolower($query->sql)), 'select')
            || ! str_contains($query->sql, 'nodeflow_runs')
            || ! str_contains($query->sql, 'idempotency_key')) {
            return;
        }

        // The preflight SELECT has already produced its empty result. Insert a
        // committed competing delivery before CreateRun opens its transaction,
        // so the production insert hits the database's real unique constraint.
        $armed = false;
        $now = now();
        $winnerId = DB::table('nodeflow_runs')->insertGetId([
            'flow_version_id' => $version->id,
            'tenant_id' => 'org-1',
            'engine_workflow_id' => null,
            'engine_entry_node_id' => 'old-entry',
            'engine_dispatch_status' => 'pending',
            'engine_dispatch_error' => null,
            'strategy' => 'subject',
            'status' => 'pending',
            'is_test' => false,
            'idempotency_key' => 'race-key',
            'started_via' => 'test.fake',
            'trigger_node_id' => 'trigger',
            'trigger_data' => json_encode(['winner' => true], JSON_THROW_ON_ERROR),
            'steps_taken' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('nodeflow_run_subjects')->insert([
            'run_id' => $winnerId,
            'subject_type' => 'user',
            'subject_id' => '2',
            'current_node_id' => 'old-entry',
            'status' => 'active',
        ]);
    });

    $winner = app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', [
        'started_via' => 'test.fake',
        'trigger_node_id' => 'trigger',
        'trigger_data' => ['candidate' => true],
        'idempotency_key' => 'race-key',
    ]);

    expect($winner->id)->toBe($winnerId)
        ->and($winner->engine_workflow_id)->toBe("nodeflow-run:{$winnerId}")
        ->and($winner->trigger_data)->toBe(['winner' => true])
        ->and($winner->subjects()->pluck('subject_id')->all())->toBe(['2'])
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1)
        ->and(app(WorkflowEngine::class)->started()[0]['id'])->toBe("nodeflow-run:{$winnerId}");
});

it('accepts trigger data at the exact encoded byte limit and rejects invalid limits', function () {
    $version = FlowVersion::withoutTenancy()->findOrFail($this->v1->id);
    $data = ['value' => 'é'];
    $bytes = strlen(json_encode($data, JSON_THROW_ON_ERROR));
    $options = [
        'started_via' => 'test.fake',
        'trigger_node_id' => 'trigger',
        'trigger_data' => $data,
    ];

    config()->set('nodeflow.limits.trigger_data_bytes', $bytes);
    $run = app(CreateRun::class)->forVersion($version, 'user', ['1'], 'old-entry', $options);
    expect($run->trigger_data)->toBe($data);

    $transactions = 0;
    Event::listen(TransactionBeginning::class, function () use (&$transactions) { $transactions++; });

    foreach ([0, -1, 'bad'] as $invalid) {
        config()->set('nodeflow.limits.trigger_data_bytes', $invalid);
        expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['2'], 'old-entry', $options))
            ->toThrow(InvalidArgumentException::class, 'positive');
    }

    expect($transactions)->toBe(0);
});
