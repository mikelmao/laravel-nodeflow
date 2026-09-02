# Laravel Nodeflow

Laravel Nodeflow is a visual workflow builder and durable Laravel execution engine. Applications own tenants, subjects, authorization, trigger sources, and domain nodes; Nodeflow owns graph authoring, immutable publication, idempotent run creation, durable dispatch, and inspection.

> **Experimental:** the package is pre-release. Test every workflow and side effect before production use.

## What a flow starts with

Every publishable graph contains exactly one trigger node. `graph.start` names that trigger, the trigger has no incoming edges, and it has exactly one `started` edge to an executable node. Trigger nodes are declarative and are never executed by the interpreter.

Three trigger node types ship by default:

| Type | Driver | Starts from |
| --- | --- | --- |
| `core.trigger.webhook` | `webhook` | An authenticated public webhook request |
| `core.trigger.model_observer` | `model` | An allowlisted Eloquent `created`, `updated`, `deleted`, or `restored` event |
| `core.trigger.laravel_event` | `event` | An allowlisted concrete Laravel event class |

The built-in drivers and graph nodes are registered unconditionally. A host registers only explicit, application-owned source classes. This prevents authors from entering arbitrary model names, event names, or service classes.

## Install

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan nodeflow:install --check
```

Mount authenticated editor routes in a host-owned group. Mount the webhook route separately so its domain, rate limiting, and public middleware are deliberate:

```php
use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('admin/nodeflow')
    ->group(fn () => Nodeflow::routes());

Route::middleware(['api', 'throttle:webhooks'])
    ->domain('hooks.example.com')
    ->group(fn () => Nodeflow::webhookRoutes());
```

Register host trigger sources after Nodeflow's provider has registered the built-in drivers and nodes:

```php
public function boot(): void
{
    Nodeflow::registerTriggerSources($this->triggerSources);
}
```

The editor shows an empty-state explanation until a compatible source is registered. Publishing compiles the selected source and routing metadata into an immutable activation pinned to the new flow version.

## Extensible by contract

Applications can implement `TriggerSource` for an existing driver, subclass `AbstractTriggerNode` to give an existing driver a new authoring shape, or implement `TriggerDriver` and pair it with a reference trigger node. Register dependencies in driver → node → source order:

```php
Nodeflow::registerTriggerDrivers($this->triggerDrivers);
Nodeflow::registerTriggerNodes($this->triggerNodes);
Nodeflow::registerTriggerSources($this->triggerSources);
```

Generate the corresponding starting points with `nodeflow:make-trigger`, `nodeflow:make-trigger-source`, and `nodeflow:make-trigger-driver`.

## Documentation

- [Quick start](docs/gitbook/getting-started/quick-start.md)
- [Writing triggers](docs/gitbook/building-automations/writing-triggers.md)
- [Provider-backed facts](docs/gitbook/building-automations/provider-backed-facts.md)
- [Starting runs](docs/gitbook/building-automations/starting-runs.md)
- [Route reference](docs/gitbook/reference/routes.md)
- [Database schema](docs/gitbook/reference/database-schema.md)
- [Testing](docs/gitbook/contributing/testing.md)

## Deliberate limits

Schedules are not supported. Unsigned webhooks are not supported. Authors cannot enter arbitrary model or event class names. Expression interpolation is not supported. Multiple trigger nodes are not supported. Eloquent query-builder bulk updates are not observed.

## License

MIT.
