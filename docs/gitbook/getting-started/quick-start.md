# Quick start

This page creates a minimal end-to-end Nodeflow integration: an organization is the tenant, a user is the subject, and a welcome-message flow publishes and runs through a queue worker.

> **Experimental:** Treat this as a starting point for a pre-release package. Review the [experimental project status](../experimental/project-status.md) before using it for production automation.

## Install and migrate

Run these commands from the Laravel application:

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

The installer creates `app/Providers/NodeflowServiceProvider.php` when it can. The following examples use that provider.

## Resolve organizations and users

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
        return auth()->user()?->organization_id === null
            ? null
            : (string) auth()->user()->organization_id;
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

`ownsSubject()` is the tenant-isolation check run before every subject is added to a run. Returning `false` for unknown types prevents a cross-organization audience from being materialized.

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
            ->keyBy(fn (User $user) => (string) $user->getKey())
            ->all();
    }
}
```

The string keys matter: Nodeflow stores and passes subject IDs as strings. For deeper contract and tenancy guidance, see [Required contracts](../integration/required-contracts.md) and [Tenancy](../integration/tenancy.md).

## Bind, authorize, and register the node

Replace the generated contents of `app/Providers/NodeflowServiceProvider.php` with this minimal provider:

```php
<?php

namespace App\Providers;

use App\Models\User;
use App\Nodeflow\OrganizationTenantResolver;
use App\Nodeflow\UserSubjectResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    /** @var class-string[] */
    protected array $nodes = [
        \App\Nodeflow\Nodes\SendWelcomeMessage::class,
    ];

    /** @var class-string[] */
    protected array $triggers = [
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
            $user?->is_admin === true
            && ($resource === null || (string) $user->organization_id === (string) $resource->tenant_id)
        );

        Gate::define('nodeflow.update', fn (?User $user, $flow): bool =>
            $user?->is_admin === true
            && (string) $user->organization_id === (string) $flow->tenant_id
        );

        Gate::define('nodeflow.publish', fn (?User $user, $flow): bool =>
            $user?->is_admin === true
            && (string) $user->organization_id === (string) $flow->tenant_id
        );

        Gate::define('nodeflow.runManually', fn (?User $user, $flow): bool =>
            $user?->is_admin === true
            && (string) $user->organization_id === (string) $flow->tenant_id
        );
    }

    /** @return \Nodeflow\Schema\SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
        ];
    }
}
```

The resolver bindings belong in `register()` and must be unconditional. With the default tenancy mode, a resolver that exists only during a web request can make a worker or console process appear to have no tenancy. Each gate denies by default: a missing user, a non-admin, or a different organization returns `false`.

Adjust the `is_admin` check to match your application, but keep the tenant comparison for gates that receive a flow or run. See [Authorization](../integration/authorization.md) and [Registering domain components](../integration/registering-domain-components.md) for the complete pattern, including triggers and subject attributes.

## Generate and implement the welcome node

Generate a subject node with a stable, application-owned type:

```bash
php artisan nodeflow:make-node SendWelcomeMessage --type=app.send_welcome --outputs=sent
```

Then replace `app/Nodeflow/Nodes/SendWelcomeMessage.php` with the following class. This example assumes the application's `User` model uses Laravel notifications and that `WelcomeNotification` is your normal application notification.

```php
<?php

namespace App\Nodeflow\Nodes;

use App\Models\User;
use App\Notifications\WelcomeNotification;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class SendWelcomeMessage extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'app.send_welcome';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send welcome message')
            ->group('Messaging')
            ->description('Send the application welcome message to a user.')
            ->outputs(['sent']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('sent');
        }

        /** @var User $user */
        $user = $context->subject();
        $user->notify(new WelcomeNotification);

        return $context->continue('sent');
    }
}
```

The `isTest()` branch is deliberate: test-mode runs must not cause an external side effect. In a production node, also make the notification operation idempotent because queued activities can be retried. Read [Writing nodes](../building-automations/writing-nodes.md) for cardinality, retries, and failure handling.

## Register authenticated routes

Add the Nodeflow routes to `routes/web.php` inside your application's authenticated group:

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])->prefix('admin')->group(
    fn () => Nodeflow::routes(),
);
```

The package routes are opt-in so your application retains control of middleware, URLs, and Inertia pages. See [Routes and Inertia](../integration/routes-and-inertia.md) before customizing them.

## Publish and start a flow

Use an authenticated administrator in a controller, command, or application service after resolving the intended organization. The following is a complete example of the Nodeflow calls; `$user` is the user who should receive the welcome message.

```php
<?php

use App\Models\User;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

/** @var User $user */
$flow = Flow::create([
    'name' => 'Welcome journey',
    'trigger_type' => 'manual',
    'status' => 'draft',
]);

$graph = [
    'start' => 'welcome',
    'nodes' => [
        ['id' => 'welcome', 'type' => 'app.send_welcome', 'config' => []],
        ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
    ],
    'edges' => [
        ['from' => 'welcome', 'output' => 'sent', 'to' => 'exit'],
    ],
];

app(PublishFlow::class)->publish(
    flow: $flow,
    graph: $graph,
    publishedBy: (string) auth()->id(),
);

$run = app(StartRun::class)->forFlow(
    flow: $flow->fresh(),
    subjectType: 'user',
    subjectIds: [(string) $user->getKey()],
    options: [
        'strategy' => 'subject',
        'is_test' => false,
        'correlation_id' => 'welcome:'.$user->getKey(),
        'idempotency_key' => 'welcome:'.$user->getKey(),
    ],
);
```

`PublishFlow::publish()` validates the graph and freezes it as a version. `StartRun::forFlow()` uses that published version, creates one audience for the supplied IDs, and starts the durable workflow. The idempotency key prevents a second start for the same published version and key from creating another run. See [Publishing flows](../building-automations/publishing-flows.md) and [Starting runs](../building-automations/starting-runs.md) for the full APIs.

## Start a worker and verify the run

Run a worker for the non-`sync` queue configured by your application:

```bash
php artisan queue:work
```

Verify the integration:

- The `nodeflow_runs` record for `$run->id` moves beyond `pending` and eventually completes for this two-node flow.
- The `nodeflow_run_subjects` record has `subject_type` `user`, the user's string ID, and reaches `completed` with no current node.
- The user receives `WelcomeNotification` for this live run. Repeat with `is_test` set to `true`; the subject should still complete, but no notification should be sent.
- Open the authenticated run view to inspect the pinned graph and per-node subject progress.

For worker settings, waits, and operational checks, see [Queues and workers](../operations/queues-and-workers.md) and [Inspecting runs](../editor-and-run-view/inspecting-runs.md).

## Next step

Read [Core concepts](core-concepts.md), then expand the integration with [required contracts](../integration/required-contracts.md) and [writing nodes](../building-automations/writing-nodes.md).
