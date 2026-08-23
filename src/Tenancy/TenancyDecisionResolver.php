<?php

namespace Nodeflow\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\TenancyUnresolvedException;

final class TenancyDecisionResolver
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    public function decision(): TenancyDecision
    {
        $resolver = $this->container->make(TenantResolver::class);

        return $this->decisionFor($resolver, $this->config->get('nodeflow.tenancy'));
    }

    public function tenantIdForScope(string $modelClass): ?string
    {
        $resolver = $this->container->make(TenantResolver::class);
        $decision = $this->decisionFor($resolver, $this->config->get('nodeflow.tenancy'));

        if (! $decision->isValid()) {
            throw new InvalidArgumentException($this->invalidModeMessage($decision->configuredMode));
        }

        $tenantId = $resolver->currentTenantId();

        if ($tenantId !== null) {
            return $tenantId;
        }

        return match ($decision->nullTenantOutcome) {
            TenancyDecision::NULL_TENANT_UNSCOPED => null,
            TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED => throw new TenancyUnresolvedException($modelClass),
            default => throw new InvalidArgumentException($this->invalidModeMessage($decision->configuredMode)),
        };
    }

    private function decisionFor(TenantResolver $resolver, mixed $mode): TenancyDecision
    {
        return match ($mode) {
            'auto' => $resolver instanceof NoTenancyResolver
                ? new TenancyDecision(
                    $mode,
                    TenancyDecision::EFFECTIVE_DISABLED,
                    $resolver::class,
                    TenancyDecision::NULL_TENANT_UNSCOPED,
                    true,
                    TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK,
                )
                : new TenancyDecision(
                    $mode,
                    TenancyDecision::EFFECTIVE_RESOLVER,
                    $resolver::class,
                    TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED,
                    true,
                    TenancyDecision::REASON_AUTO_HOST_RESOLVER,
                ),
            'disabled' => new TenancyDecision(
                $mode,
                TenancyDecision::EFFECTIVE_DISABLED,
                $resolver::class,
                TenancyDecision::NULL_TENANT_UNSCOPED,
                false,
                TenancyDecision::REASON_EXPLICIT_DISABLED,
            ),
            'resolver' => new TenancyDecision(
                $mode,
                TenancyDecision::EFFECTIVE_RESOLVER,
                $resolver::class,
                TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED,
                false,
                TenancyDecision::REASON_EXPLICIT_RESOLVER,
            ),
            default => new TenancyDecision(
                $mode,
                null,
                $resolver::class,
                TenancyDecision::NULL_TENANT_THROWS_INVALID,
                false,
                TenancyDecision::REASON_INVALID_CONFIGURATION,
            ),
        };
    }

    private function invalidModeMessage(mixed $mode): string
    {
        return 'Unrecognised nodeflow.tenancy mode '.$this->describeMode($mode)
            ."; the only valid values are 'auto', 'resolver' and 'disabled'. All are matched "
            ."exactly, so 'Auto', 'AUTO' and true are all invalid. Reading is refused rather "
            .'than falling back to unscoped, which on a null tenant would return every '
            .'tenant\'s rows. Check NODEFLOW_TENANCY in the environment, and run '
            .'`php artisan config:clear` if a cached config predates the key existing.';
    }

    private function describeMode(mixed $mode): string
    {
        if ($mode === null) {
            return 'null (the key is absent)';
        }

        return is_scalar($mode) ? var_export($mode, true) : get_debug_type($mode);
    }
}
