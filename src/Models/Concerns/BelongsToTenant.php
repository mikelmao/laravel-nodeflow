<?php

namespace Nodeflow\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Tenancy\TenancyDecisionResolver;

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
                && (string) $model->tenant_id !== $currentTenantId) {
                throw CrossTenantWriteException::forCreate(
                    $model::class,
                    $currentTenantId,
                    $model->tenant_id
                );
            }

            $model->tenant_id ??= $currentTenantId;
        });

        // A row's tenant is fixed at creation. Nothing in the package moves a
        // row between tenants, and neither should a host: $guarded is empty on
        // every model here, so without this an `update($request->all())`
        // carrying a tenant_id would silently reassign the row — and take with
        // it every child row reachable through it, including RunSubject and
        // NodeExecution, which carry no tenant_id of their own and rely
        // entirely on their parent being scoped.
        //
        // Refuses any change, not just a change away from the ambient tenant:
        // "which tenant is ambient right now" is not the question. isDirty()
        // means a same-value write is not a change, so an update that merely
        // re-sends the row's existing tenant_id passes.
        //
        // Suspended by TenancyGuardSuspension for the same reason creating() is
        // — the package's own system-authored writes read their tenant_id from
        // a trusted row, not from request input.
        //
        // KNOWN LIMIT, and the reason it is stated here rather than only in the
        // docs: this is an `updating` model hook, so it sees only writes that go
        // through a model instance. Flow::withoutTenancy()->where(...)->update([...])
        // fires no model events and bypasses this guard completely. That is
        // inherent to the approach and is not a bug to be fixed here — but it is a
        // trap, because this codebase already uses query-builder updates for
        // status writes (CompleteRunActivity), so it is a pattern a reader may
        // copy without noticing what it skips.
        //
        // tests/Feature/TenantIdImmutabilityTest.php pins that bypass with a test
        // that asserts the query-builder write SUCCEEDS. If you change the
        // mechanism so it is caught, that test fails on purpose: update this
        // comment and docs/02-integration.md in the same commit.
        static::updating(function ($model) {
            if (TenancyGuardSuspension::isActive() || ! $model->isDirty('tenant_id')) {
                return;
            }

            throw CrossTenantWriteException::forTenantChange(
                $model::class,
                $model->getOriginal('tenant_id'),
                $model->tenant_id
            );
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
     * and nothing in the null itself distinguishes them — so nodeflow.tenancy
     * decides. It defaults to 'auto', which infers.
     *
     * KNOWN LIMIT of that inference: a host that binds its resolver only in
     * middleware still gets the package fallback in queue and console contexts,
     * where auto infers no tenancy and reads across every tenant. The decision API
     * and installer report make that inference visible, but do not make a
     * middleware-only binding safe. Bind in a provider's register(), or use
     * NODEFLOW_TENANCY=resolver instead.
     *
     * The mode is matched against a known set on every scoped read, in both
     * branches, rather than compared to 'resolver' alone. An unrecognised value
     * — 'Resolver', true, or a stale cached config that no longer has the key —
     * would otherwise take the same path as 'disabled' and read unscoped on a
     * null tenant, with no diagnostic. That fails open for exactly the host who
     * read the docs and mistyped the env var.
     *
     * Package-internal reads that legitimately cross tenants never reach this:
     * every one of them opts out with withoutTenancy() before the scope applies.
     */
    protected static function resolveTenantIdForScope(): ?string
    {
        return app(TenancyDecisionResolver::class)
            ->tenantIdForScope(static::class);
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
