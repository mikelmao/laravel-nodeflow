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

## Generate and implement the welcome node

Generate the node before changing the provider. The installer has already created the provider and its `$nodes` anchor, so the generator can append the class there automatically:

```bash
php artisan nodeflow:make-node SendWelcomeMessage --type=app.send_welcome --outputs=sent
```

If the provider no longer has the exact generated anchor, the command does not guess: it prints the registration line for you to add manually. Keep the generated registration, then replace `app/Nodeflow/Nodes/SendWelcomeMessage.php` with this class. This example assumes the application's `User` model uses Laravel notifications and that `WelcomeNotification` is your normal application notification.

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

## Bind and authorize

After generating the node, update `app/Providers/NodeflowServiceProvider.php`. This complete provider preserves the generator's registration homes:

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

        // This is an application gate, not one of Nodeflow's four gates.
        Gate::define('welcome-journeys.create', fn (?User $user): bool =>
            $user !== null && $user->isNodeflowAdministrator()
        );

        Gate::define('nodeflow.viewAny', fn (?User $user, mixed $resource = null): bool =>
            $this->mayManageNodeflow($user, $resource)
        );

        Gate::define('nodeflow.update', fn (?User $user, $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );

        Gate::define('nodeflow.publish', fn (?User $user, $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );

        Gate::define('nodeflow.runManually', fn (?User $user, $flow): bool =>
            $this->mayManageNodeflow($user, $flow)
        );
    }

    /** @return \Nodeflow\Schema\SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
        ];
    }

    private function mayManageNodeflow(?User $user, mixed $resource = null): bool
    {
        if ($user === null || ! $user->isNodeflowAdministrator()) {
            return false;
        }

        return $resource === null
            || (string) $user->organization_id === (string) $resource->tenant_id;
    }
}
```

The resolver bindings belong in `register()` and must be unconditional. With the default tenancy mode, a resolver that exists only during a web request can make a worker or console process appear to have no tenancy. Each gate denies by default: a missing user, a user for whom `isNodeflowAdministrator()` returns `false`, or a different organization returns `false`.

This example assumes the host `User` model exposes `isNodeflowAdministrator(): bool`; using an explicit boolean method avoids relying on how a database flag happens to be cast. Keep the tenant comparison for gates that receive a flow or run. See [Authorization](../integration/authorization.md) and [Registering domain components](../integration/registering-domain-components.md) for the complete pattern, including triggers and subject attributes.

## Register authenticated routes

Add the Nodeflow routes and the host-owned start route to `routes/web.php` inside your application's authenticated group:

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])->prefix('admin')->group(function (): void {
    Nodeflow::routes();

    Route::post('welcome-journeys', [\App\Http\Controllers\WelcomeJourneyController::class, 'store'])
        ->name('welcome-journeys.store');

    Route::post('flows/{flow}/users/{recipient}/welcome-journey', [\App\Http\Controllers\WelcomeJourneyController::class, 'start'])
        ->name('welcome-journeys.start');
});
```

The package routes are opt-in so your application retains control of middleware, URLs, and Inertia pages. The host-owned start route is a separate authorization boundary; it is not a package route. See [Routes and Inertia](../integration/routes-and-inertia.md) before customizing them.

## Add the host Inertia pages

The package ships React components, while your application supplies the Inertia pages named by the package controllers. Create `resources/js/pages/nodeflow/editor.tsx`:

```tsx
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} />
}
```

Create `resources/js/pages/nodeflow/run.tsx`:

```tsx
import { FlowRun, type FlowRunProps } from '@nodeflow/editor'

export default function Run(props: FlowRunProps) {
    return <FlowRun {...props} />
}
```

The lower-case, case-sensitive paths are intentional: the package renders `nodeflow/editor` and `nodeflow/run`, which map to these host files. Apply any Vite alias, TypeScript-path, React-deduplication, and `@xyflow/react` dependency snippets printed by the installer, then run `php artisan nodeflow:install --check` until it passes. [Frontend setup](../integration/frontend-setup.md) has the full wiring guide.

## Publish and start a flow

Create `app/Http/Controllers/WelcomeJourneyController.php`. First, its authenticated `store()` endpoint creates and publishes a flow once. Then its authenticated `start()` endpoint receives that already-published, tenant-scoped flow through route-model binding and starts live or test runs for it.

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

class WelcomeJourneyController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless($actor instanceof User, 403);
        $this->authorize('welcome-journeys.create');

        // TenantResolver derives this flow's tenant from the authenticated actor.
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

        // These policy checks use Nodeflow's registered FlowPolicy and gates.
        $this->authorize('publish', $flow);
        app(PublishFlow::class)->publish(
            flow: $flow,
            graph: $graph,
            publishedBy: (string) $actor->getAuthIdentifier(),
        );

        return to_route('nodeflow.flows.edit', ['flow' => $flow]);
    }

    public function start(Request $request, Flow $flow, User $recipient): RedirectResponse
    {
        // The {flow} binding is tenant-scoped by Nodeflow before this code runs.
        $this->authorize('runManually', $flow);

        // Do not let a flow address another organization's user.
        abort_unless((string) $flow->tenant_id === (string) $recipient->organization_id, 404);

        $isTest = $request->boolean('test_mode');
        $mode = $isTest ? 'test' : 'live';
        $run = app(StartRun::class)->forFlow(
            flow: $flow->fresh(),
            subjectType: 'user',
            subjectIds: [(string) $recipient->getKey()],
            options: [
                'strategy' => 'subject',
                'is_test' => $isTest,
                'correlation_id' => 'welcome:'.$mode.':'.$recipient->getKey(),
                'idempotency_key' => 'welcome:'.$mode.':'.$recipient->getKey(),
            ],
        );

        return to_route('nodeflow.runs.show', ['run' => $run]);
    }
}
```

`PublishFlow::publish()` validates the graph and freezes it as a version. `StartRun::forFlow()` uses that published version, creates one audience for the supplied IDs, and starts the durable workflow. On this same flow version, the idempotency key prevents a repeat live or test start from creating another run. Test and live starts use different keys, so each mode creates its own run.

> **Warning:** `PublishFlow` and `StartRun` are direct actions; they do not authorize themselves. Keep them behind an authenticated host boundary and explicitly authorize flow creation, publishing, and manual starts as this controller does.

See [Publishing flows](../building-automations/publishing-flows.md) and [Starting runs](../building-automations/starting-runs.md) for the full APIs.

## Start a worker and verify the run

Run a worker for the non-`sync` queue configured by your application:

```bash
php artisan queue:work
```

Verify the integration:

- Create and publish the flow once by submitting authenticated `POST /admin/welcome-journeys`. Keep the returned flow ID; the controller redirects to that flow's editor.
- Start the live run by submitting authenticated `POST /admin/flows/{flow}/users/{recipient}/welcome-journey`. The `nodeflow_runs` record for the returned run moves beyond `pending` and eventually completes for this two-node flow.
- The `nodeflow_run_subjects` record has `subject_type` `user`, the user's string ID, and reaches `completed` with no current node.
- The user receives `WelcomeNotification` for this live run. To create a separate test run on the same published flow, submit the same authenticated start request with the request body `{"test_mode": true}`. Its `welcome:test:{recipient-id}` idempotency key differs from the live `welcome:live:{recipient-id}` key; the subject should complete without a notification.
- Open the authenticated run view to inspect the pinned graph and per-node subject progress.

For worker settings, waits, and operational checks, see [Queues and workers](../operations/queues-and-workers.md) and [Inspecting runs](../editor-and-run-view/inspecting-runs.md).

## Next step

Read [Core concepts](core-concepts.md), then expand the integration with [required contracts](../integration/required-contracts.md) and [writing nodes](../building-automations/writing-nodes.md).
