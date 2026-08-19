<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Template;

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

it('does not show null-tenant rows to flows', function () {
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect($flow->allowsGlobalTenantRows())->toBeFalse();
});
