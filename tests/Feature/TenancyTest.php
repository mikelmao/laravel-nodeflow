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
