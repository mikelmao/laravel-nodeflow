<?php

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeSendNode;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;

beforeEach(function () {
    Nodeflow::register([FakeSendNode::class]);
    $this->validator = app(GraphValidator::class);
});

it('passes a well formed graph', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ])));

    expect($result->passes())->toBeTrue();
});

it('rejects an unknown node type', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'nope.missing', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('nope.missing');
});

it('returns structured errors for an unresolved executable alias with an outgoing edge', function () {
    app(NodeRegistry::class)->alias('legacy.missing', 'canonical.missing');

    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'legacy.missing', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'default', 'to' => 'n2']],
    ])));

    expect($result->passes())->toBeFalse()
        ->and($result->nodeErrors())->toContain([
            'node' => 'n1',
            'field' => null,
            'message' => 'Node [n1] uses unknown type [legacy.missing].',
        ]);
});

it('rejects a cycle', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n2', 'output' => 'sent', 'to' => 'n1'],
        ],
    ])));

    $errors = implode(' ', $result->errors());

    expect($result->passes())->toBeFalse()
        ->and($errors)->toContain('cycle')
        ->and($errors)->toContain('n1')
        ->and($errors)->toContain('n2');
});

it('rejects invalid node config', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'pigeon']]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('channel');
});

it('rejects an edge pointing at a missing node', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']]],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'ghost']],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('ghost');
});

it('rejects an edge on an output the node does not declare', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'exploded', 'to' => 'n2']],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('exploded');
});

it('warns when two branches of a branching node both contain waits', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
            ['id' => 'w2', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'w1'],
            ['from' => 'n1', 'output' => 'failed', 'to' => 'w2'],
        ],
    ])));

    expect($result->passes())->toBeTrue()
        ->and(implode(' ', $result->warnings()))->toContain('sequentially');
});

it('rejects a graph with no start node set', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => '',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('start');
});

it('rejects a graph whose start node does not exist', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'ghost',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('ghost');
});

it('rejects duplicate node ids', function () {
    $result = $this->validator->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n1', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [],
    ])));

    $errors = implode(' ', $result->errors());

    expect($result->passes())->toBeFalse()
        ->and($errors)->toContain('n1')
        ->and($errors)->toContain('unique');
});

it('rejects two edges from the same output', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n3'],
        ],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('more than one outgoing edge');
});

it('reports every problem in a graph with several simultaneous issues', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'nope.missing', 'config' => []],
            ['id' => 'n2', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n2', 'output' => 'sent', 'to' => 'n1'],
            ['from' => 'n1', 'output' => 'sent', 'to' => 'ghost'],
        ],
    ]));

    expect($result->passes())->toBeFalse();
    expect(count($result->errors()))->toBeGreaterThanOrEqual(3);

    $errors = implode(' ', $result->errors());

    expect($errors)->toContain('nope.missing')
        ->and($errors)->toContain('cycle')
        ->and($errors)->toContain('ghost');
});

it('rejects a graph referencing a node type that implements neither cardinality interface', function () {
    // Belt and braces for the NodeRegistry::register() guard: a node reaching the
    // registry by some path that bypasses that check still cannot be published.
    $registry = new NodeRegistry;

    // Bypass register() deliberately - this is the situation the rule exists for.
    (function () {
        $this->types['test.no-cardinality'] = Tests\Support\FakeNoCardinalityNode::class;
    })->call($registry);

    $types = app(GraphTypeCatalog::class);
    $types->claim('test.no-cardinality', 'executable', Tests\Support\FakeNoCardinalityNode::class);

    $result = (new GraphValidator(
        $registry,
        app(TriggerNodeRegistry::class),
        app(TriggerSourceRegistry::class),
        $types,
    ))->validate(Graph::fromArray(triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.no-cardinality', 'config' => []]],
        'edges' => [],
    ])));

    $errors = implode(' ', $result->errors());

    expect($result->passes())->toBeFalse()
        ->and($errors)->toContain('n1')
        ->and($errors)->toContain('HandlesSubject')
        ->and($errors)->toContain('HandlesAudience');
});
