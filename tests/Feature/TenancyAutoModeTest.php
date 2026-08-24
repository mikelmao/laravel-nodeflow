<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;
use Nodeflow\Tenancy\NoTenancyResolver;

function seedFlowFor(string $tenantId): void
{
    TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => $tenantId,
        'name' => 'A',
        'status' => 'draft',
    ]));
}

function bindNullResolver(): void
{
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return null;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });
}

it('defaults to auto', function () {
    // Counterfactual: leave the default at 'disabled' and this fails.
    expect(config('nodeflow.tenancy'))->toBe('auto');
});

it('reads unscoped under auto when the package fallback resolver is in play', function () {
    // The engine-only host: never binds a resolver, so ours answers. A null from
    // it means "this application has no tenancy", not "we could not resolve".
    // Counterfactual: make auto throw unconditionally and this fails.
    seedFlowFor('org-1');
    seedFlowFor('org-2');

    expect(app(TenantResolver::class))->toBeInstanceOf(NoTenancyResolver::class)
        ->and(Flow::count())->toBe(2);
});

it('throws under auto when the host bound its own resolver and it returned null', function () {
    // The multi-tenant host on a queue job. Under the old 'disabled' default this
    // silently returned every tenant's rows — the hole E2a closes.
    // Counterfactual: treat any resolver as "no tenancy" and this returns 2.
    seedFlowFor('org-1');
    bindNullResolver();

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('still scopes under auto when the host resolver returns a tenant', function () {
    seedFlowFor('org-1');
    seedFlowFor('org-2');

    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    expect(Flow::count())->toBe(1);
});

it('lets an explicit disabled override auto for a host with its own resolver', function () {
    // The escape hatch: a host that binds a resolver and genuinely wants unscoped
    // reads says so. Counterfactual: drop the 'disabled' arm and this throws.
    config()->set('nodeflow.tenancy', 'disabled');
    seedFlowFor('org-1');
    seedFlowFor('org-2');
    bindNullResolver();

    expect(Flow::count())->toBe(2);
});

it('lets an explicit resolver override auto for the fallback resolver', function () {
    // The inverse escape hatch, and the one that proves auto is inference rather
    // than a rename of 'disabled'.
    config()->set('nodeflow.tenancy', 'resolver');
    seedFlowFor('org-1');

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('still refuses an unrecognised mode', function () {
    config()->set('nodeflow.tenancy', 'Auto');

    expect(fn () => Flow::count())->toThrow(InvalidArgumentException::class, 'Auto');
});
