<?php

namespace Nodeflow\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\TenancyUnresolvedException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('nodeflow_tenant', function (Builder $query) {
            $tenantId = static::resolveTenantIdForScope();

            if ($tenantId !== null) {
                $query->where(function (Builder $q) use ($tenantId) {
                    $q->where($q->getModel()->getTable().'.tenant_id', $tenantId);

                    if ($q->getModel()->allowsGlobalTenantRows()) {
                        $q->orWhereNull($q->getModel()->getTable().'.tenant_id');
                    }
                });
            }
        });

        static::creating(function ($model) {
            $currentTenantId = app(TenantResolver::class)->currentTenantId();

            if (! TenancyGuardSuspension::isActive()
                && $currentTenantId !== null
                && $model->tenant_id !== null
                && (string)$model->tenant_id !== $currentTenantId) {
                throw new CrossTenantWriteException(
                    $model::class,
                    $currentTenantId,
                    $model->tenant_id
                );
            }

            $model->tenant_id ??= $currentTenantId;
        });
    }

    /**
     * The ambient tenant for a scoped read, or null when reading unscoped is the
     * declared intent.
     *
     * Null from the resolver is overloaded: it means both "this application has
     * no tenancy" (a single-tenant host that never binds a resolver — including
     * the package's own fallback binding) and "tenancy is unresolved right now"
     * (a queue worker, a console command, an unauthenticated request). Reading
     * unscoped is correct for the first and a cross-tenant leak for the second,
     * and nothing in the null itself distinguishes them — so the host declares
     * which it means via nodeflow.tenancy.
     *
     * Package-internal reads that legitimately cross tenants never reach this:
     * every one of them opts out with withoutTenancy() before the scope applies.
     *
     * @throws \Nodeflow\Models\TenancyUnresolvedException
     */
    protected static function resolveTenantIdForScope(): ?string
    {
        $tenantId = app(TenantResolver::class)->currentTenantId();

        if ($tenantId === null && config('nodeflow.tenancy') === 'resolver') {
            throw new TenancyUnresolvedException(static::class);
        }

        return $tenantId;
    }

    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope('nodeflow_tenant');
    }

    public function allowsGlobalTenantRows(): bool
    {
        return false;
    }
}
