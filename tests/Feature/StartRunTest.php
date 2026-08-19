<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Workflows\FlowInterpreter;
use Tests\Support\FakeSendNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return $i !== '666'; }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->flow, [
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);
});

it('creates a run pinned to the current version with subjects at the start node', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);

    expect($run->flow_version_id)->toBe($this->flow->fresh()->current_version_id)
        ->and($run->subjects()->count())->toBe(2)
        ->and($run->subjects()->first()->current_node_id)->toBe('n1')
        ->and($run->engine_workflow_id)->not->toBeNull();
});

it('starts the interpreter workflow with the run id', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    $started = app(WorkflowEngine::class)->started();

    expect($started[0]['workflow'])->toBe(FlowInterpreter::class)
        ->and($started[0]['args']['run_id'])->toBe($run->id);
});

it('marks a per-user run as the subject strategy automatically', function () {
    $cohort = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);
    $single = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['3']);

    expect($cohort->strategy)->toBe('cohort')
        ->and($single->strategy)->toBe('subject');
});

it('refuses to start when a subject fails the tenant check and creates no run subjects', function () {
    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '666']))
        ->toThrow(\Nodeflow\Execution\CrossTenantSubjectException::class);

    expect(\Nodeflow\Models\RunSubject::count())->toBe(0);
});

it('is idempotent for a repeated trigger identity', function () {
    $first = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);
    $second = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);

    expect($second->id)->toBe($first->id)
        ->and(\Nodeflow\Models\Run::count())->toBe(1);
});
