# Flood-alert workflow

This example starts one tenant-isolated run per organization when the host dispatches its allowlisted `FloodAlertDispatched` Laravel event. It uses the application models, resolvers, `SendMessage` node, and event from [Application setup](application-setup.md).

## Implement the allowlisted source

The source explicitly snapshots value data from the exact concrete event. It does not hand the event object to durable execution, reflect over arbitrary properties, or automatically serialize it.

**File: `app/Nodeflow/Triggers/FloodAlertSource.php`**

```php
<?php

namespace App\Nodeflow\Triggers;

use App\Events\FloodAlertDispatched;
use InvalidArgumentException;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

final class FloodAlertSource implements LaravelEventTriggerSource
{
    public static function key(): string
    {
        return 'flood.alert_dispatched';
    }

    public static function driver(): string
    {
        return LaravelEventTriggerDriver::key();
    }

    public static function eventClass(): string
    {
        return FloodAlertDispatched::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Flood alert dispatched');
    }

    public function snapshot(object $event): LaravelEventOccurrence
    {
        if (! $event instanceof FloodAlertDispatched) {
            throw new InvalidArgumentException('Expected FloodAlertDispatched.');
        }

        return new LaravelEventOccurrence(FloodAlertDispatched::class, [
            'alert_id' => (string) $event->alertId,
            'severity' => (string) $event->severity,
            'user_ids_by_organization' => array_map(
                static fn (array $ids): array => array_map('strval', $ids),
                $event->userIdsByOrganization,
            ),
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof LaravelEventOccurrence) {
            throw new InvalidArgumentException('Expected a Laravel event occurrence.');
        }

        $data = $occurrence->payload->data;
        $matches = TriggerMatch::make();

        foreach ($data['user_ids_by_organization'] as $tenantId => $userIds) {
            $matches = $matches->forTenant(
                tenantId: (string) $tenantId,
                subjectType: 'user',
                subjectIds: $userIds,
                triggerData: [
                    'alert_id' => (string) $data['alert_id'],
                    'severity' => (string) $data['severity'],
                ],
                occurrenceId: 'flood-alert:'.(string) $data['alert_id'],
            );
        }

        return $matches;
    }
}
```

The application creates one activation per tenant flow. The shared listener snapshots every matching activation before it calls source code, then selects the audience with the same tenant ID. `TenantResolver::ownsSubject()` verifies every user before materialization. An incorrect organization map is therefore rejected for that activation and isolated from the others.

Laravel events are synchronous. Dispatch `FloodAlertDispatched` only after the application data is committed, or make the event use Laravel's after-commit event contract. Nodeflow does not impose a second transaction-timing policy on host events.

## Register the source

The built-in event driver and `core.trigger.laravel_event` node are already registered. Add only the host source:

```php
use App\Nodeflow\Nodes\SendMessage;
use App\Nodeflow\Triggers\FloodAlertSource;
use Nodeflow\Nodeflow;

protected array $nodes = [SendMessage::class];
protected array $triggerDrivers = [];
protected array $triggerNodes = [];
protected array $triggerSources = [FloodAlertSource::class];

public function boot(): void
{
    Nodeflow::register($this->nodes);
    Nodeflow::registerTriggerDrivers($this->triggerDrivers);
    Nodeflow::registerTriggerNodes($this->triggerNodes);
    Nodeflow::registerTriggerSources($this->triggerSources);
}
```

## Build the graph

The graph begins with one declarative trigger. The `started` edge enters `app.send_message`; the message node's `sent` edge terminates at the executable `core.exit` node.

**File: `app/Support/FloodAlertGraph.php`**

```php
<?php

namespace App\Support;

final class FloodAlertGraph
{
    public static function definition(): array
    {
        return [
            'start' => 'flood-alert',
            'nodes' => [
                [
                    'id' => 'flood-alert',
                    'type' => 'core.trigger.laravel_event',
                    'config' => ['source' => 'flood.alert_dispatched'],
                    'position' => ['x' => 80, 'y' => 120],
                ],
                [
                    'id' => 'send-alert',
                    'type' => 'app.send_message',
                    'config' => ['message' => 'flood_alert'],
                    'position' => ['x' => 420, 'y' => 120],
                ],
                [
                    'id' => 'finish',
                    'type' => 'core.exit',
                    'config' => [],
                    'position' => ['x' => 760, 'y' => 120],
                ],
            ],
            'edges' => [
                [
                    'from' => 'flood-alert',
                    'output' => 'started',
                    'to' => 'send-alert',
                ],
                [
                    'from' => 'send-alert',
                    'output' => 'sent',
                    'to' => 'finish',
                ],
            ],
        ];
    }
}
```

This satisfies the publication invariant: exactly one trigger node, `graph.start` equals its ID, no incoming trigger edge, and exactly one `started` edge to an executable node.

## Provision one flow per organization

Create the flow in a tenant-aware context and publish the graph with its current draft revision:

```php
use App\Support\FloodAlertGraph;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

$flow = Flow::create([
    'name' => 'Flood alert',
    'status' => 'draft',
]);

$result = app(PublishFlow::class)->publish(
    $flow,
    FloodAlertGraph::definition(),
    publishedBy: (string) auth()->id(),
    expectedDraftRevision: (int) $flow->draft_revision,
);
```

Publication stores an immutable event activation with driver `event`, source `flood.alert_dispatched`, the tenant, trigger node `flood-alert`, and the new version. Re-publishing replaces only the current activation; an event already being delivered stays pinned to its captured version.

## Dispatch the alert

```php
event(new \App\Events\FloodAlertDispatched(
    alertId: (string) $alert->getKey(),
    severity: (string) $alert->severity,
    userIdsByOrganization: $userIdsByOrganization,
));
```

Each matching run records `started_via = event`, `trigger_node_id = flood-alert`, and sanitized alert data in `trigger_data`. The stable alert occurrence identity deduplicates redelivery per flow version. The interpreter begins at `send-alert`; it never executes the trigger node.

See [Testing the workflow](testing-the-workflow.md) for focused publication and dispatch assertions.
