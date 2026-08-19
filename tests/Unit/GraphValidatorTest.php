<?php

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeExitNode;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeWaitNode;

beforeEach(function () {
    $this->registry = new NodeRegistry;
    $this->registry->register(FakeSendNode::class, FakeExitNode::class, FakeWaitNode::class);
    $this->validator = new GraphValidator($this->registry);
});

it('passes a well formed graph', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]));

    expect($result->passes())->toBeTrue();
});

it('rejects an unknown node type', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'nope.missing', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('nope.missing');
});

it('rejects a cycle', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n2', 'output' => 'sent', 'to' => 'n1'],
        ],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('cycle');
});

it('rejects invalid node config', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'pigeon']]],
        'edges' => [],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('channel');
});

it('rejects an edge pointing at a missing node', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']]],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'ghost']],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('ghost');
});

it('rejects an edge on an output the node does not declare', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'exploded', 'to' => 'n2']],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('exploded');
});

it('warns when two branches of a split both contain waits', function () {
    $result = $this->validator->validate(Graph::fromArray([
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
    ]));

    expect($result->passes())->toBeTrue()
        ->and(implode(' ', $result->warnings()))->toContain('sequentially');
});
