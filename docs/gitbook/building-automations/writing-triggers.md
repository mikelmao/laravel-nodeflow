# Writing triggers

> **Experimental:** Nodeflow is pre-release software. A trigger starts real work from application events, so make event delivery and any downstream side effects safe to repeat.

A trigger turns one host event into tenant-specific audiences and starts every matching active flow. Its stable type is stored on flows; keep that value unchanged while any flow uses it.

Generate a trigger with its event class and an explicit, domain-owned type:

```bash
php artisan nodeflow:make-trigger FloodAlertTrigger --event="App\\Events\\FloodAlertFires" --type=app.flood_alert
```

The command is `nodeflow:make-trigger {name}`. It writes the class to `app/Nodeflow/Triggers/<Name>.php` by default and offers these options:

| Option | Default | Validation and behavior |
| --- | --- | --- |
| `--event=` | none | Required when the command cannot prompt. An interactive command prompts for it. A class or interface that is not yet loadable produces a warning but still generates the class; `::class` needs no loaded class. Use the exact class dispatched by Laravel: a wrong name attaches a listener that never receives an event. |
| `--type=` | Interactive: class name converted to snake case; non-interactive: the same value with a warning | Must match lowercase letters and digits in dot- or underscore-separated segments, such as `app.flood_alert`. `core.` is reserved. The command refuses a type already owned by another registered trigger, but direct duplicate registration can still replace it. |
| `--force`, `-f` | off | Overwrites an existing generated class. |

When the generated provider contains exactly one trigger anchor, the command also adds the class to its `$triggers` array. Otherwise it prints the registration call. Generation without `--event` fails before it writes a file; a missing event class only warns because creating the event after the trigger is a valid order of work.

## Implement the event and trigger

Define the host event first so its public data is a deliberate contract for the trigger.

**File: `app/Events/FloodAlertFires.php`**

```php
<?php

namespace App\Events;

class FloodAlertFires
{
    /** @param array<string, list<int|string>> $residentIdsByOrganization */
    public function __construct(
        public readonly string $alertId,
        public readonly string $severity,
        public readonly array $residentIdsByOrganization,
    ) {}
}
```

**File: `app/Nodeflow/Triggers/FloodAlertTrigger.php`**

```php
<?php

namespace App\Nodeflow\Triggers;

use App\Events\FloodAlertFires;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;

class FloodAlertTrigger extends Trigger
{
    public static function type(): string
    {
        return 'app.flood_alert';
    }

    public static function event(): string
    {
        return FloodAlertFires::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Flood alert')
            ->description('Start for residents affected by a flood alert.')
            ->fields([
                Field::select('minimum_severity')
                    ->label('Minimum severity')
                    ->options([
                        'moderate' => 'Moderate',
                        'severe' => 'Severe',
                    ])
                    ->required(),
            ]);
    }

    public function resolve(object $event): TriggerMatch
    {
        /** @var FloodAlertFires $event */
        $match = TriggerMatch::make();

        foreach ($event->residentIdsByOrganization as $organizationId => $residentIds) {
            $match->forTenant(
                tenantId: (string) $organizationId,
                subjectType: 'resident',
                subjectIds: $residentIds,
            );
        }

        return $match;
    }

    public function matchesConfig(object $event, array $config): bool
    {
        /** @var FloodAlertFires $event */
        return match ($config['minimum_severity'] ?? null) {
            'moderate' => in_array($event->severity, ['moderate', 'severe'], true),
            'severe' => $event->severity === 'severe',
            default => false,
        };
    }

    public function idempotencyKey(object $event): ?string
    {
        /** @var FloodAlertFires $event */
        return 'flood-alert:'.$event->alertId;
    }
}
```

**Abstract method signatures (partial):** `Trigger` requires these exact methods:

```php
public static function type(): string;
public static function event(): string;
public function definition(): TriggerDefinition;
public function resolve(object $event): TriggerMatch;
```

`type()` is the stored, stable trigger type. `event()` returns the event class to listen for. `definition()` supplies the editor label, optional description, and fields. `resolve()` returns the audience selected from one event. The inherited `matchesConfig(object $event, array $config): bool` accepts every event by default; override it when each flow's `trigger_config` should narrow the event. The inherited `idempotencyKey(object $event): ?string` returns `null`; override it only with a stable identity for this event delivery, such as the alert ID above.

`TriggerMatch::forTenant(string $tenantId, string $subjectType, iterable $subjectIds): self` records one audience per tenant. Its stored shape is:

```php
[
    'organization-42' => [
        'subject_type' => 'resident',
        'subject_ids' => ['101', '102'],
    ],
]
```

Calling `forTenant()` again for the same tenant replaces that tenant's earlier audience; merge IDs before calling it when one event has several sources for that tenant. The match preserves the supplied IDs until run materialization. Materialization then string-normalizes them and removes repeated IDs.

## Register at application boot

**File: `app/Providers/NodeflowServiceProvider.php` (partial `boot()` method)**

```php
app(\Nodeflow\Triggers\TriggerRegistry::class)->register(
    \App\Nodeflow\Triggers\FloodAlertTrigger::class,
);
```

Register in a provider's `boot()` method. Registration attaches the Laravel event listener immediately, so provider boot ordering does not require the package provider to run later. An unregistered trigger is absent from both the editor palette and event processing.

There is at most one listener per distinct event class. If two registered trigger classes return the same `event()` class, the one listener asks the registry for all triggers for that event and handles each exactly once. Trigger types are registry keys: direct registration of another class with the same type silently replaces the earlier class. Use one owner for every stable type; the generator refuses known collisions to prevent the usual case.

## Know what a fired event starts

For each trigger selected by the event class, Nodeflow calls `resolve()`, then processes every tenant audience independently. Within that tenant it finds flows that are all of the following:

- owned by that tenant;
- `active`;
- configured with the trigger's stable type; and
- holding a current published version.

It calls `matchesConfig()` for each candidate flow and starts one run for each flow that returns `true`. A failure while one flow or tenant is starting is reported to Laravel's error handler and does not stop the remaining flows or tenant audiences. `resolve()` and `matchesConfig()` themselves are outside that per-flow `try` block, so make them deterministic and handle expected bad event data before returning a match.

The trigger's idempotency key is passed to every started run. Repeated delivery returns an existing run when the same **flow version** and non-null key already exist. It does not deduplicate across different flow versions: publishing a new version intentionally gives the same event key a separate idempotency scope. With a `null` key, every delivery can create another run.

The database unique constraint backs the pre-check. Two concurrent deliveries can both see no run; one insert wins and the loser re-queries and returns that winner after a matching unique-constraint error. This removes duplicate run rows for the same `(flow_version_id, idempotency_key)`, not duplicate external effects that a node may already have performed. Nodes must still make their own side effects idempotent.

## Next step

Expose only safe branching data to authors with [Subject attributes](subject-attributes.md).
