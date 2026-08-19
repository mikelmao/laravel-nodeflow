<?php

use Nodeflow\Console\CheckNodeTypesResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });
});

/**
 * A flow with one published version whose graph references $type, plus a live run.
 *
 * Wrapped in TenancyGuardSuspension because these tests seed rows for a tenant
 * other than the ambient one, and BelongsToTenant's creating() hook throws
 * CrossTenantWriteException on exactly that. Suspension disables only that
 * throw, never the read scope — which is the thing under test here. This is the
 * same mechanism StartRun uses for its own cross-tenant fan-out writes.
 */
function seedVersionWithLiveRun(string $tenantId, string $type): FlowVersion
{
    return \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(function () use ($tenantId, $type) {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => 'A',
            'trigger_type' => 'manual',
            'status' => 'active',
        ]);

        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'tenant_id' => $tenantId,
            'version' => 1,
            'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => $type, 'config' => []]], 'edges' => []],
            'content_hash' => 'x',
            'published_at' => now(),
        ]);

        Run::withoutTenancy()->create([
            'flow_version_id' => $version->id,
            'tenant_id' => $tenantId,
            'strategy' => 'cohort',
            'status' => 'waiting',
        ]);

        return $version;
    });
}

it('hides another tenants flow versions', function () {
    // Counterfactual: remove BelongsToTenant from FlowVersion and this returns 2.
    // This is the read the handoff named: FlowVersion::find($request->version)
    // becomes a cross-tenant read the moment a route exists.
    seedVersionWithLiveRun('org-1', 'core.exit');
    seedVersionWithLiveRun('org-2', 'core.exit');

    expect(FlowVersion::count())->toBe(1);

    $this->tenant = 'org-2';

    expect(FlowVersion::count())->toBe(1);
});

it('stamps a version with its flows tenant, not the ambient one', function () {
    // Counterfactual: drop 'tenant_id' from PublishFlow's create() and the row
    // gets the ambient tenant — null in a console or queue publish, which would
    // then be invisible to every scoped read.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = null;

    $version = app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    expect($version->tenant_id)->toBe('org-1');
});

it('checks node types across every tenant, not just the ambient one', function () {
    // The regression this task exists to prevent. check-node-types is a deploy
    // gate: it must see every tenant's live versions. Counterfactual: drop
    // withoutTenancy() from CheckNodeTypesResolver and this finds 1, not 2 —
    // or throws, in resolver mode with no ambient tenant.
    seedVersionWithLiveRun('org-1', 'gone.missing');
    seedVersionWithLiveRun('org-2', 'gone.missing');

    config()->set('nodeflow.tenancy', 'resolver');
    $this->tenant = null;

    $missing = CheckNodeTypesResolver::findMissingTypes(app(NodeRegistry::class));

    expect($missing)->toHaveCount(2);
});

it('continues a flows own version sequence instead of restarting it under a different ambient tenant', function () {
    // Counterfactual: remove withoutGlobalScope('nodeflow_tenant') from
    // Flow::versions(). The second publish below then computes
    // $flow->versions()->max('version') scoped to 'org-2' — which sees none of
    // this flow's rows, all of them tagged 'org-1' — so max() returns null,
    // (int) null + 1 is 1, and the insert collides with the version already at
    // (flow_id, 1).
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $graph = [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ];

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow, $graph);

    $this->tenant = 'org-2';

    // Suspended because this test isolates Flow::versions()'s read scope, not
    // PublishFlow's own tenant_id contradiction guard — the next test covers
    // that guard on its own.
    $second = \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(
        fn () => app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow->fresh(), $graph)
    );

    expect($second->version)->toBe(2);
});

it('resolves a runs flow version across tenants, lazily and eagerly', function () {
    // Counterfactual: remove withoutGlobalScope('nodeflow_tenant') from
    // Run::flowVersion(). Both assertions below then fail, because the
    // relation's own query reapplies FlowVersion's tenant scope against the
    // ambient tenant at the time it runs — the same trap that broke
    // StartRun's currentVersion() lookup, but on the run-to-version edge
    // instead of the flow-to-version one. This feeds LoadGraphActivity and
    // RunNodeActivity, the durable resume path.
    $version = seedVersionWithLiveRun('org-1', 'core.exit');
    $run = Run::withoutTenancy()->where('flow_version_id', $version->id)->firstOrFail();

    $this->tenant = 'org-2';

    expect($run->flowVersion)->not->toBeNull()
        ->and($run->flowVersion->id)->toBe($version->id);

    $reloaded = Run::withoutTenancy()->with('flowVersion')->find($run->id);

    expect($reloaded->flowVersion)->not->toBeNull()
        ->and($reloaded->flowVersion->id)->toBe($version->id);
});

it('throws instead of mislabelling a version when the ambient tenant differs from the flows own', function () {
    // Counterfactual: drop 'tenant_id' => $flow->tenant_id from PublishFlow's
    // create() call. BelongsToTenant's own ??= would then silently stamp the
    // version with the ambient tenant ('org-2') instead of the flow's real one
    // ('org-1') — mislabelling it rather than refusing the write. The explicit
    // stamp turns that silent mislabel into a loud CrossTenantWriteException.
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(fn () => app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow->fresh(), [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);
});
