# Tenancy

Configure tenancy so ordinary Nodeflow model reads are isolated to the current organization and unresolved contexts fail safely when your application has tenants.

Nodeflow scopes `Flow`, `FlowVersion`, `Run`, and `Template` with its `nodeflow_tenant` global scope. `Template` additionally allows null-tenant rows as global templates when a non-null tenant is active. `RunSubject` and `NodeExecution` do not have their own tenant scope; see [Keep parent-row invariants intact](#keep-parent-row-invariants-intact).

## Choose the null-tenant mode

Set `NODEFLOW_TENANCY` in your application configuration. A non-null result from `TenantResolver::currentTenantId()` scopes queries in every mode. The setting changes only the meaning of `null`.

| Mode | A null tenant means | Web, console, and queue behavior |
| --- | --- | --- |
| `auto` (default) | If the container has Nodeflow’s `NoTenancyResolver`, the application declared no tenancy. If the container has a host resolver, tenancy is unresolved. | The fallback resolver reads unscoped. A host resolver returning null—for example in a guest request, console command, or worker without tenant context—throws `TenancyUnresolvedException`. |
| `disabled` | The application has no applicable tenant for this read. | Reads unscoped in every context when the resolver returns null. A non-null tenant still scopes normally. |
| `resolver` | Tenant resolution is required and failed. | Throws `TenancyUnresolvedException` in every context when the resolver returns null. A non-null tenant still scopes normally. |

Values are exact and case-sensitive. An absent, mistyped, or non-string mode raises `InvalidArgumentException` rather than falling back to an unscoped read.

## Inspect the effective decision

`auto` is a configured mode, not by itself the effective null-tenant behavior. Inspect the structured diagnostic when validating host wiring:

```php
$decision = app(\Nodeflow\Tenancy\TenancyDecisionResolver::class)->decision();

$diagnostic = [
    'configuredMode' => $decision->configuredMode,
    'effectiveMode' => $decision->effectiveMode,
    'resolverClass' => $decision->resolverClass,
    'nullTenantOutcome' => $decision->nullTenantOutcome,
    'inferred' => $decision->inferred,
    'reason' => $decision->reason,
];
```

`php artisan nodeflow:install --check` uses the same resolver and reports this effective decision. Its tenancy report is diagnostic only; it does not change the command's wiring exit code.

## Bind a custom resolver unconditionally

For a multi-tenant application, bind `TenantResolver` in the application provider’s `register()` method:

```php
// Partial snippet: App\Providers\NodeflowServiceProvider::register().

$this->app->bind(
    \Nodeflow\Contracts\TenantResolver::class,
    \App\Nodeflow\OrganizationTenantResolver::class,
);
```

Do not bind it only in request middleware. In `auto` mode, Nodeflow distinguishes “no tenancy” from “unresolved tenancy” by inspecting the resolver currently in the container. If middleware is the only binding, a queue worker or console command can fall back to `NoTenancyResolver`; `auto` then reads across all tenants instead of recognizing an unresolved tenant. An unconditional binding makes those contexts fail closed, or lets you deliberately select `disabled` if the application genuinely has no tenancy.

See [Required contracts](required-contracts.md) for a complete organization resolver.

## Make deliberate system-wide reads

Every tenant-scoped Nodeflow model exposes this public query escape hatch:

```php
public static function withoutTenancy(): \Illuminate\Database\Eloquent\Builder
```

Use it narrowly, after deciding that a system operation is allowed to cross tenant boundaries:

```php
// Partial snippet: a trusted maintenance or fan-out service.

$flows = \Nodeflow\Models\Flow::withoutTenancy()
    ->where('status', 'active')
    ->get();
```

`withoutTenancy()` removes only Nodeflow’s read scope. It does not authenticate an actor, grant authorization, validate input, or make a write safe. Keep it inside application-owned services with an explicit system purpose; do not expose it through request input.

> **Warning:** Avoid using an unscoped query merely to repair a route that cannot find a tenant row. A tenant-scoped lookup should remain a 404/data-isolation boundary; authorize the already reachable resource separately.

## Keep tenant IDs immutable

On creation, a tenant-scoped model receives the current tenant ID when one is available. An explicit `tenant_id` that contradicts a non-null ambient tenant raises `CrossTenantWriteException`. After creation, changing `tenant_id` through an Eloquent model instance always raises that exception, even if the new value matches the current ambient tenant. Re-send the existing value or omit it for ordinary updates.

```php
// Partial snippet: create a flow in the resolved organization.

$flow = \Nodeflow\Models\Flow::create([
    'name' => 'Renewal reminder',
    'trigger_type' => 'manual',
    'status' => 'draft',
]);

// Safe: tenant_id is unchanged.
$flow->update(['name' => 'Updated renewal reminder']);
```

> **Warning:** A query-builder update bypasses Eloquent model events and therefore bypasses the immutable-tenant guard. For example, `Flow::withoutTenancy()->where(...)->update(['tenant_id' => ...])` can move rows. Never update `tenant_id` through the query builder; create a new row in the target tenant instead.

The package’s internal `TenancyGuardSuspension` is not a host-application escape hatch. It is an internal mechanism for package-authored writes whose tenant is already taken from a trusted parent row.

## Keep parent-row invariants intact

Some relations intentionally remove their own tenant scope after their parent was already reached through a scoped query. For example, a `Flow` reads its versions and current version unscoped, and a `Run` reads its flow version unscoped. This is safe only while their foreign keys and tenant IDs describe the same tenant.

On Eloquent model-instance writes, `Flow` validates `current_version_id` in its `creating` hook and whenever `current_version_id` or `tenant_id` changes in its `updating` hook. `Run` applies the corresponding `creating` and invariant-changing `updating` hooks to `flow_version_id`. `Flow.current_version_id` may be null; `Run.flow_version_id` may not. Each guard resolves the referenced `FlowVersion` without the tenant scope, rejects a missing reference with `InvalidFlowVersionReferenceException`, and rejects a tenant mismatch with `CrossTenantWriteException`.

The `Flow` guard proves that a referenced version exists and has the Flow's tenant; it does not prove that the version belongs to that same Flow. These guards apply to new or updated model instances and do not audit existing rows.

`TenancyGuardSuspension` does not bypass these structural version-reference guards. Durable `RunNodeActivity` independently compares the persisted run and version tenants and throws `CrossTenantExecutionException` before it increments `steps_taken` or invokes a node.

Do not accept `flow_id`, `current_version_id`, or `flow_version_id` from untrusted request input. A flow version must inherit its flow’s tenant, and a run must point to a version from the same tenant. Query-builder updates and raw SQL bypass Eloquent model events, including these guards: keep version and tenant foreign-key writes on model instances, or perform equivalent explicit existence and tenant checks in a trusted service. Do not treat `withoutTenancy()` as write authorization.

`RunSubject` and `NodeExecution` have no `tenant_id`. Reach them through `Run::subjects()` and `Run::nodeExecutions()` after the `Run` itself was tenant-scoped, rather than starting from an unscoped child query. Their isolation depends on the scoped parent run remaining correct.

## Next step

Define the permission checks that apply after tenant-scoped reachability in [Authorization](authorization.md).
