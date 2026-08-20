<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Template;
use Tests\Support\StrictlyScopedTemplate;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver {
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

it('stamps the tenant id on create', function () {
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect($flow->tenant_id)->toBe('org-1');
});

it('hides other tenants rows', function () {
    Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(Flow::count())->toBe(0);

    $this->tenant = 'org-1';

    expect(Flow::count())->toBe(1);
});

it('can be escaped explicitly for system operations', function () {
    Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(Flow::withoutTenancy()->count())->toBe(1);
});

it('throws when explicit tenant_id differs from resolved tenant', function () {
    expect(fn () => Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 'org-2',
    ]))->toThrow(CrossTenantWriteException::class);
});

it('allows explicit tenant_id matching the resolved tenant', function () {
    $flow = Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 'org-1',
    ]);

    expect($flow->tenant_id)->toBe('org-1');
    expect(Flow::count())->toBe(1);
});

it('allows explicit tenant_id when no tenant is resolved', function () {
    $this->tenant = null;

    $flow = Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 'org-3',
    ]);

    expect($flow->tenant_id)->toBe('org-3');
});

it('shows global templates to all tenants', function () {
    // Create org-specific template in org-1 context
    Template::create(['name' => 'Org1', 'scope' => 'tenant', 'graph' => ['x' => 2]]);

    // Create global template with no tenant context
    $this->tenant = null;
    Template::create(['name' => 'Global', 'scope' => 'global', 'graph' => ['x' => 1]]);

    // Switch back to org-1 and verify we see both
    $this->tenant = 'org-1';
    expect(Template::count())->toBe(2);

    // Switch to org-2 and verify we only see the global one
    $this->tenant = 'org-2';
    expect(Template::count())->toBe(1);
    expect(Template::first()->name)->toBe('Global');
});

it('excludes null-tenant rows when allowsGlobalTenantRows returns false', function () {
    // Create a null-tenant row directly via query builder
    \DB::table('nodeflow_templates')->insert([
        'name' => 'Null',
        'scope' => 'global',
        'graph' => json_encode(['x' => 1]),
        'tenant_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Create an org-1 row via the model
    StrictlyScopedTemplate::create(['name' => 'Org1', 'scope' => 'tenant', 'graph' => ['x' => 2]]);

    // With resolver bound to org-1 and allowsGlobalTenantRows() = false, only org-1 row is visible
    $this->tenant = 'org-1';
    expect(StrictlyScopedTemplate::count())->toBe(1);
    expect(StrictlyScopedTemplate::first()->name)->toBe('Org1');

    // But the null-tenant row IS visible when bypassing tenancy
    expect(StrictlyScopedTemplate::withoutTenancy()->count())->toBe(2);
    expect(StrictlyScopedTemplate::withoutTenancy()->pluck('name')->sort()->values()->all())->toBe(['Null', 'Org1']);
});

it('accepts integer tenant_id equal to string resolver', function () {
    // Resolver returns string '5'
    $this->tenant = '5';

    // Create flow with integer tenant_id 5
    $flow = Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 5,
    ]);

    expect($flow->tenant_id)->toBe(5);
    expect(Flow::count())->toBe(1);
});

it('rejects integer tenant_id differing from string resolver', function () {
    // Resolver returns string '5'
    $this->tenant = '5';

    expect(fn () => Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 6,
    ]))->toThrow(CrossTenantWriteException::class);
});

it('refuses to move an existing row to another tenant on update', function () {
    // Proven by probe before the fix: creating() guarded the insert and nothing
    // guarded the update, so with $guarded = [] a host route doing
    // $flow->update($request->all()) could hand the flow — and every run,
    // subject and node execution hanging off it — to another tenant.
    //
    // Counterfactual: delete the updating() hook from BelongsToTenant and this
    // returns 'org-2' instead of throwing.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => $flow->update(['tenant_id' => 'org-2']))
        ->toThrow(CrossTenantWriteException::class);

    expect(Flow::withoutTenancy()->find($flow->id)->tenant_id)->toBe('org-1');
});

it('refuses a tenant_id change even when the new value is the ambient tenant', function () {
    // The question is not "does this match the ambient tenant" — it is "is this
    // row's tenant being changed at all". A row created for org-3 in a console
    // context must not become an org-1 row just because org-1 happens to be
    // ambient on the request that updates it.
    //
    // Counterfactual: make the updating() hook compare against the ambient
    // tenant the way creating() does, and this stops throwing.
    $this->tenant = null;

    $flow = Flow::create([
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
        'tenant_id' => 'org-3',
    ]);

    $this->tenant = 'org-1';

    expect(fn () => Flow::withoutTenancy()->find($flow->id)->update(['tenant_id' => 'org-1']))
        ->toThrow(CrossTenantWriteException::class);
});

it('allows an update that leaves tenant_id alone', function () {
    // The guard must not make ordinary edits throw — PublishFlow's own
    // $flow->update(['current_version_id' => ...]) runs through it.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $flow->update(['name' => 'B', 'status' => 'active']);

    expect(Flow::find($flow->id)->name)->toBe('B');
});

it('allows an update that re-sends the rows existing tenant_id', function () {
    // isDirty() is the test, not presence in the attribute list: an editor
    // round-tripping the whole model back is not a tenant change.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $flow->update(['name' => 'B', 'tenant_id' => 'org-1']);

    expect(Flow::find($flow->id)->name)->toBe('B');
});

it('lets a suspended guard write tenant_id on update, as it does on create', function () {
    // Same escape hatch, same reason: a package-internal write whose tenant_id
    // came from a trusted row rather than from request input. Without this the
    // updating() guard would be a second, undocumented rule that
    // TenancyGuardSuspension does not cover.
    //
    // Counterfactual: drop the isActive() check from the updating() hook and
    // this throws instead of writing.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(
        fn () => $flow->update(['tenant_id' => 'org-2'])
    );

    expect(Flow::withoutTenancy()->find($flow->id)->tenant_id)->toBe('org-2');
});
