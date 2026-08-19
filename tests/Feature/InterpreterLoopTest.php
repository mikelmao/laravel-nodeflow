<?php

use Nodeflow\Execution\InterpreterLoop;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;

function drive(Graph $graph, array $responses, int $maxSteps = 100): array
{
    $loop = (new InterpreterLoop)->steps($graph, $maxSteps);
    $seen = [];

    while ($loop->valid()) {
        $step = $loop->current();
        $seen[] = $step;

        $loop->send($step instanceof RunNodeStep ? array_shift($responses) : null);
    }

    return $seen;
}

it('walks a linear graph node by node', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $steps = drive($graph, [['n2'], []]);

    expect($steps)->toHaveCount(2)
        ->and($steps[0])->toBeInstanceOf(RunNodeStep::class)
        ->and($steps[0]->nodeId)->toBe('n1')
        ->and($steps[1]->nodeId)->toBe('n2');
});

it('emits a wait step before running a wait node', function () {
    $graph = Graph::fromArray([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']]],
        'edges' => [],
    ]);

    $steps = drive($graph, [[]]);

    expect($steps[0])->toBeInstanceOf(WaitStep::class)
        ->and($steps[0]->duration)->toBe('1 day')
        ->and($steps[1])->toBeInstanceOf(RunNodeStep::class)
        ->and($steps[1]->nodeId)->toBe('w1');
});

it('stops when no node holds subjects', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => []]],
        'edges' => [],
    ]);

    expect(drive($graph, [[]]))->toHaveCount(1);
});

it('deduplicates a cursor where two branches converge', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'core.condition', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'yes', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'no', 'to' => 'n2'],
        ],
    ]);

    $steps = drive($graph, [['n2', 'n2'], []]);

    expect($steps)->toHaveCount(2);
});

it('stops at the max step guard', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => []]],
        'edges' => [],
    ]);

    $steps = drive($graph, array_fill(0, 10, ['n1']), maxSteps: 3);

    expect($steps)->toHaveCount(3);
});
