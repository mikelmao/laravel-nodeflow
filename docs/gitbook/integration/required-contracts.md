# Required contracts

Bind a tenant resolver and a subject resolver so Nodeflow can isolate data and turn your application’s subject IDs into application models at run time.

This page uses an application where an `Organization` owns `User` records. It expands the setup in [Quick start](../getting-started/quick-start.md) without repeating the rest of that integration.

## Implement the tenant resolver

Create `app/Nodeflow/OrganizationTenantResolver.php`:

```php
<?php

namespace App\Nodeflow;

use App\Models\User;
use Nodeflow\Contracts\TenantResolver;

class OrganizationTenantResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        $organizationId = auth()->user()?->organization_id;

        return $organizationId === null ? null : (string) $organizationId;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        if ($subjectType !== 'user') {
            return false;
        }

        return User::query()
            ->whereKey($subjectId)
            ->where('organization_id', $tenantId)
            ->exists();
    }
}
```

`currentTenantId(): ?string` returns the ambient tenant for the current context. Return `null` only when no tenant can be resolved; how Nodeflow treats that value is configured by the tenancy mode described in [Tenancy](tenancy.md). Cast the application key to a string. Nodeflow stores tenant and subject identities as strings, so a consistent representation avoids treating an integer key and its string form as different identities.

`ownsSubject()` is a mandatory isolation check, not an optional eligibility rule. During audience materialization, Nodeflow string-normalizes and de-duplicates the supplied IDs, then calls this method once for each remaining subject before it inserts any audience rows. If any call returns `false`, it raises `CrossTenantSubjectException`; no part of that audience is materialized. Reject unknown subject types rather than accepting a type the resolver does not understand.

> **Warning:** Do not implement `ownsSubject()` as a membership check that ignores `$tenantId`. Returning `true` for a subject owned by another organization admits that subject to the run.

## Implement the subject resolver

Create `app/Nodeflow/UserSubjectResolver.php`:

```php
<?php

namespace App\Nodeflow;

use App\Models\User;
use Nodeflow\Contracts\SubjectResolver;

class UserSubjectResolver implements SubjectResolver
{
    public function resolve(string $subjectType, array $subjectIds): array
    {
        if ($subjectType !== 'user') {
            return [];
        }

        return User::query()
            ->whereKey($subjectIds)
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey())
            ->all();
    }
}
```

The exact contract is `resolve(string $subjectType, array $subjectIds): array`. It returns a string-keyed map of `subjectId => host model`, not a numerically indexed list. Nodeflow looks up each requested ID by its string form.

An unknown subject type should return an empty map. A requested user that is missing from the query is also absent from the map; Nodeflow supplies `null` for that subject when it calls a subject node. Decide deliberately how your nodes handle a deleted or otherwise unavailable subject. Audience ownership is checked when the audience is created, while subject resolution occurs later when nodes run.

## Bind both contracts in the provider

Put unconditional bindings in your generated `NodeflowServiceProvider`’s `register()` method:

```php
// Partial snippet: App\Providers\NodeflowServiceProvider::register().

$this->app->bind(
    \Nodeflow\Contracts\TenantResolver::class,
    \App\Nodeflow\OrganizationTenantResolver::class,
);

$this->app->bind(
    \Nodeflow\Contracts\SubjectResolver::class,
    \App\Nodeflow\UserSubjectResolver::class,
);
```

These are the two host bindings the runtime requires. Bind them in `register()`, not in web middleware or only after authentication: queue workers and console commands also resolve these contracts. The package has fallbacks, but its fallback subject resolver throws when a node tries to resolve subjects.

The package also defines an `AudienceResolver` contract. Current runtime code does not consume it, so do not add a binding for it as part of this integration.

## Next step

Choose how null tenant contexts fail or read unscoped in [Tenancy](tenancy.md), then define user permissions in [Authorization](authorization.md).
