# Application setup

This page establishes the host contracts used by the flood-alert workflow. Nodeflow migrations create its own flow, version, run, and execution tables; they do not create the application tables below.

## Define the host data

Use the following minimum data contract. The application may have additional columns, but the example relies only on these values.

| Model/table | Required columns and relationships | Why it is needed |
| --- | --- | --- |
| `Organization` / `organizations` | Bigint primary key `id`; a stable, non-null `name`. | Its primary key is cast to a string whenever it becomes a Nodeflow tenant ID. |
| `User` / `users` | Bigint primary key `id`; indexed `organization_id` foreign key; `name`; unique `email`; non-null `password`; boolean `is_nodeflow_admin` defaulting to false; nullable timestamp `clicked_offer_at`; `organization()` belongs-to relationship. Cast the final two columns to `boolean` and `datetime`. | A `User` is the `user` subject. `is_nodeflow_admin` supplies the example's administration rule, and `clicked_offer_at` is the source for `clicked_offer`. |
| `FloodAlert` / `flood_alerts` | Bigint primary key `id`; `severity` string; nullable `dispatched_at` timestamp. | The event carries the durable alert ID and severity. Do not create a new alert record for a redelivery. |
| `DemoMessage` / `demo_messages` | Bigint primary key `id`; indexed `organization_id`; `user_id`; `run_id`; `node_id`; `message` string; `body` text; timestamps; a unique index on `run_id`, `node_id`, and `user_id`. | This host-owned operational delivery record makes the illustrative message write idempotent. |
| `FloodAlertWorkflow` / `flood_alert_workflows` | Bigint primary key `id`; unique `organization_id`; nullable `flow_id` foreign key. | This is the stable, one-per-organization provisioning record for the flood-alert flow. |

This concrete migration uses Laravel `id()` and `foreignId()`, so its application identities are bigint keys. Nodeflow stores tenant and subject identities as strings, so cast `getKey()`, `organization_id`, and event map keys to strings at the integration boundary. `User::organization_id` must identify the same organization checked by the tenant resolver; a relationship alone is not an ownership check.

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
            $table->boolean('is_nodeflow_admin')->default(false);
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

        Schema::create('flood_alert_workflows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('flow_id')
                ->nullable()
                ->constrained('nodeflow_flows')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flood_alert_workflows');
        Schema::dropIfExists('demo_messages');
        Schema::dropIfExists('flood_alerts');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['organization_id']);
            $table->dropColumn(['organization_id', 'is_nodeflow_admin', 'clicked_offer_at']);
        });

        Schema::dropIfExists('organizations');
    }
};
```

`DemoMessage::firstOrCreate()` uses the same `run_id`, `node_id`, `user_id` identity as this unique index. The `run_id` foreign key intentionally points to Nodeflow's `nodeflow_runs` table, so publish and run the package migrations before this host migration. Its cascading foreign key means a `DemoMessage` row is deleted when the related Nodeflow run is deleted or pruned. It is therefore an operational delivery record, not a durable business or audit ledger; retain an application-owned audit record separately if it must outlive run retention.

## Implement the host models

These compact application models expose only the relationships and mass-assignment surface used by this walkthrough. `DemoMessage` accepts the node's trusted `firstOrCreate()` attributes; do not mass-assign it from a request.

**File: `app/Models/Organization.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organization extends Model
{
    protected $fillable = ['name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function floodAlertWorkflow(): HasOne
    {
        return $this->hasOne(FloodAlertWorkflow::class);
    }
}
```

**File: `app/Models/FloodAlert.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FloodAlert extends Model
{
    protected $fillable = ['severity', 'dispatched_at'];

    protected $casts = [
        'dispatched_at' => 'datetime',
    ];
}
```

**File: `app/Models/DemoMessage.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodeflow\Models\Run;

class DemoMessage extends Model
{
    protected $fillable = [
        'organization_id',
        'user_id',
        'run_id',
        'node_id',
        'message',
        'body',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
```

**File: `app/Models/FloodAlertWorkflow.php`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nodeflow\Models\Flow;

class FloodAlertWorkflow extends Model
{
    protected $fillable = ['organization_id', 'flow_id'];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }
}
```

**File: `app/Models/User.php` (modifications to the existing Laravel model)**

```php
// Add these imports beside the existing imports.
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Merge these entries into the existing $casts property or casts() method.
// Preserve every existing cast.
'clicked_offer_at' => 'datetime',
'is_nodeflow_admin' => 'boolean',

// Add these methods inside the existing User class.
public function organization(): BelongsTo
{
    return $this->belongsTo(Organization::class);
}

public function isNodeflowAdministrator(): bool
{
    return $this->is_nodeflow_admin;
}
```

The `User` changes are additive: do not replace its existing authentication traits, fillable settings, hidden values, password casts, or other relationships.

### Bootstrap the first administrator safely

Use a trusted deployment-time seeder to promote a known user. Replace the email below with the already verified administrator selected by the operator, then run the seeder once.

**File: `database/seeders/NodeflowAdministratorSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class NodeflowAdministratorSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()
            ->where('email', 'owner@example.test')
            ->firstOrFail();

        $user->forceFill(['is_nodeflow_admin' => true])->save();
    }
}
```

```bash
php artisan db:seed --class=NodeflowAdministratorSeeder
```

Never expose `is_nodeflow_admin` in request validation, profile updates, or a self-service promotion route. Role promotion is a trusted administrative operation, not user-controlled input.

## Define the dispatched event

Dispatch this event after the `FloodAlert` record exists and the application has selected affected users. Its payload is deliberately enough for the trigger to work without looking up arbitrary request input.

**File: `app/Events/FloodAlertDispatched.php`**

```php
<?php

namespace App\Events;

class FloodAlertDispatched
{
    /** @param array<int|string, list<int|string>> $userIdsByOrganization */
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

        Route::post('flood-alert-workflow', [\App\Http\Controllers\FloodAlertFlowController::class, 'store'])
            ->name('flood-alert-workflow.store');
    });
```

Create the thin adapters at `resources/js/pages/nodeflow/editor.tsx` and `resources/js/pages/nodeflow/run.tsx` under the page root and casing that the application already uses. Keep Nodeflow's editor and run routes inside an authenticated, tenant-aware group; the full route and adapter contract is in [Routes and Inertia](../integration/routes-and-inertia.md).

## Next step

Implement the node, trigger, graph, and conversion cancellation in [Flood-alert workflow](flood-alert-workflow.md).
