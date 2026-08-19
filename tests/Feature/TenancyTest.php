<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;

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
