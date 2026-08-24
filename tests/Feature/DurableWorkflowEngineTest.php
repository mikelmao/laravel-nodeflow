<?php

use Nodeflow\Engine\DurableWorkflowEngine;
use Nodeflow\Workflows\FlowInterpreter;
use Workflow\Providers\WorkflowServiceProvider;
use Workflow\V2\Models\WorkflowInstance;
use Workflow\V2\Models\WorkflowRun;

function prepareDurableEngineRuntime($test): void
{
    app()->register(WorkflowServiceProvider::class);
    $test->artisan('migrate', [
        '--path' => realpath(__DIR__.'/../../vendor/durable-workflow/workflow/src/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
    config()->set('workflows.v2.task_dispatch_mode', 'poll');
}

it('returns the existing active workflow for a deterministic instance id', function () {
    prepareDurableEngineRuntime($this);
    $engine = new DurableWorkflowEngine;
    $args = ['run_id' => 41, 'max_steps' => 1000, 'entry_node_id' => 'entry'];

    $first = $engine->start(FlowInterpreter::class, $args, 'nodeflow-run:41');
    $retry = $engine->start(FlowInterpreter::class, $args, 'nodeflow-run:41');

    expect($first)->toBe('nodeflow-run:41')
        ->and($retry)->toBe($first)
        ->and(WorkflowInstance::query()->count())->toBe(1)
        ->and(WorkflowRun::query()->count())->toBe(1);
});

it('still allocates distinct instances when no deterministic id is supplied', function () {
    prepareDurableEngineRuntime($this);
    $engine = new DurableWorkflowEngine;
    $args = ['run_id' => 42, 'max_steps' => 1000, 'entry_node_id' => 'entry'];

    $first = $engine->start(FlowInterpreter::class, $args);
    $second = $engine->start(FlowInterpreter::class, $args);

    expect($first)->not->toBe($second)
        ->and(WorkflowInstance::query()->count())->toBe(2)
        ->and(WorkflowRun::query()->count())->toBe(2);
});
