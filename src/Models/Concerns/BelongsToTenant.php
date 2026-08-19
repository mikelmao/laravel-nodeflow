<?php

namespace Nodeflow\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Contracts\TenantResolver;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('nodeflow_tenant', function (Builder $query) {
            $tenantId = app(TenantResolver::class)->currentTenantId();

            if ($tenantId !== null) {
                $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            $model->tenant_id ??= app(TenantResolver::class)->currentTenantId();
        });
    }

    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope('nodeflow_tenant');
    }
}
