# Writing triggers

Triggers are a three-part extension boundary:

- a `TriggerNode` defines the graph authoring shape and compiles immutable routing metadata;
- a `TriggerDriver` owns how occurrences arrive and validates its descriptors;
- a `TriggerSource` is a host allowlist entry that turns a typed occurrence into tenant audiences.

At runtime, `TriggerSource::resolve()` receives the activation descriptor metadata compiled by the node, not an untrusted request and not necessarily the complete raw authoring config. A source should branch only on fields its compatible node deliberately compiled.

Nodeflow registers the `webhook`, `model`, and `event` drivers and their built-in trigger nodes unconditionally. The host registers sources explicitly. Flow authors therefore select stable keys; they never submit a PHP model class, event class, listener class, or arbitrary service name.

## Graph invariant

A publishable graph contains exactly one trigger node. `graph.start` must name it. The trigger accepts no incoming edge and has exactly one `started` edge to an executable entry node. Multiple trigger nodes are not supported. Trigger nodes are declarative and are never executed; the run starts at their `started` target.

Built-in graph types are `core.trigger.webhook`, `core.trigger.model_observer`, and `core.trigger.laravel_event`. The server supplies trigger-node and compatible-source palettes to the editor. A driver with no registered compatible source displays an empty state rather than an author-controlled class input.

Stable driver and source keys use `[a-z][a-z0-9._-]*` and are at most 191 bytes. Graph node types use the same grammar and are at most 255 bytes. Registration rejects collisions. Source fields are combined into the node as flat config; source keys must not collide with node-owned fields. Dots in a field key are literal keys, not nested-object paths.

## Webhook source

This complete source accepts a signed JSON order delivery. The webhook driver supplies the request's `Idempotency-Key` as the occurrence identity, so the source cannot override it.

```php
<?php

namespace App\Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\Webhook\WebhookOccurrence;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

final class OrderWebhookSource implements WebhookTriggerSource
{
    public static function key(): string
    {
        return 'shop.order_webhook';
    }

    public static function driver(): string
    {
        return WebhookTriggerDriver::key();
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Order webhook')
            ->description('Starts for an allowlisted order delivery.');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof WebhookOccurrence) {
            throw new InvalidArgumentException('Expected a webhook occurrence.');
        }

        $payload = $occurrence->payload->payload;
        $tenantId = $payload['tenant_id'] ?? null;
        $orderId = $payload['order_id'] ?? null;

        if (! is_string($tenantId) || ! is_string($orderId)) {
            throw new InvalidArgumentException('The webhook payload is incomplete.');
        }

        return TriggerMatch::make()->forTenant(
            tenantId: $tenantId,
            subjectType: 'order',
            subjectIds: [$orderId],
            triggerData: [
                'delivery_id' => $occurrence->payload->deliveryId,
                'event' => $payload['event'] ?? null,
            ],
        );
    }
}
```

For each webhook activation the source must return exactly one non-empty audience for that activation's tenant. A missing audience, a second audience, a tenant mismatch, or a subject rejected by `TenantResolver::ownsSubject()` produces a sanitized `422` response. Keep validation messages free of secrets and raw payloads.

### Sign and send the request

Public webhook routes are opt-in:

```php
Route::middleware(['api', 'throttle:webhooks'])
    ->domain('hooks.example.com')
    ->group(fn () => \Nodeflow\Nodeflow::webhookRoutes());
```

The route is `POST hooks/{token}` with name `nodeflow.webhooks.receive`. A flow's first webhook publication creates a stable endpoint token and encrypted signing secret. The publish response shows the plaintext secret once. Later publications return no secret. The authenticated rotation route replaces it, invalidates the old secret immediately, returns the replacement once, and sets `Cache-Control: no-store` and `Pragma: no-cache`.

Send these exact headers:

- `X-Nodeflow-Timestamp`: a base-10 Unix timestamp inside `nodeflow.webhooks.replay_window_seconds` (default 300);
- `X-Nodeflow-Signature`: `sha256=` followed by the lowercase HMAC hex;
- `Idempotency-Key`: a nonblank delivery identity of at most 255 bytes.

The signed message is `timestamp`.`raw request body`. Do not re-encode JSON before signing:

```php
$timestamp = (string) time();
$body = json_encode($payload, JSON_THROW_ON_ERROR);
$signature = 'sha256='.hash_hmac(
    'sha256',
    $timestamp.'.'.$body,
    $secret,
);

Http::withHeaders([
    'Content-Type' => 'application/json',
    'X-Nodeflow-Timestamp' => $timestamp,
    'X-Nodeflow-Signature' => $signature,
    'Idempotency-Key' => $deliveryId,
])->withBody($body, 'application/json')->post($endpointUrl);
```

The body is rejected before HMAC work when it exceeds `nodeflow.webhooks.max_body_bytes` (default 1 MiB). Responses are:

| Status | Meaning |
| --- | --- |
| `202 Accepted` | A run was created or an idempotent duplicate was found; JSON contains `run_id` and `duplicate`. |
| `404` | The token is unknown, inactive, or no longer points to a webhook activation. |
| `401` | Signature headers are missing/malformed, outside the replay window, or the HMAC is invalid. |
| `413` | Raw body exceeds the configured byte limit. |
| `422` | Idempotency is invalid, JSON is malformed/not an array, or the source cannot resolve its one tenant audience. |
| `503` | Verification configuration, registration, source execution, or durable run dispatch is unavailable; retry with the same idempotency key. |

Unsigned webhooks are not supported. Put rate limiting, trusted proxy handling, domain restrictions, and any additional middleware in the host route group. Never log the signing secret, signature, token, or raw request body; Nodeflow's public errors and stored dispatch errors are intentionally sanitized.

## Model observer source

The built-in model driver observes only `created`, `updated`, `deleted`, and `restored`. `modelClass()` is the allowlist; authors choose only the source and event. For `updated`, optional `changed_fields` starts an activation when at least one configured name changed. Eloquent query-builder bulk updates are not observed because they emit no model lifecycle event.

```php
<?php

namespace App\Nodeflow\Triggers;

use App\Models\Order;
use InvalidArgumentException;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerDriver;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelOccurrence;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

final class OrderModelSource implements ModelObserverTriggerSource
{
    public static function key(): string
    {
        return 'shop.order_model';
    }

    public static function driver(): string
    {
        return ModelObserverTriggerDriver::key();
    }

    public static function modelClass(): string
    {
        return Order::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Order lifecycle');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof ModelOccurrence) {
            throw new InvalidArgumentException('Expected a model occurrence.');
        }

        $model = $occurrence->payload;
        $tenantId = $model->attributes['tenant_id'] ?? null;

        if (! is_string($tenantId)) {
            throw new InvalidArgumentException('Order tenant is missing.');
        }

        $identity = hash('sha256', json_encode([
            $model->event,
            $model->modelKey,
            $model->attributes,
            $model->original,
            $model->changedFields,
        ], JSON_THROW_ON_ERROR));

        return TriggerMatch::make()->forTenant(
            tenantId: $tenantId,
            subjectType: 'order',
            subjectIds: [$model->modelKey],
            triggerData: [
                'event' => $model->event,
                'changed_fields' => $model->changedFields,
                'status' => $model->attributes['status'] ?? null,
            ],
            occurrenceId: $identity,
        );
    }
}
```

Nodeflow snapshots the model key, connection name, attributes, raw originals, and changed field names into immutable value data before registering the callback. Scalars, arrays, backed enums, dates, JSON-serializable values, and strings are normalized; unsupported objects reject the observation without vetoing persistence. The source controls the smaller, sanitized `trigger_data` stored on the run.

Delivery occurs after the outermost database transaction commits. If no transaction is open, Laravel runs the callback immediately. A rollback emits no run. The snapshot and matching activation versions are captured before the callback, so later model mutation or flow publication cannot move that occurrence. Use the model's emitting connection; do not assume the default database connection.

## Laravel event source

`eventClass()` must return one existing, concrete, instantiable class. Nodeflow attaches one shared listener per exact event class and fans out to registered sources and tenant activations. Interfaces, abstract classes, parent-class catch-alls, and author-entered class strings are not supported.

```php
<?php

namespace App\Nodeflow\Triggers;

use App\Events\OrderPlaced;
use InvalidArgumentException;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;

final class OrderPlacedSource implements LaravelEventTriggerSource
{
    public static function key(): string
    {
        return 'shop.order_placed';
    }

    public static function driver(): string
    {
        return LaravelEventTriggerDriver::key();
    }

    public static function eventClass(): string
    {
        return OrderPlaced::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Order placed');
    }

    public function snapshot(object $event): LaravelEventOccurrence
    {
        if (! $event instanceof OrderPlaced) {
            throw new InvalidArgumentException('Expected OrderPlaced.');
        }

        return new LaravelEventOccurrence(OrderPlaced::class, [
            'order_id' => (string) $event->orderId,
            'tenant_id' => (string) $event->tenantId,
            'customer_id' => (string) $event->customerId,
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        if (! $occurrence->payload instanceof LaravelEventOccurrence) {
            throw new InvalidArgumentException('Expected a Laravel event occurrence.');
        }

        $data = $occurrence->payload->data;

        return TriggerMatch::make()->forTenant(
            tenantId: (string) $data['tenant_id'],
            subjectType: 'customer',
            subjectIds: [(string) $data['customer_id']],
            triggerData: ['order_id' => (string) $data['order_id']],
            occurrenceId: 'order-placed:'.(string) $data['order_id'],
        );
    }
}
```

The source owns the explicit value-only snapshot. Nodeflow performs no reflection-based property extraction and no automatic event serialization. `LaravelEventOccurrence` accepts JSON-safe scalar/array data and rejects objects, resources, non-finite floats, recursive arrays, excessive depth, and excessive value count.

Laravel event delivery follows Laravel's normal synchronous timing. Nodeflow does not defer it automatically. If a source must observe committed state, the host should implement Laravel's after-commit event contract or dispatch after commit. One source/activation failure is reported and isolated so it cannot abort other sources or the host's event dispatch.

## Register sources and extensions

Keep explicit arrays in the host provider. Built-ins need not be repeated:

```php
use App\Nodeflow\Triggers\OrderModelSource;
use App\Nodeflow\Triggers\OrderPlacedSource;
use App\Nodeflow\Triggers\OrderWebhookSource;
use Nodeflow\Nodeflow;

protected array $triggerDrivers = [];
protected array $triggerNodes = [];
protected array $triggerSources = [
    OrderWebhookSource::class,
    OrderModelSource::class,
    OrderPlacedSource::class,
];

public function boot(): void
{
    Nodeflow::registerTriggerDrivers($this->triggerDrivers);
    Nodeflow::registerTriggerNodes($this->triggerNodes);
    Nodeflow::registerTriggerSources($this->triggerSources);
}
```

Registration order is driver → node → source because source registration calls `TriggerDriver::sourceRegistered()`. Registering the same key for another class is rejected; registration is not a last-writer-wins override.

## Custom node on a built-in driver

A custom node can reuse webhook delivery while adding an authoring field. `AbstractTriggerNode` supplies source selection and source-registry validation; the subclass owns definition, compatibility, and deterministic compilation.

```php
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

final class PartnerWebhookTrigger extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'shop.trigger.partner_webhook';
    }

    protected function sourceType(): string
    {
        return WebhookTriggerSource::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Partner webhook')->fields([
            Field::select('source')->required(),
            Field::select('region')->required()->options([
                'eu' => 'Europe',
                'us' => 'United States',
            ]),
        ]);
    }

    public function driver(): string
    {
        return WebhookTriggerDriver::key();
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            driver: $this->driver(),
            source: $this->source($config),
            qualifier: null,
            metadata: ['region' => (string) $config['region']],
        );
    }
}
```

Register it with `Nodeflow::registerTriggerNodes([PartnerWebhookTrigger::class]);`. Its sources must still implement `WebhookTriggerSource`, and the built-in webhook protocol remains signed.

## Custom driver and reference node

Generate an atomic extension kit:

```bash
php artisan nodeflow:make-trigger-driver QueueTriggerDriver --key=queue
```

The generated driver implements the public contract and the generated node references its stable key:

```php
use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerOccurrence;

final class QueueTriggerDriver implements TriggerDriver
{
    public static function key(): string
    {
        return 'queue';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        // Attach one deduplicated, extension-owned listener here.
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        return $descriptor->driver === self::key()
            ? []
            : ['driver' => ['The descriptor belongs to another driver.']];
    }

    public function occurrence(string $source, mixed $payload): TriggerOccurrence
    {
        return new TriggerOccurrence(self::key(), $source, $payload);
    }
}
```

```php
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;

final class QueueTrigger extends AbstractTriggerNode
{
    public static function type(): string
    {
        return 'queue.trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Queue')->fields([
            Field::select('source')->required(),
        ]);
    }

    public function driver(): string
    {
        return QueueTriggerDriver::key();
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            driver: $this->driver(),
            source: $this->source($config),
            qualifier: null,
            metadata: [],
        );
    }
}
```

Register the extension in dependency order:

```php
Nodeflow::registerTriggerDrivers([QueueTriggerDriver::class]);
Nodeflow::registerTriggerNodes([QueueTrigger::class]);
Nodeflow::registerTriggerSources([QueueMessageSource::class]);
```

The `TriggerDriver` contract intentionally does not prescribe transport-specific dispatch methods. An extension owns its listener or adapter, creates trusted `TriggerOccurrence` values, snapshots activation candidates before extension code can mutate publication state, and hands them to `TriggerOccurrenceDispatcher`. The shared dispatcher validates pinned descriptors, isolates per-activation failures, enforces tenant matching, and starts exact pinned versions.

## Unsupported authoring shortcuts

Schedules are not supported. Unsigned webhooks are not supported. Arbitrary model/event class author input is not supported. Expression interpolation is not supported. Multiple trigger nodes are not supported. Eloquent query-builder bulk updates are not observed.

Next, read [Starting runs](starting-runs.md) for persistence, idempotency, and recovery semantics.
