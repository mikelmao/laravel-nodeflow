<?php

use Nodeflow\Workflows\FlowInterpreter;
use Nodeflow\Workflows\Activities\CompleteRunActivity;
use Nodeflow\Workflows\Activities\LoadGraphActivity;
use Nodeflow\Workflows\Activities\ResolveRunEntryNodeActivity;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Workflow\Serializers\Serializer;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowDefinition;
use Workflow\V2\WorkflowStub;

function prepareWorkflowRuntime($test): void
{
    app()->register(Workflow\Providers\WorkflowServiceProvider::class);
    $test->artisan('migrate', [
        '--path' => realpath(__DIR__.'/../../vendor/durable-workflow/workflow/src/migrations'),
        '--realpath' => true,
    ])->assertSuccessful();
}

it('registers the audienceEmptied signal that SubjectExiter fires', function () {
    expect(WorkflowDefinition::hasSignal(FlowInterpreter::class, 'audienceEmptied'))->toBeTrue();
});

it('resolves a legacy trigger workflow entry before dispatching executable nodes', function () {
    prepareWorkflowRuntime($this);

    WorkflowStub::fake();
    WorkflowStub::mock(LoadGraphActivity::class, triggeredExitGraph());
    WorkflowStub::mock(ResolveRunEntryNodeActivity::class, 'first-action');
    WorkflowStub::mock(RunNodeActivity::class, []);
    WorkflowStub::mock(CompleteRunActivity::class, null);

    $workflow = WorkflowStub::make(FlowInterpreter::class, 'legacy-trigger-entry');
    $workflow->start(42);

    WorkflowStub::assertDispatched(ResolveRunEntryNodeActivity::class, fn (int $runId): bool => $runId === 42);
    WorkflowStub::assertDispatched(RunNodeActivity::class, fn (int $runId, string $nodeId): bool => $runId === 42 && $nodeId === 'first-action');
    WorkflowStub::assertNotDispatched(RunNodeActivity::class, fn (int $runId, string $nodeId): bool => $runId === 42 && $nodeId === 'trigger');
    expect($workflow->refresh()->completed())->toBeTrue();
});

it('keeps executable-only compatibility when a legacy workflow omits its entry', function () {
    prepareWorkflowRuntime($this);

    WorkflowStub::fake();
    WorkflowStub::mock(LoadGraphActivity::class, [
        'start' => 'legacy-start',
        'nodes' => [['id' => 'legacy-start', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);
    WorkflowStub::mock(ResolveRunEntryNodeActivity::class, 'legacy-start');
    WorkflowStub::mock(RunNodeActivity::class, []);
    WorkflowStub::mock(CompleteRunActivity::class, null);

    $workflow = WorkflowStub::make(FlowInterpreter::class, 'legacy-executable-entry');
    $workflow->start(43);

    WorkflowStub::assertDispatched(ResolveRunEntryNodeActivity::class, fn (int $runId): bool => $runId === 43);
    WorkflowStub::assertDispatched(RunNodeActivity::class, fn (int $runId, string $nodeId): bool => $runId === 43 && $nodeId === 'legacy-start');
    expect($workflow->refresh()->completed())->toBeTrue();
});

it('does not resolve an entry when the workflow start payload already supplied one', function () {
    prepareWorkflowRuntime($this);

    WorkflowStub::fake();
    WorkflowStub::mock(LoadGraphActivity::class, triggeredExitGraph());
    WorkflowStub::mock(ResolveRunEntryNodeActivity::class, 'wrong-entry');
    WorkflowStub::mock(RunNodeActivity::class, []);
    WorkflowStub::mock(CompleteRunActivity::class, null);

    $workflow = WorkflowStub::make(FlowInterpreter::class, 'explicit-trigger-entry');
    $workflow->start(44, 1000, 'first-action');

    WorkflowStub::assertNotDispatched(ResolveRunEntryNodeActivity::class);
    WorkflowStub::assertDispatched(RunNodeActivity::class, fn (int $runId, string $nodeId): bool => $runId === 44 && $nodeId === 'first-action');
    expect($workflow->refresh()->completed())->toBeTrue();
});

it('records a version marker before resolving a new missing-entry workflow', function () {
    $graph = triggeredExitGraph();
    $runner = WorkflowFiberRunner::forClass(
        FlowInterpreter::class,
        'new-missing-entry',
        'run-1',
        [42],
    );

    $load = $runner->step();
    expect($load->command['activity_type'])->toBe(LoadGraphActivity::class);

    $resolve = $runner->step($graph);

    expect(array_column($resolve->commands, 'type'))->toBe(['record_version_marker', 'schedule_activity'])
        ->and($resolve->commands[0]['change_id'])->toBe('nodeflow.resolve-missing-entry')
        ->and($resolve->commands[0]['version'])->toBe(1)
        ->and($resolve->command['activity_type'])->toBe(ResolveRunEntryNodeActivity::class);
});

it('cold replays pre-version history through the legacy graph start command shape', function () {
    $graph = triggeredExitGraph();
    $scheduled = WorkflowFiberRunner::forClass(
        FlowInterpreter::class,
        'legacy-missing-entry',
        'run-1',
        [42],
        'avro',
        [[
            'sequence' => 1,
            'event_type' => 'WorkflowStarted',
            'payload' => [
                'workflow_class' => FlowInterpreter::class,
                'workflow_type' => FlowInterpreter::class,
            ],
            'recorded_at' => '2026-08-24T00:00:00+00:00',
        ], [
            'sequence' => 2,
            'event_type' => 'ActivityCompleted',
            'payload' => [
                'sequence' => 1,
                'result' => Serializer::serializeWithCodec('avro', $graph),
                'payload_codec' => 'avro',
            ],
            'recorded_at' => '2026-08-24T00:00:01+00:00',
        ]],
    )->step();

    expect(array_column($scheduled->commands, 'type'))->toBe(['schedule_activity'])
        ->and($scheduled->command['activity_type'])->toBe(RunNodeActivity::class)
        ->and(Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']))->toBe([42, 'trigger']);
});

it('does not version or resolve a workflow whose payload has an explicit entry', function () {
    $runner = WorkflowFiberRunner::forClass(
        FlowInterpreter::class,
        'explicit-entry',
        'run-1',
        [42, 1000, 'first-action'],
    );

    $runner->step();
    $scheduled = $runner->step(triggeredExitGraph());

    expect(array_column($scheduled->commands, 'type'))->toBe(['schedule_activity'])
        ->and($scheduled->command['activity_type'])->toBe(RunNodeActivity::class)
        ->and(Serializer::unserializeWithCodec('avro', $scheduled->command['arguments']))->toBe([42, 'first-action']);
});
