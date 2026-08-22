# Application setup

This page establishes the host contracts used by the flood-alert workflow. Nodeflow migrations create its own flow, version, run, and execution tables; they do not create the application tables below.

## Define the host data

Use the following minimum data contract. The application may have additional columns, but the example relies only on these values.

| Model/table | Required columns and relationships | Why it is needed |
| --- | --- | --- |
| `Organization` / `organizations` | Primary key `id`; a stable, non-null `name` is useful for administration. | Its primary key is cast to a string whenever it becomes a Nodeflow tenant ID. |
| `User` / `users` | Primary key `id`; indexed `organization_id` foreign key; `name`; unique `email`; non-null `password`; nullable timestamp `clicked_offer_at`; `organization()` belongs-to relationship. Cast `clicked_offer_at` to `datetime`. | A `User` is the `user` subject. `clicked_offer_at` is the source for `clicked_offer`. |
| `FloodAlert` / `flood_alerts` | Primary key `id`; `severity` string; nullable `dispatched_at` timestamp. | The event carries the durable alert ID and severity. Do not create a new alert record for a redelivery. |
| `DemoMessage` / `demo_messages` | Primary key `id`; indexed `organization_id`; `user_id`; `run_id`; `node_id`; `message` string; `body` text; timestamps; a unique index on `run_id`, `node_id`, and `user_id`. | This host-owned delivery ledger makes the illustrative message write idempotent. |

`Organization` and `User` may use integer or UUID keys. The package stores tenant and subject identities as strings, so always cast `getKey()`, `organization_id`, and event map keys to strings at the integration boundary. `User::organization_id` must identify the same organization checked by the tenant resolver; a relationship alone is not an ownership check.

### Create the host migrations

The following is a complete **host migration** for a fresh application that already has Laravel's normal `users` table (`id`, `name`, `email`, and `password`) and has run the Nodeflow migrations first. It creates only application-owned tables and columns. For an application that already has users, backfill `organization_id` in a separate deployment before adding the non-null foreign key.

**File: `database/migrations/2026_08_22_000001_create_flood_alert_example_tables.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('organization_id');
            $table->timestamp('clicked_offer_at')->nullable();
        });

        Schema::create('flood_alerts', function (Blueprint $table): void {
            $table->id();
            $table->string('severity');
            $table->timestamp('dispatched_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('demo_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->index('organization_id');
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('run_id')
                ->constrained('nodeflow_runs')
                ->cascadeOnDelete();
            $table->string('node_id');
            $table->string('message');
            $table->text('body');
            $table->timestamps();

            $table->unique(['run_id', 'node_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_messages');
        Schema::dropIfExists('flood_alerts');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'clicked_offer_at']);
        });

        Schema::dropIfExists('organizations');
    }
};
```

`DemoMessage::firstOrCreate()` uses the same `run_id`, `node_id`, `user_id` identity as this unique index. The `run_id` foreign key intentionally points to Nodeflow's `nodeflow_runs` table, so publish and run the package migrations before this host migration.

## Define the dispatched event

Dispatch this event after the `FloodAlert` record exists and the application has selected affected users. Its payload is deliberately enough for the trigger to work without looking up arbitrary request input.

**File: `app/Events/FloodAlertDispatched.php`**

```php
<?php

namespace App\Events;

class FloodAlertDispatched
{
    /** @param array<string, list<int|string>> $userIdsByOrganization */
    public function __construct(
        public readonly string $alertId,
        public readonly string $severity,
        public readonly array $userIdsByOrganization,
    ) {
    }
}
```

The map key is an organization ID and each value is that organization's affected `User` IDs. Do not put users from different organizations into one map entry. The trigger turns each entry into its own `TriggerMatch` audience.

## Bind the tenant and subject resolvers

`OrganizationTenantResolver` is the isolation boundary. It resolves the ambient organization from the authenticated user and proves that every user entering a run belongs to the named organization.

**File: `app/Nodeflow/OrganizationTenantResolver.php`**

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

`UserSubjectResolver` returns a string-keyed map, not a positional list. A missing user remains absent so a node receives `null` and can handle that safely.

**File: `app/Nodeflow/UserSubjectResolver.php`**

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

Bind both classes unconditionally in a provider's `register()` method. Queue workers and event listeners need the same bindings even when no user is authenticated. See [Required contracts](../integration/required-contracts.md) and [Tenancy](../integration/tenancy.md) for the broader tenancy behavior.

## Register the application components and gates

The installer-generated provider has registration homes for nodes, triggers, and subject attributes. Preserve those homes so later generators can append safely. This complete provider adds the flood-alert components without replacing the package's bindings or routes.

**File: `app/Providers/NodeflowServiceProvider.php`**

```php
<?php

namespace App\Providers;

use App\Models\User;
use App\Nodeflow\Nodes\SendMessage;
use App\Nodeflow\OrganizationTenantResolver;
use App\Nodeflow\Triggers\FloodAlertFires;
use App\Nodeflow\UserSubjectResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    /** @var class-string[] */
    protected array $nodes = [
        SendMessage::class,
    ];

    /** @var class-string[] */
    protected array $triggers = [
        FloodAlertFires::class,
    ];

    public function register(): void
    {
        $this->app->bind(TenantResolver::class, OrganizationTenantResolver::class);
        $this->app->bind(SubjectResolver::class, UserSubjectResolver::class);
    }

    public function boot(): void
    {
        Nodeflow::register($this->nodes);
        app(TriggerRegistry::class)->register(...$this->triggers);
        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());

        Gate::define('nodeflow.viewAny', fn (?User $user, mixed $resource = null): bool =>
            $this->mayManageNodeflow($user, $resource)
        );
        Gate::define('nodeflow.update', fn (?User $user, mixed $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );
        Gate::define('nodeflow.publish', fn (?User $user, mixed $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );
        Gate::define('nodeflow.runManually', fn (?User $user, mixed $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );
    }

    /** @return \Nodeflow\Schema\SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
            SubjectAttribute::make(
                'clicked_offer',
                'Clicked offer',
                'boolean',
                fn (?User $user): bool => $user?->clicked_offer_at !== null,
            ),
        ];
    }

    protected function mayManageNodeflow(?User $user, mixed $resource = null): bool
    {
        if ($user === null || ! $user->isNodeflowAdministrator()) {
            return false;
        }

        return $resource === null
            || (string) $user->organization_id === (string) $resource->tenant_id;
    }
}
```

`clicked_offer` reads only `users.clicked_offer_at`. It returns `false` when a user has been deleted since audience materialization, which makes the condition safely take the `no` branch only if that subject is still active. In normal operation, the conversion listener on the next page removes a converted user before the follow-up wait completes.

The gates are intentionally separate from tenant scoping: a cross-organization row should not be reachable, and a reachable row still needs a role decision. See [Authorization](../integration/authorization.md) before changing their permissions.

## Mount secure routes and Inertia adapters

The package supplies route definitions and controller props. The application supplies authentication, URL ownership, layout, and the Inertia page adapters.

**File: `routes/web.php`**

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('admin')
    ->group(function (): void {
        Nodeflow::routes();
    });
```

Create the thin adapters at `resources/js/pages/nodeflow/editor.tsx` and `resources/js/pages/nodeflow/run.tsx` under the page root and casing that the application already uses. Keep Nodeflow's editor and run routes inside an authenticated, tenant-aware group; the full route and adapter contract is in [Routes and Inertia](../integration/routes-and-inertia.md).

## Next step

Implement the node, trigger, graph, and conversion cancellation in [Flood-alert workflow](flood-alert-workflow.md).
