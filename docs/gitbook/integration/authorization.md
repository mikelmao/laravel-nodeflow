# Authorization

Define four application gates so Nodeflow’s registered policies can deny by default and allow only the organization members your application chooses.

Nodeflow knows a model’s opaque `tenant_id`, but it does not know your roles, plans, or membership rules. It registers policies for `Flow` and `Run`; those policies delegate to these gate names:

| Gate | Purpose |
| --- | --- |
| `nodeflow.viewAny` | List and view flows or runs. |
| `nodeflow.update` | Edit drafts and resolve field options. |
| `nodeflow.publish` | Publish an immutable version. |
| `nodeflow.runManually` | Start live or test runs manually. |

## Define tenant-aware gates

Add these definitions in your application `NodeflowServiceProvider::boot()` after the provider’s registry work. This partial snippet assumes `User::isNodeflowAdministrator(): bool` is an application method and `organization_id` identifies the user’s organization.

```php
// Partial snippet: imports at the top of App\Providers\NodeflowServiceProvider.

use App\Models\User;
use Illuminate\Support\Facades\Gate;
```

```php
// Partial snippet: the body of App\Providers\NodeflowServiceProvider::boot().

Gate::define('nodeflow.viewAny', function (?User $user, mixed $resource = null): bool {
    if ($user === null || ! $user->isNodeflowAdministrator()) {
        return false;
    }

    return $resource === null
        || (string) $user->organization_id === (string) $resource->tenant_id;
});

Gate::define('nodeflow.update', function (?User $user, \Nodeflow\Models\Flow $flow): bool {
    return $user !== null
        && $user->isNodeflowAdministrator()
        && (string) $user->organization_id === (string) $flow->tenant_id;
});

Gate::define('nodeflow.publish', function (?User $user, \Nodeflow\Models\Flow $flow): bool {
    return $user !== null
        && $user->isNodeflowAdministrator()
        && (string) $user->organization_id === (string) $flow->tenant_id;
});

Gate::define('nodeflow.runManually', function (?User $user, \Nodeflow\Models\Flow $flow): bool {
    return $user !== null
        && $user->isNodeflowAdministrator()
        && (string) $user->organization_id === (string) $flow->tenant_id;
});
```

The nullable first parameter is intentional. Laravel calls a gate closure for a guest only when the closure accepts a null user. `nodeflow.viewAny` receives no model for a class-level list check, and it receives a `Flow` or `Run` model for an individual view check, so its second parameter must accept both shapes.

The package maps `FlowPolicy::view()` and `RunPolicy::view()` to `nodeflow.viewAny`; it does not define a fifth view gate. Its update, publish, and manual-start flow policy methods delegate to the remaining three names. If a gate is absent, the policy returns `false` rather than opening access.

## Keep isolation and permission separate

Tenant-scoped route-model binding determines whether a row is reachable. A cross-tenant flow or run ID is not found and should produce a 404, which does not reveal that the row exists. A reachable row then goes through the policy and gates; a denied permission is a 403.

Keep both checks. A tenant scope is data isolation, not a role check, and a role check does not make an unscoped lookup safe.

```php
// Partial snippet: an authenticated application controller.

public function start(\Illuminate\Http\Request $request, \Nodeflow\Models\Flow $flow): \Illuminate\Http\RedirectResponse
{
    // $flow was reached through its tenant scope; this is the permission check.
    $this->authorize('runManually', $flow);

    $run = app(\Nodeflow\Execution\StartRun::class)->forFlow(
        flow: $flow,
        subjectType: 'user',
        subjectIds: [(string) $request->integer('user_id')],
    );

    return to_route('nodeflow.runs.show', ['run' => $run]);
}
```

`PublishFlow` and `StartRun` are direct actions: neither authorizes itself. Put them behind an authenticated host route or service boundary and use `authorize()` or `Gate::authorize()` before invoking them. The [Quick start](../getting-started/quick-start.md#create-and-publish-the-graph) shows a publication call; direct manual-start code must also validate subject ownership.

## Use Gate::before carefully

A `Gate::before()` callback runs before the gate or policy. Returning a non-null result overrides the normal decision; returning `null` defers to the normal gate and policy path.

```php
// Partial snippet: a deliberate, audited global override.

Gate::before(function (?User $user, string $ability, array $arguments): ?bool {
    return $user?->isSuperAdministrator() ? true : null;
});
```

> **Warning:** A non-null `Gate::before()` result bypasses the tenant-aware gates above. Use a global allow only for an application role that is explicitly permitted to cross tenants, and return `null` for everyone else.

## Replace policies only deliberately

The package registers `FlowPolicy` for `Nodeflow\Models\Flow` and `RunPolicy` for `Nodeflow\Models\Run`. The default policies are the four-gate adapter described above. If your application needs substantially different policy behavior, register your own mapping in a provider that runs after the package provider:

```php
// Partial snippet: an application provider that intentionally replaces both mappings.

Gate::policy(\Nodeflow\Models\Flow::class, \App\Policies\NodeflowFlowPolicy::class);
Gate::policy(\Nodeflow\Models\Run::class, \App\Policies\NodeflowRunPolicy::class);
```

Once replaced, those policy classes own the authorization contract. Preserve tenant-aware checks and the expected abilities, or update every application route and action that calls them.

## Next step

Register the application nodes, triggers, and subject attributes that authors may use in [Registering domain components](registering-domain-components.md).
