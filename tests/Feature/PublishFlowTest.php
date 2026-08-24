<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeRetryingAudienceNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Nodeflow::register([FakeSendNode::class, FakeRetryingAudienceNode::class]);

    $this->flow = Flow::create(['name' => 'F', 'status' => 'draft']);

    $this->validGraph = triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);
});

it('freezes version 1 and points the flow at it', function () {
    $version = app(PublishFlow::class)->publish($this->flow, $this->validGraph, 'user-9')->version;

    expect($version->version)->toBe(1)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->published_by)->toBe('user-9')
        ->and($this->flow->fresh()->current_version_id)->toBe($version->id);
});

it('increments the version on each publish and leaves earlier versions untouched', function () {
    $first = app(PublishFlow::class)->publish($this->flow, $this->validGraph)->version;
    $second = app(PublishFlow::class)->publish($this->flow, $this->validGraph)->version;

    expect($second->version)->toBe(2)
        ->and($first->fresh()->graph['nodes'][1]['runtime']['activity'])->toBe([
            'max_attempts' => 3,
            'backoff' => [1, 2, 5, 10, 15, 30, 60, 120],
            'start_to_close_timeout' => null,
            'non_retryable_error_types' => [],
        ])
        ->and($first->content_hash)->toMatch('/^[a-f0-9]{64}$/')
        ->and($first->content_hash)->toBe($second->content_hash);
});

it('persists activity policy snapshots for executable nodes without changing trigger metadata', function () {
    $graph = triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            [
                'id' => 'retrying',
                'type' => 'test.retrying-audience',
                'config' => [],
                'runtime' => ['trace_id' => 'author-trace', 'activity' => ['max_attempts' => 99]],
            ],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ], triggerConfig: ['keep' => 'trigger-descriptor']);

    $version = app(PublishFlow::class)->publish($this->flow, $graph)->version;
    $nodes = collect($version->graph['nodes'])->keyBy('id');

    expect($nodes['retrying']['runtime'])->toBe([
        'trace_id' => 'author-trace',
        'activity' => [
            'max_attempts' => 5,
            'backoff' => [1, 5, 30, 120],
            'start_to_close_timeout' => 90,
            'non_retryable_error_types' => [\InvalidArgumentException::class],
        ],
    ])
        ->and($nodes['trigger'])->not->toHaveKey('runtime')
        ->and($version->fresh()->activation->descriptor)->toBe(['keep' => 'trigger-descriptor'])
        ->and($version->content_hash)->toBe(hash('sha256', json_encode($version->graph, JSON_THROW_ON_ERROR)));
});

it('resolves executable aliases before snapshotting their activity policies', function () {
    app(NodeRegistry::class)->alias('test.legacy-retrying-audience', FakeRetryingAudienceNode::type());

    $graph = triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            ['id' => 'retrying', 'type' => 'test.legacy-retrying-audience', 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ]);

    $version = app(PublishFlow::class)->publish($this->flow, $graph)->version;
    $retrying = collect($version->graph['nodes'])->firstWhere('id', 'retrying');

    expect($retrying['type'])->toBe('test.legacy-retrying-audience')
        ->and($retrying['runtime']['activity'])->toBe([
            'max_attempts' => 5,
            'backoff' => [1, 5, 30, 120],
            'start_to_close_timeout' => 90,
            'non_retryable_error_types' => [\InvalidArgumentException::class],
        ]);
});

it('keeps each published policy snapshot when node defaults change', function () {
    $graph = triggeredGraph([
        'start' => 'retrying',
        'nodes' => [
            ['id' => 'retrying', 'type' => 'test.retrying-audience', 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'retrying', 'output' => 'accepted', 'to' => 'exit']],
    ]);

    $first = app(PublishFlow::class)->publish($this->flow, $graph)->version;

    app()->bind(FakeRetryingAudienceNode::class, fn () => new class extends FakeRetryingAudienceNode
    {
        public int $tries = 7;
    });

    $second = app(PublishFlow::class)->publish($this->flow, $graph)->version;
    $firstRetrying = collect($first->fresh()->graph['nodes'])->firstWhere('id', 'retrying');
    $secondRetrying = collect($second->graph['nodes'])->firstWhere('id', 'retrying');

    expect($firstRetrying['runtime']['activity']['max_attempts'])->toBe(5)
        ->and($secondRetrying['runtime']['activity']['max_attempts'])->toBe(7)
        ->and($second->content_hash)->not->toBe($first->content_hash);
});

it('refuses to publish an invalid graph', function () {
    $invalid = $this->validGraph;
    $invalid['nodes'][0]['config'] = ['channel' => 'pigeon'];

    expect(fn () => app(PublishFlow::class)->publish($this->flow, $invalid))
        ->toThrow(GraphInvalidException::class);
});

it('leaves runs on the previous version untouched when a new one is published', function () {
    $v1 = app(PublishFlow::class)->publish($this->flow, $this->validGraph)->version;

    $run = Run::create([
        'flow_version_id' => $v1->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    app(PublishFlow::class)->publish($this->flow, $this->validGraph);

    expect($run->fresh()->flow_version_id)->toBe($v1->id)
        ->and($v1->fresh()->hasLiveRuns())->toBeTrue();
});

it('publishes a graph with concurrent branch waits despite the warning', function () {
    $graphWithConcurrentWaits = triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
            ['id' => 'w2', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'w1'],
            ['from' => 'n1', 'output' => 'failed', 'to' => 'w2'],
            ['from' => 'w1', 'output' => 'default', 'to' => 'n2'],
            ['from' => 'w2', 'output' => 'default', 'to' => 'n2'],
        ],
    ]);

    // Assert that this graph genuinely emits a warning
    $validationResult = app(GraphValidator::class)->validate(Graph::fromArray($graphWithConcurrentWaits));
    expect($validationResult->passes())->toBeTrue()
        ->and(implode(' ', $validationResult->warnings()))->toContain('sequentially');

    // Assert that publishing succeeds despite the warning
    $version = app(PublishFlow::class)->publish($this->flow, $graphWithConcurrentWaits)->version;

    expect($version->version)->toBe(1)
        ->and($version->published_at)->not->toBeNull()
        ->and($this->flow->fresh()->current_version_id)->toBe($version->id);
});

it('refuses to publish a wait whose duration the engine cannot parse', function () {
    // The error must reach the person who can fix it: the author at publish
    // time, not a real customer at send time.
    $graph = triggeredGraph([
        'start' => 'w1',
        'nodes' => [
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 dya']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'w1', 'output' => 'default', 'to' => 'n2']],
    ]);

    expect(fn () => app(PublishFlow::class)->publish($this->flow, $graph))
        ->toThrow(GraphInvalidException::class);

    expect($this->flow->fresh()->current_version_id)->toBeNull();
});

it('refuses to publish a wait whose duration resolves to zero seconds', function () {
    // "banana" parses without an exception and yields 0 seconds, which would make
    // a day-2 message send immediately.
    $graph = triggeredGraph([
        'start' => 'w1',
        'nodes' => [
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => 'banana']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'w1', 'output' => 'default', 'to' => 'n2']],
    ]);

    try {
        app(PublishFlow::class)->publish($this->flow, $graph);
        $errors = [];
    } catch (GraphInvalidException $e) {
        $errors = $e->errors();
    }

    expect(implode(' ', $errors))->toContain('duration');
});

it('publishes a wait with a duration the engine can parse', function () {
    $graph = triggeredGraph([
        'start' => 'w1',
        'nodes' => [
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'w1', 'output' => 'default', 'to' => 'n2']],
    ]);

    expect(app(PublishFlow::class)->publish($this->flow, $graph)->version->version)->toBe(1);
});
