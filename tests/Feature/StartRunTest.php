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

    $this->flow = Flow::create(['name' => 'F', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->flow, triggeredGraph([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]));
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

    expect(\Nodeflow\Models\RunSubject::count())->toBe(0)
        ->and(\Nodeflow\Models\Run::withoutTenancy()->count())->toBe(0);
});

it('is idempotent for a repeated trigger identity', function () {
    $first = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);
    $second = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);

    expect($second->id)->toBe($first->id)
        ->and(\Nodeflow\Models\Run::count())->toBe(1);
});

it('creates a run for a different tenant than the ambient one via the internal guard suspension', function () {
    // This file's beforeEach binds the ambient tenant to 'org-1'. Build the
    // org-2 flow with the ambient tenant switched to null just for this setup
    // step, so the fixture itself isn't blocked by the very guard being
    // tested — that guard only fires when the ambient tenant is non-null.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return null; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $otherTenantFlow = Flow::create(['tenant_id' => 'org-2', 'name' => 'Other', 'status' => 'draft']);

    app(PublishFlow::class)->publish($otherTenantFlow, triggeredGraph([
        'start' => 'o1',
        'nodes' => [['id' => 'o1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    // Switch back to a concrete, non-null ambient tenant — 'org-1' — that
    // differs from the flow's own tenant_id. Outside StartRun's internal
    // guard suspension this contradiction would throw CrossTenantWriteException
    // (see the next test); through StartRun it must succeed, because the run's
    // tenant_id comes from the trusted $flow->tenant_id, not from ambient or
    // request state, and StartRun is reacting to a system event with no
    // ambient tenant of its own.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $run = app(StartRun::class)->forFlow($otherTenantFlow->fresh(), 'user', ['1']);

    expect($run->tenant_id)->toBe('org-2')
        ->and($run->subjects()->count())->toBe(1);
});

it('still throws for a genuine contradicting write outside the guard suspension', function () {
    // Same ambient tenant ('org-1', from this file's beforeEach) as the test
    // above, but this is an ordinary, unsuspended model write directly — the
    // guard must still block it. This is what proves the suspension is scoped
    // to StartRun's own insert and does not leak into surrounding code.
    expect(fn () => Flow::create([
        'tenant_id' => 'org-2',
        'name' => 'Bad',
        'status' => 'draft',
    ]))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);
});
