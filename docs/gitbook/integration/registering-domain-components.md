# Registering domain components

Register application-owned nodes, trigger extensions, trigger-source allowlists, and subject attributes from a host service provider. Nodeflow's registries are container singletons, so the editor, publisher, health check, and runtime see the same registrations.

## Provider shape

`nodeflow:install` creates this dependency order:

```php
<?php

namespace App\Providers;

use App\Nodeflow\Nodes\SendReceipt;
use App\Nodeflow\Triggers\OrderModelSource;
use App\Nodeflow\Triggers\OrderPlacedSource;
use App\Nodeflow\Triggers\OrderWebhookSource;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

final class NodeflowServiceProvider extends ServiceProvider
{
    protected array $nodes = [
        SendReceipt::class,
    ];

    protected array $triggerDrivers = [];
    protected array $triggerNodes = [];
    protected array $triggerSources = [
        OrderWebhookSource::class,
        OrderModelSource::class,
        OrderPlacedSource::class,
    ];

    public function boot(): void
    {
        Nodeflow::register($this->nodes);
        Nodeflow::registerTriggerDrivers($this->triggerDrivers);
        Nodeflow::registerTriggerNodes($this->triggerNodes);
        Nodeflow::registerTriggerSources($this->triggerSources);

        app(SubjectAttributeRegistry::class)->register(
            SubjectAttribute::make(
                'plan',
                'Plan',
                'text',
                fn (object $subject): string => $subject->plan,
            ),
        );
    }
}
```

The package provider unconditionally registers its core executable nodes plus the `webhook`, `model`, and `event` drivers and built-in trigger nodes. The host arrays contain extensions and allowlisted sources only. A source must be registered after its driver because registration calls `TriggerDriver::sourceRegistered()` to attach any deduplicated listener.

## Public registration facade

```php
Nodeflow::nodes();
Nodeflow::register([...]);
Nodeflow::triggerDrivers();
Nodeflow::registerTriggerDrivers([...]);
Nodeflow::triggerNodes();
Nodeflow::registerTriggerNodes([...]);
Nodeflow::triggerSources();
Nodeflow::registerTriggerSources([...]);
Nodeflow::routes();
Nodeflow::webhookRoutes();
```

`register()` is for executable node classes. The three trigger registration methods accept class arrays and enforce the matching public contracts. Driver/source keys use `[a-z][a-z0-9._-]*` with a 191-byte maximum; trigger node types use the same grammar with a 255-byte maximum. Conflicting classes cannot claim the same key. Executable and trigger graph types share one catalog and cannot collide.

## Stable references

Published graphs store executable node types and trigger node types. Activations store driver and source keys. Keep all of them stable while any published version or live run refers to them.

Executable `NodeRegistry::alias($old, $new)` supports one-hop migrations. Point every historical name directly at the current node; do not create alias chains. Trigger drivers, nodes, and sources have no alias operation. Keep their stable keys registered until affected flows are republished and old live runs finish.

Run `php artisan nodeflow:check-node-types` during deployment. It checks executable types referenced by versions with live runs and trigger node, driver, and source registrations referenced by active flow activations. Missing trigger registrations are actionable even when no run exists yet because the next occurrence would otherwise be lost.

## Trigger source allowlists

Registering a model source calls its `modelClass()` and installs one listener set for that exact Eloquent class. Registering a Laravel event source calls `eventClass()` and installs one listener for that exact concrete class. Webhook sources do not expose a route; `Nodeflow::webhookRoutes()` is a separate host opt-in.

Authors receive only serialized definitions and source keys from the server palette. They cannot provide PHP classes. Complete source classes and custom node/driver examples are in [Writing triggers](../building-automations/writing-triggers.md).

## Registration timing

Register components in a provider's `boot()` after the package has populated built-ins. Bind `TenantResolver` and `SubjectResolver` in `register()` so web, queue, console, and trigger listeners all resolve the same contracts. Avoid conditional request-only registration: long-lived workers and event listeners need the same catalog as publication.

For extracted Composer node packages, let their provider register package-owned nodes/extensions, and keep application-only sources in the application provider. See [Creating packages](../node-packages/creating-packages.md).
