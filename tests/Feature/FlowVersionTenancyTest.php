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
            'status' => 'active',
        ]);

        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'tenant_id' => $tenantId,
            'version' => 1,
            // Deliberately raw: this fixture probes CheckNodeTypesResolver against
            // historical persisted executable graphs without publishing them.
            'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => $type, 'config' => []]], 'edges' => []],
            'content_hash' => 'x',
            'published_at' => now(),
        ]);

        Run::withoutTenancy()->create([
            'flow_version_id' => $version->id,
            'tenant_id' => $tenantId,
            'started_via' => 'manual',
            'trigger_node_id' => 'trigger',
            'trigger_data' => null,
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
    $flow = Flow::create(['name' => 'A', 'status' => 'draft']);

    $this->tenant = null;

    $version = app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow, triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]));

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
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'A', 'status' => 'draft']);

    $graph = triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

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
    // Publishing a flow from a context whose ambient tenant is not the flow's
    // own must refuse the write rather than label the version with the ambient
    // tenant. Two independent guards now say so: PublishFlow's explicit
    // 'tenant_id' => $flow->tenant_id makes the trait's ambient contradiction
    // check fire, and FlowVersion's own creating() hook re-reads the flow and
    // refuses any mismatch even without the explicit stamp.
    //
    // Counterfactual: remove BOTH the explicit stamp in PublishFlow and the
    // mismatch throw in FlowVersion::booted(), and this creates a version
    // labelled 'org-2' on an 'org-1' flow instead of throwing. (Removing
    // either one alone leaves the other holding, which is the point of the
    // belt-and-braces arrangement — see the test below for the hook on its
    // own.)
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'A', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(fn () => app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow->fresh(), triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ])))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);
});

it('refuses a version stamped with the ambient tenant when its flow belongs to another', function () {
    // The probe behind this fix. bootTraits() registers BelongsToTenant's
    // creating() hook before booted() runs, so the trait's ??= had already
    // stamped the ambient tenant by the time FlowVersion's hook fired — and the
    // old hook only acted on a null. Result: FlowVersion::create(['flow_id' =>
    // <org-2's flow>]) under ambient org-1 produced a version labelled org-1
    // hanging off org-2's flow, no exception, and every scoped read afterwards
    // showed org-1 a version of a flow it does not own.
    //
    // Counterfactual: restore `if ($version->tenant_id === null && ...)` around
    // the body of FlowVersion::booted()'s hook and this stops throwing — the
    // row is written with tenant_id 'org-1'.
    $othersFlow = \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(
        fn () => Flow::withoutTenancy()->create([
            'tenant_id' => 'org-2',
            'name' => 'B',
            'status' => 'active',
        ])
    );

    expect(fn () => FlowVersion::create([
        'flow_id' => $othersFlow->id,
        'version' => 1,
        'graph' => triggeredExitGraph(),
        'content_hash' => 'x',
    ]))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);

    expect(FlowVersion::withoutTenancy()->count())->toBe(0);
});

it('refuses an explicit tenant_id contradicting the flow even with no ambient tenant', function () {
    // Isolates FlowVersion's hook from the trait's. With no ambient tenant the
    // trait's contradiction check cannot fire at all — it needs a non-null
    // ambient tenant to compare against — so this write is refused by the flow
    // lookup or by nothing.
    //
    // Counterfactual: delete the mismatch throw from FlowVersion::booted() and
    // this writes a version labelled 'org-2' onto 'org-1's flow.
    $flow = Flow::create(['name' => 'A', 'status' => 'draft']);

    $this->tenant = null;

    expect(fn () => FlowVersion::create([
        'flow_id' => $flow->id,
        'tenant_id' => 'org-2',
        'version' => 1,
        'graph' => triggeredExitGraph(),
        'content_hash' => 'x',
    ]))->toThrow(\Nodeflow\Models\CrossTenantWriteException::class);
});

it('still inherits the flows tenant when nothing is set and nothing is ambient', function () {
    // The behaviour the hook already had, kept: a console command or queue
    // worker publishing with no ambient tenant must still produce a correctly
    // labelled version, or it would be invisible to every scoped read.
    //
    // Counterfactual: delete the inheritance branch and this write fails on
    // nodeflow_flow_versions.tenant_id being NOT NULL.
    $flow = Flow::create(['name' => 'A', 'status' => 'draft']);

    $this->tenant = null;

    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 1,
        'graph' => triggeredExitGraph(),
        'content_hash' => 'x',
    ]);

    expect($version->tenant_id)->toBe('org-1');
});

it('names the flow and both tenants when it refuses a mismatched version', function () {
    // The message has to identify which flow and which two tenants, or the
    // reader has no way to tell a seeding mistake from a real cross-tenant
    // write attempt.
    $othersFlow = \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(
        fn () => Flow::withoutTenancy()->create([
            'tenant_id' => 'org-2',
            'name' => 'B',
            'status' => 'active',
        ])
    );

    try {
        FlowVersion::create([
            'flow_id' => $othersFlow->id,
            'version' => 1,
            'graph' => triggeredExitGraph(),
            'content_hash' => 'x',
        ]);
        expect(false)->toBeTrue('expected a CrossTenantWriteException');
    } catch (\Nodeflow\Models\CrossTenantWriteException $e) {
        expect($e->getMessage())
            ->toContain('FlowVersion')
            ->toContain("flow [{$othersFlow->id}]")
            ->toContain("'org-1'")
            ->toContain("'org-2'");
    }
});
