# 4. Writing triggers

A trigger turns one of your existing Laravel events into something a customer can build a journey on.
You do not dispatch anything new; you declare that an event your application already fires is an
authoring surface.

## Scaffold one

```bash
php artisan nodeflow:make-trigger FloodAlertFires \
    --event='App\Events\FloodAlertDispatched' \
    --type=rada.flood_alert
```

That writes `app/Nodeflow/Triggers/FloodAlertFires.php` with the four required
methods and `idempotencyKey()` / `matchesConfig()` commented out, and appends the
class to your provider's `$triggers` array.

`--event` is the part worth care. Registering a trigger calls `Event::listen()`
for that class, at the moment of registration — so naming the wrong class raises
no error at all: the listener attaches to an event that never fires and the
trigger is simply silent. The command warns when the class cannot be found, but it
still generates the file, because writing the trigger before the event is a normal
order of work.

## A complete trigger

```php
namespace App\Nodeflow\Triggers;

use App\Events\OrderPlaced as OrderPlacedEvent;
use App\Models\User;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;

class OrderPlaced extends Trigger
{
    public static function type(): string
    {
        return 'app.order_placed';
    }

    public static function event(): string
    {
        return OrderPlacedEvent::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('An order is placed')
            ->description('Starts when a customer completes checkout.')
            ->fields([
                Field::number('minimum_total')->label('Only orders above')->default(0),
            ]);
    }

    public function resolve(object $event): TriggerMatch
    {
        return TriggerMatch::make()->forTenant(
            (string) $event->order->organization_id,
            'user',
            [(string) $event->order->user_id],
        );
    }

    public function idempotencyKey(object $event): ?string
    {
        return 'order-'.$event->order->id;
    }

    public function matchesConfig(object $event, array $config): bool
    {
        return $event->order->total >= ($config['minimum_total'] ?? 0);
    }
}
```

Register it and every active flow whose `trigger_type` is `app.order_placed` starts a run when the
event fires.

## The four methods

**`type()`** — the stable string stored on a flow as its `trigger_type`. Same rules as a node type:
never derive it from the class name.

**`event()`** — the FQCN of the host event to listen for. Registration attaches exactly one listener per
distinct event class, so two triggers sharing an event is fine and does not double-process it.

**`definition()`** — label, description, and config fields the author fills in. These are the filters:
"only orders above £50", "only red and orange severity". Same `Field` builders as nodes.

**`resolve(object $event): TriggerMatch`** — the important one. Turn the event into *which tenants, and
which subjects for each*.

## One event, many tenants

```php
public function resolve(object $event): TriggerMatch
{
    $match = TriggerMatch::make();

    $affected = User::query()
        ->whereIn('town_id', $event->townIds)
        ->get()
        ->groupBy('organization_id');

    foreach ($affected as $tenantId => $users) {
        $match->forTenant(
            (string) $tenantId,
            'user',
            $users->pluck('id')->map(fn ($id) => (string) $id)->all(),
        );
    }

    return $match;
}
```

This is the shape that matters. One event resolving to three tenants produces **three independent
runs** — separately versioned, separately cancellable, each isolated. If one tenant's run fails to
start, the others still start; the failure is reported through your application's exception handler and
the loop continues.

Cast tenant ids and subject ids to **strings**. The package works in strings throughout.

## Optional hooks

**`idempotencyKey(object $event): ?string`** — defaults to `null`. Return something stable and the
package will not create a second run for the same (flow version, key) pair, which makes a redelivered
event safe. Returning `null` means every firing creates a new run — correct for genuinely independent
occurrences, wrong for at-least-once delivery. **If your event can be redelivered, implement this.**

**`matchesConfig(object $event, array $config): bool`** — defaults to `true`. Compare the event against
the flow's own trigger config so each flow can filter independently. Two flows on the same event with
different thresholds both get consulted.

## The other three ways a run starts

**Manually**, which is how anything gets tested:

```php
use Nodeflow\Execution\StartRun;

app(StartRun::class)->forFlow(
    $flow,
    'user',
    ['1', '2', '3'],
    ['is_test' => true],
);
```

Options: `is_test`, `correlation_id`, `idempotency_key`, `strategy`.

**From another flow**, via the `core.start_flow` node — a journey that hands its subjects to a
different journey. Depth-limited to 5 levels using the `correlation_id` as a lineage chain, so a flow
that eventually starts itself stops rather than becoming a fork bomb with durable state. A sub-flow
lookup is scoped to the parent run's tenant, so a graph naming another tenant's flow id cannot start
it.

**On a schedule**, by calling `StartRun` from your own scheduled command with an audience you resolve.

## Registration and its one gotcha

```php
app(TriggerRegistry::class)->register(OrderPlaced::class);
```

`register()` attaches the event listener **at the moment you call it**. Put it in a service provider's
`boot()`. If you register somewhere conditional that may not run, the trigger silently never fires —
the flow looks active, the event fires, and nothing happens.

## Checklist

- [ ] `type()` stable, `event()` points at a real event class
- [ ] `resolve()` groups subjects by tenant and casts ids to strings
- [ ] `idempotencyKey()` implemented if the event can be redelivered
- [ ] Registered in `boot()`, not lazily
- [ ] A test that dispatches the event for real (`Event::dispatch(...)`) and asserts a run appeared —
      calling the listener directly would not catch a registration-timing mistake
