<?php

use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\InvalidFlowVersionReferenceException;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\Core\StartFlowNode;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\SubFlowStarter;

beforeEach(function () {
    // Ambient tenant stays null throughout: every Flow/Run below stamps its own
    // explicit tenant_id, and a null ambient tenant lets any explicit tenant_id
    // through the BelongsToTenant creating guard without a contradiction.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return null; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $this->childFlow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Child', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->childFlow, triggeredGraph([
        'start' => 'c1',
        'nodes' => [['id' => 'c1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    $this->childFlow = $this->childFlow->fresh();

    $parentFlow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Parent', 'status' => 'draft']);

    app(PublishFlow::class)->publish($parentFlow, triggeredGraph([
        'start' => 'p1',
        'nodes' => [['id' => 'p1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    $this->parentRun = Run::create([
        'flow_version_id' => $parentFlow->fresh()->current_version_id,
        'tenant_id' => 'org-1',
        'correlation_id' => null,
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => ['parent-occurrence' => 'p-1'],
        'strategy' => 'cohort',
        'status' => 'running',
    ]);
});

it('starts a child run and seeds the lineage chain with the parent run id', function () {
    $child = app(SubFlowStarter::class)->start($this->parentRun, $this->childFlow->id, 'user', ['1', '2']);

    expect($child)->not->toBeNull()
        ->and($child->flow_version_id)->toBe($this->childFlow->current_version_id)
        ->and($child->tenant_id)->toBe('org-1')
        ->and($child->correlation_id)->toBe((string) $this->parentRun->id)
        ->and($child->started_via)->toBe('subflow')
        ->and($child->trigger_node_id)->toBe('trigger')
        ->and($child->trigger_data)->toBe(['parent-occurrence' => 'p-1'])
        ->and($child->subjects()->pluck('current_node_id')->unique()->all())->toBe(['c1'])
        ->and($child->nodeExecutions()->count())->toBe(0)
        ->and($child->subjects()->count())->toBe(2);
});

it('refuses a raw same-tenant child current-version pointer to another flow', function () {
    $other = Flow::create(['tenant_id' => 'org-1', 'name' => 'Other child', 'status' => 'draft']);
    app(PublishFlow::class)->publish($other, triggeredExitGraph());

    DB::table('nodeflow_flows')->where('id', $this->childFlow->id)->update([
        'current_version_id' => $other->fresh()->current_version_id,
    ]);

    expect(fn () => app(SubFlowStarter::class)->start(
        $this->parentRun,
        $this->childFlow->id,
        'user',
        ['1'],
    ))->toThrow(InvalidFlowVersionReferenceException::class, 'does not belong to Flow');

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('refuses a raw cross-tenant child version that still belongs to the child flow', function () {
    DB::table('nodeflow_flow_versions')->where('id', $this->childFlow->current_version_id)->update([
        'tenant_id' => 'org-2',
    ]);

    expect(fn () => app(SubFlowStarter::class)->start(
        $this->parentRun,
        $this->childFlow->id,
        'user',
        ['1'],
    ))->toThrow(CrossTenantExecutionException::class, 'Cross-tenant execution refused');

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('extends an existing lineage chain rather than replacing it', function () {
    $this->parentRun->update(['correlation_id' => '12>48']);

    $child = app(SubFlowStarter::class)->start($this->parentRun->fresh(), $this->childFlow->id, 'user', ['1']);

    expect($child->correlation_id)->toBe('12>48>'.$this->parentRun->id);
});

it('refuses to start beyond the depth limit', function () {
    // Five entries already at MAX_DEPTH (5): the sixth hop must be refused
    // before any flow lookup or run creation happens.
    $this->parentRun->update(['correlation_id' => '1>2>3>4>5']);

    $child = app(SubFlowStarter::class)->start($this->parentRun->fresh(), $this->childFlow->id, 'user', ['1']);

    expect($child)->toBeNull()
        ->and(Run::withoutTenancy()->count())->toBe(1); // only the parent run exists
});

it('keeps the existing no-published-version diagnostic for a child flow', function () {
    $draft = Flow::create(['tenant_id' => 'org-1', 'name' => 'Draft child', 'status' => 'draft']);

    expect(fn () => app(SubFlowStarter::class)->start($this->parentRun, $draft->id, 'user', ['1']))
        ->toThrow(RuntimeException::class, "Flow [{$draft->id}] has no published version.");

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('refuses to start a flow belonging to a different tenant than the parent run', function () {
    $otherTenantFlow = Flow::create(['tenant_id' => 'org-2', 'name' => 'Other', 'status' => 'draft']);

    app(PublishFlow::class)->publish($otherTenantFlow, triggeredGraph([
        'start' => 'o1',
        'nodes' => [['id' => 'o1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

    expect(fn () => app(SubFlowStarter::class)->start($this->parentRun, $otherTenantFlow->id, 'user', ['1']))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    expect(Run::withoutTenancy()->count())->toBe(1); // only the parent run exists
});

it('StartFlowNode starts the sub-flow and exits the current flow by default', function () {
    $node = new StartFlowNode;
    $context = new AudienceContext($this->parentRun, 'n1', ['flow_id' => $this->childFlow->id], 'user', ['1', '2']);

    $result = $node->forAudience($context);

    expect($result->outputs())->toBe([]);

    $child = Run::withoutTenancy()->where('id', '!=', $this->parentRun->id)->first();

    expect($child)->not->toBeNull()
        ->and($child->flow_version_id)->toBe($this->childFlow->current_version_id)
        ->and($child->correlation_id)->toBe((string) $this->parentRun->id)
        ->and($child->subjects()->count())->toBe(2);
});

it('StartFlowNode keeps subjects in the current flow when exit_this_flow is false', function () {
    $node = new StartFlowNode;
    $context = new AudienceContext(
        $this->parentRun,
        'n1',
        ['flow_id' => $this->childFlow->id, 'exit_this_flow' => false],
        'user',
        ['1'],
    );

    $result = $node->forAudience($context);

    expect($result->outputs())->toBe(['default' => ['1']])
        ->and(Run::withoutTenancy()->count())->toBe(2); // parent + the sub-flow it started
});
