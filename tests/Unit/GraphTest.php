<?php

use Nodeflow\Graph\Graph;

function sampleGraph(): array
{
    return [
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'failed', 'to' => 'n3'],
        ],
    ];
}

it('reads nodes, start, and edges', function () {
    $graph = Graph::fromArray(sampleGraph());

    expect($graph->startNodeId())->toBe('n1')
        ->and($graph->node('n1')['type'])->toBe('test.send')
        ->and($graph->nodeIds())->toBe(['n1', 'n2', 'n3']);
});

it('resolves edge targets by output name', function () {
    $graph = Graph::fromArray(sampleGraph());

    expect($graph->targetsFor('n1', 'sent'))->toBe(['n2'])
        ->and($graph->targetsFor('n1', 'failed'))->toBe(['n3'])
        ->and($graph->targetsFor('n1', 'nonexistent'))->toBe([])
        ->and($graph->targetsFor('n2', 'default'))->toBe([]);
});

it('round trips through toArray', function () {
    expect(Graph::fromArray(sampleGraph())->toArray())->toBe(sampleGraph());
});
