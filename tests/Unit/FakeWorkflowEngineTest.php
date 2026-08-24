<?php

use Nodeflow\Engine\FakeWorkflowEngine;

it('records starts, signals, and cancellations', function () {
    $engine = new FakeWorkflowEngine;

    $id = $engine->start('SomeWorkflow', ['run_id' => 1]);

    $engine->signal($id, 'subjectExited', [['7']]);
    $engine->cancel($id);

    expect($engine->started())->toBe([['workflow' => 'SomeWorkflow', 'args' => ['run_id' => 1], 'id' => $id]])
        ->and($engine->signals())->toBe([['id' => $id, 'method' => 'subjectExited', 'args' => [['7']]]])
        ->and($engine->cancelled())->toBe([$id])
        ->and($engine->isRunning($id))->toBeFalse();
});

it('reports a started workflow as running until cancelled', function () {
    $engine = new FakeWorkflowEngine;

    $id = $engine->start('SomeWorkflow', []);

    expect($engine->isRunning($id))->toBeTrue();
});

it('reuses a caller supplied workflow instance without recording a second execution', function () {
    $engine = new FakeWorkflowEngine;

    $first = $engine->start('SomeWorkflow', ['run_id' => 1], 'nodeflow-run:1');
    $retry = $engine->start('SomeWorkflow', ['run_id' => 1], 'nodeflow-run:1');

    expect($first)->toBe('nodeflow-run:1')
        ->and($retry)->toBe($first)
        ->and($engine->started())->toHaveCount(1);
});

it('preserves generated workflow ids when no deterministic instance is requested', function () {
    $engine = new FakeWorkflowEngine;

    expect($engine->start('SomeWorkflow', []))->toBe('fake-1')
        ->and($engine->start('SomeWorkflow', []))->toBe('fake-2')
        ->and($engine->started())->toHaveCount(2);
});
