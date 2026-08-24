<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string { return null; }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
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

    $run = app(TriggerRunStarter::class)->start($this->snapshot, new TriggerTenantMatch(
        tenantId: 'org-1',
        subjectType: 'user',
        subjectIds: [1, '2'],
        triggerData: ['delivery' => 'd-1'],
        occurrenceId: 'external-occurrence',
    ));

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

it('uses a fixed namespaced occurrence identity and leaves null occurrences non-idempotent', function () {
    $match = new TriggerTenantMatch('org-1', 'user', ['1'], [], str_repeat('x', 1000));
    $first = app(TriggerRunStarter::class)->start($this->snapshot, $match);
    $retry = app(TriggerRunStarter::class)->start($this->snapshot, $match);

    $otherSource = new TriggerActivationSnapshot(
        activationId: 42,
        flowId: $this->snapshot->flowId,
        flowVersionId: $this->snapshot->flowVersionId,
        tenantId: $this->snapshot->tenantId,
        driver: $this->snapshot->driver,
        source: 'test.other-source',
        qualifier: null,
        triggerNodeId: $this->snapshot->triggerNodeId,
        descriptor: [],
    );
    $differentNamespace = app(TriggerRunStarter::class)->start($otherSource, $match);
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
        ->and($differentNamespace->id)->not->toBe($first->id)
        ->and($differentNamespace->idempotency_key)->not->toBe($first->idempotency_key)
        ->and($withoutOccurrenceA->idempotency_key)->toBeNull()
        ->and($withoutOccurrenceB->id)->not->toBe($withoutOccurrenceA->id)
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(4);
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

    $first = app(TriggerRunStarter::class)->start(
        $firstSnapshot,
        new TriggerTenantMatch('org-1', 'user', ['1'], [], 'd'),
    );
    $second = app(TriggerRunStarter::class)->start(
        $secondSnapshot,
        new TriggerTenantMatch('org-1', 'user', ['1'], [], "c\0d"),
    );

    expect($second->id)->not->toBe($first->id)
        ->and($second->idempotency_key)->not->toBe($first->idempotency_key);
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

    expect(Run::withoutTenancy()->count())->toBe(0);
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

    foreach ([0, -1, 'bad'] as $invalid) {
        config()->set('nodeflow.limits.trigger_data_bytes', $invalid);
        expect(fn () => app(CreateRun::class)->forVersion($version, 'user', ['2'], 'old-entry', $options))
            ->toThrow(InvalidArgumentException::class, 'positive');
    }
});
