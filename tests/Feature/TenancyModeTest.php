<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;

/** Binds a resolver returning $tenantId, which may be null. */
function bindTenant(?string $tenantId): void
{
    app()->bind(TenantResolver::class, fn () => new class($tenantId) implements TenantResolver
    {
        public function __construct(private ?string $tenantId) {}

        public function currentTenantId(): ?string
        {
            return $this->tenantId;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });
}

/**
 * Seeds a flow for an arbitrary tenant, ambient or not.
 *
 * Wrapped in TenancyGuardSuspension because these tests deliberately seed rows
 * for a tenant other than the ambient one, and BelongsToTenant's creating()
 * hook throws CrossTenantWriteException on that mismatch. Suspension disables
 * only that throw — never the read scope, which is the thing under test. This
 * is the same mechanism StartRun uses for its own cross-tenant fan-out writes.
 */
function makeFlowFor(string $tenantId): void
{
    \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => $tenantId,
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]));
}

it('reads unscoped when tenancy is disabled and no tenant resolves', function () {
    // Meaning 1 of null: the application has no tenancy. This is the package's
    // out-of-the-box behaviour and the default TenantResolver returns null.
    config()->set('nodeflow.tenancy', 'disabled');
    bindTenant(null);

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(2);
});

it('throws when tenancy is resolver-managed and no tenant resolves', function () {
    // Meaning 2 of null: a queue worker or unauthenticated request. Reading
    // unscoped here is a cross-tenant leak, so it must fail loudly.
    // Counterfactual: delete the throw and this returns 2 instead of throwing.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    makeFlowFor('org-1');

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('names the model and the escape hatch when it throws', function () {
    // A fail-closed error the reader cannot act on just gets the mode switched
    // back off, which would defeat the whole change.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    expect(fn () => Flow::count())->toThrow(
        TenancyUnresolvedException::class,
        'Nodeflow\Models\Flow',
    );

    try {
        Flow::count();
    } catch (TenancyUnresolvedException $e) {
        expect($e->getMessage())
            ->toContain('withoutTenancy()')
            ->toContain('nodeflow.tenancy');
    }
});

it('scopes normally in resolver mode when a tenant does resolve', function () {
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant('org-1');

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(1);
});

it('scopes normally in disabled mode when a tenant does resolve', function () {
    // The mode governs only what NULL means. A non-null tenant scopes in both
    // modes — this is what keeps every existing tenancy test passing.
    // Counterfactual: make 'disabled' skip scoping entirely and this returns 2.
    config()->set('nodeflow.tenancy', 'disabled');
    bindTenant('org-1');

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(1);
});

it('lets an explicit system read through in resolver mode', function () {
    // withoutTenancy() is how all eleven package-internal cross-tenant reads
    // work; if the throw fired before the scope was removed, every fan-out
    // trigger and queue activity would break.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::withoutTenancy()->count())->toBe(2);
});
