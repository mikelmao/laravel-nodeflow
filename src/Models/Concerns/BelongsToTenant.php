<?php

namespace Nodeflow\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\CrossTenantWriteException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('nodeflow_tenant', function (Builder $query) {
            $tenantId = app(TenantResolver::class)->currentTenantId();

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

            if ($currentTenantId !== null && $model->tenant_id !== null && $model->tenant_id !== $currentTenantId) {
                throw new CrossTenantWriteException(
                    $model::class,
                    $currentTenantId,
                    $model->tenant_id
                );
            }

            $model->tenant_id ??= $currentTenantId;
        });
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
