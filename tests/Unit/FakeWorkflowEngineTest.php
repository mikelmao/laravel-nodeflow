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
