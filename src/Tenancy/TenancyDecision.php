<?php

namespace Nodeflow\Tenancy;

final readonly class TenancyDecision
{
    public const EFFECTIVE_DISABLED = 'disabled';

    public const EFFECTIVE_RESOLVER = 'resolver';

    public const NULL_TENANT_UNSCOPED = 'unscoped';

    public const NULL_TENANT_THROWS_UNRESOLVED = 'throws_tenancy_unresolved';

    public const NULL_TENANT_THROWS_INVALID = 'throws_invalid_configuration';

    public const REASON_AUTO_PACKAGE_FALLBACK = 'auto_package_fallback';

    public const REASON_AUTO_HOST_RESOLVER = 'auto_host_resolver';

    public const REASON_EXPLICIT_DISABLED = 'explicit_disabled';

    public const REASON_EXPLICIT_RESOLVER = 'explicit_resolver';

    public const REASON_INVALID_CONFIGURATION = 'invalid_configuration';

    public function __construct(
        public mixed $configuredMode,
        public ?string $effectiveMode,
        public string $resolverClass,
        public string $nullTenantOutcome,
        public bool $inferred,
        public string $reason,
    ) {}

    public function isValid(): bool
    {
        return $this->reason !== self::REASON_INVALID_CONFIGURATION;
    }
}
