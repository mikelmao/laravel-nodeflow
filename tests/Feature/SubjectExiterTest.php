<?php

use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'waiting', 'engine_workflow_id' => 'wf-1',
    ]);

    foreach (['1', '2'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'w1', 'status' => 'active']);
    }
});

it('marks a subject exited without signalling while others remain', function () {
    app(SubjectExiter::class)->exit($this->run, ['1']);

    expect(RunSubject::where('subject_id', '1')->first()->status)->toBe('exited')
        ->and(app(WorkflowEngine::class)->signals())->toBe([]);
});

it('signals the workflow exactly once when the last subject exits', function () {
    app(SubjectExiter::class)->exit($this->run, ['1']);
    app(SubjectExiter::class)->exit($this->run, ['2']);

    $signals = app(WorkflowEngine::class)->signals();

    expect($signals)->toHaveCount(1)
        ->and($signals[0]['id'])->toBe('wf-1')
        ->and($signals[0]['method'])->toBe('audienceEmptied');
});

it('records the exit but sends no signal for a run that has already finished', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F2', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h2']);
    $finishedRun = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'completed', 'engine_workflow_id' => 'wf-2',
    ]);

    RunSubject::create(['run_id' => $finishedRun->id, 'subject_type' => 'user', 'subject_id' => '9', 'current_node_id' => null, 'status' => 'active']);

    app(SubjectExiter::class)->exit($finishedRun, ['9']);

    expect(RunSubject::where('run_id', $finishedRun->id)->where('subject_id', '9')->first()->status)->toBe('exited')
        ->and(app(WorkflowEngine::class)->signals())->toBe([]);
});

it('can still reach audience-empty after a node released part of the cohort', function () {
    // The D10 / spec 7.3 consequence of Fix 2, asserted directly. Subject '1' is
    // handed to a sub-flow by a node returning NodeResult::empty(); '2' stays in
    // this flow and later exits. Before the reconciliation sweep, '1' kept
    // status='active' forever, activeSubjectCount() could never reach 0, and
    // audienceEmptied never fired — so every later cohort wait burned its full
    // timer instead of waking early. If this test regresses, that is back.
    Nodeflow::register([\Tests\Support\FakeEmptyAudienceNode::class]);

    $graph = Graph::fromArray([
        'start' => 'sf',
        'nodes' => [['id' => 'sf', 'type' => 'test.empty-audience', 'config' => []]],
        'edges' => [],
    ]);

    RunSubject::where('run_id', $this->run->id)->where('subject_id', '1')->update(['current_node_id' => 'sf']);

    app(NodeRunner::class)->run($this->run, $graph, 'sf');

    expect($this->run->fresh()->activeSubjectCount())->toBe(1)
        ->and(app(WorkflowEngine::class)->signals())->toBe([]);

    app(SubjectExiter::class)->exit($this->run, ['2']);

    expect($this->run->fresh()->activeSubjectCount())->toBe(0)
        ->and(app(WorkflowEngine::class)->signals())->toHaveCount(1)
        ->and(app(WorkflowEngine::class)->signals()[0]['method'])->toBe('audienceEmptied');
});
