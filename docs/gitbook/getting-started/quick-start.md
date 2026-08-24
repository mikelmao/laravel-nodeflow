# Quick start

This walkthrough installs Nodeflow, registers one allowlisted Laravel event source, publishes a valid trigger-first graph, and starts it by dispatching the event.

## Install and verify

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan nodeflow:install --check
php artisan migrate
```

The installer creates `app/Providers/NodeflowServiceProvider.php` when it can safely do so. It also checks provider registration, frontend aliases, TypeScript paths, Tailwind scanning, React deduplication, and `@xyflow/react`. See [Installation](installation.md) for the full host setup.

## Register authenticated and public routes

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('nodeflow')
    ->group(fn () => Nodeflow::routes());

Route::middleware(['api', 'throttle:webhooks'])
    ->prefix('nodeflow')
    ->group(fn () => Nodeflow::webhookRoutes());
```

`Nodeflow::routes()` exposes the authenticated editor and run viewer. `Nodeflow::webhookRoutes()` exposes only `POST hooks/{token}` and is opt-in. The host owns both groups' domain, prefix, middleware, authentication, CSRF choice, and rate limits.

## Define and register an event source

Generate a source shell:

```bash
php artisan nodeflow:make-trigger-source OrderPlacedSource \
  --driver=event --key=shop.order_placed \
  --event='App\Events\OrderPlaced'
```

Implement the generated source using the complete Laravel event example in [Writing triggers](../building-automations/writing-triggers.md#laravel-event-source). In the host provider, keep the registration arrays explicit:

```php
<?php

namespace App\Providers;

use App\Nodeflow\Triggers\OrderPlacedSource;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Nodeflow;

final class NodeflowServiceProvider extends ServiceProvider
{
    protected array $triggerDrivers = [];
    protected array $triggerNodes = [];
    protected array $triggerSources = [
        OrderPlacedSource::class,
    ];

    public function boot(): void
    {
        Nodeflow::registerTriggerDrivers($this->triggerDrivers);
        Nodeflow::registerTriggerNodes($this->triggerNodes);
        Nodeflow::registerTriggerSources($this->triggerSources);
    }
}
```

The package's service provider has already registered the built-in `event` driver and `core.trigger.laravel_event` node. Host sources are registered afterward as an allowlist. If you add an extension driver, register driver → node → source in that order.

## Create and publish the graph

A valid graph has exactly one trigger node. `start` is its ID, it has no incoming edge, and its single `started` edge targets an executable node.

```php
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

$flow = Flow::create([
    'name' => 'Welcome a new order',
    'status' => 'draft',
]);

$graph = [
    'start' => 'order-placed',
    'nodes' => [
        [
            'id' => 'order-placed',
            'type' => 'core.trigger.laravel_event',
            'config' => ['source' => 'shop.order_placed'],
            'position' => ['x' => 80, 'y' => 120],
        ],
        [
            'id' => 'finish',
            'type' => 'core.exit',
            'config' => [],
            'position' => ['x' => 420, 'y' => 120],
        ],
    ],
    'edges' => [
        ['from' => 'order-placed', 'to' => 'finish', 'output' => 'started'],
    ],
];

$result = app(PublishFlow::class)->publish(
    $flow,
    $graph,
    publishedBy: (string) auth()->id(),
    expectedDraftRevision: (int) $flow->draft_revision,
);
```

Publication validates the graph, creates an immutable `FlowVersion`, replaces the flow's immutable `TriggerActivation`, and points `current_version_id` at the new version in one transaction. An event that was already emitted stays pinned to the activation snapshot it observed; a later publication affects later occurrences only.

## Dispatch the event

```php
event(new \App\Events\OrderPlaced(
    orderId: 'order-123',
    tenantId: 'org-1',
    customerId: 'customer-9',
));
```

Laravel event triggers run synchronously with normal event dispatch. If the event represents committed database state, make the host event implement Laravel's after-commit contract or dispatch it after the transaction commits.

Nodeflow resolves the source's tenant audience, verifies every subject belongs to the activation tenant, creates the run against the exact pinned version and executable entry node, then dispatches durable execution. One activation failure is reported and isolated from other activations.

## What to read next

- [Writing triggers](../building-automations/writing-triggers.md) covers all three built-ins and custom OOP extensions.
- [Starting runs](../building-automations/starting-runs.md) covers manual, trigger, sub-flow, idempotency, and recovery semantics.
- [Editor](../editor-and-run-view/editor.md) covers authoring and the one-time webhook secret UI.
