<?php

use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
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
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F2', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h2']);
    $finishedRun = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'completed', 'engine_workflow_id' => 'wf-2',
    ]);

    RunSubject::create(['run_id' => $finishedRun->id, 'subject_type' => 'user', 'subject_id' => '9', 'current_node_id' => null, 'status' => 'active']);

    app(SubjectExiter::class)->exit($finishedRun, ['9']);

    expect(RunSubject::where('run_id', $finishedRun->id)->where('subject_id', '9')->first()->status)->toBe('exited')
        ->and(app(WorkflowEngine::class)->signals())->toBe([]);
});
