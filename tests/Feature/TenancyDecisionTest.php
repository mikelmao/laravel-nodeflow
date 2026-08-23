<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;
use Nodeflow\Tenancy\NoTenancyResolver;
use Nodeflow\Tenancy\TenancyDecision;
use Nodeflow\Tenancy\TenancyDecisionResolver;

beforeEach(function () {
    $this->decisionResolver = function (): TenancyDecisionResolver {
        $resolve = fn () => app(TenancyDecisionResolver::class);

        // The public boundary is container resolution, not source/class existence.
        expect($resolve)->not->toThrow(Throwable::class);

        return $resolve();
    };

    $this->bindResolver = function (?string $tenantId): void {
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
    };
});

it('describes the auto fallback as an inferred unscoped null outcome', function () {
    $decision = ($this->decisionResolver)()->decision();

    expect(app(TenantResolver::class))->toBeInstanceOf(NoTenancyResolver::class)
        ->and($decision->configuredMode)->toBe('auto')
        ->and($decision->effectiveMode)->toBe(TenancyDecision::EFFECTIVE_DISABLED)
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_UNSCOPED)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK)
        ->and($decision->inferred)->toBeTrue()
        ->and($decision->resolverClass)->toBe(NoTenancyResolver::class)
        ->and($decision->isValid())->toBeTrue();
});

it('records that a host binding made auto fail closed', function () {
    ($this->bindResolver)(null);

    $decision = ($this->decisionResolver)()->decision();

    expect($decision->effectiveMode)->toBe(TenancyDecision::EFFECTIVE_RESOLVER)
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_AUTO_HOST_RESOLVER)
        ->and($decision->inferred)->toBeTrue()
        ->and($decision->resolverClass)->not->toBe(NoTenancyResolver::class);

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('reports explicit modes without claiming inference', function (string $mode, string $effective, string $outcome, string $reason) {
    config()->set('nodeflow.tenancy', $mode);
    ($this->bindResolver)(null);

    $decision = ($this->decisionResolver)()->decision();

    expect($decision->effectiveMode)->toBe($effective)
        ->and($decision->nullTenantOutcome)->toBe($outcome)
        ->and($decision->reason)->toBe($reason)
        ->and($decision->inferred)->toBeFalse();
})->with([
    'disabled' => ['disabled', TenancyDecision::EFFECTIVE_DISABLED, TenancyDecision::NULL_TENANT_UNSCOPED, TenancyDecision::REASON_EXPLICIT_DISABLED],
    'resolver' => ['resolver', TenancyDecision::EFFECTIVE_RESOLVER, TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED, TenancyDecision::REASON_EXPLICIT_RESOLVER],
]);

it('represents invalid configuration while an actual scope still refuses it', function () {
    config()->set('nodeflow.tenancy', 'Resolver');
    ($this->bindResolver)('org-1');

    $decision = ($this->decisionResolver)()->decision();

    expect($decision->effectiveMode)->toBeNull()
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_THROWS_INVALID)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_INVALID_CONFIGURATION)
        ->and($decision->isValid())->toBeFalse();

    expect(fn () => Flow::count())->toThrow(InvalidArgumentException::class, "'Resolver'");
});

it('recomputes config and the resolver binding after an earlier inspection', function () {
    $service = ($this->decisionResolver)();
    $first = $service->decision();

    config()->set('nodeflow.tenancy', 'resolver');
    ($this->bindResolver)('org-9');
    $second = $service->decision();

    expect($first->reason)->toBe(TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK)
        ->and($second->reason)->toBe(TenancyDecision::REASON_EXPLICIT_RESOLVER)
        ->and($second->resolverClass)->not->toBe($first->resolverClass)
        ->and($service->tenantIdForScope(Flow::class))->toBe('org-9');
});
