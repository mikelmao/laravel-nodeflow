<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Models\Flow;
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

    $this->childFlow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Child', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->childFlow, [
        'start' => 'c1',
        'nodes' => [['id' => 'c1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    $this->childFlow = $this->childFlow->fresh();

    $parentFlow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Parent', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($parentFlow, [
        'start' => 'p1',
        'nodes' => [['id' => 'p1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    $this->parentRun = Run::create([
        'flow_version_id' => $parentFlow->fresh()->current_version_id,
        'tenant_id' => 'org-1',
        'correlation_id' => null,
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
        ->and($child->subjects()->count())->toBe(2);
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

it('refuses to start a flow belonging to a different tenant than the parent run', function () {
    $otherTenantFlow = Flow::create(['tenant_id' => 'org-2', 'name' => 'Other', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($otherTenantFlow, [
        'start' => 'o1',
        'nodes' => [['id' => 'o1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

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
