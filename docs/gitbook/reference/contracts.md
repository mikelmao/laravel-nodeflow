# Public contracts

This page lists the host integration surface. Classes outside these contracts, facades, models, authoring/start services, and documented value objects are package implementation details unless another reference page explicitly exposes them.

## Host resolvers

```php
interface TenantResolver
{
    public function currentTenantId(): ?string;
    public function ownsSubject(
        string $tenantId,
        string $subjectType,
        string $subjectId,
    ): bool;
}

interface SubjectResolver
{
    /** @return array<string, mixed> keyed by subject ID */
    public function resolve(string $subjectType, array $subjectIds): array;
}

interface AudienceResolver
{
    public function subjectType(): string;
    public function subjectIds(string $tenantId, array $payload): iterable;
}
```

`TenantResolver::ownsSubject()` is a mandatory security boundary for every subject materialized by trigger starts. Bind resolvers unconditionally in a provider's `register()` method so requests, listeners, queue workers, and console commands share the same behavior.

## Executable nodes

Subclass `Nodeflow\Nodes\Node` and implement `HandlesSubject`, `HandlesAudience`, or both:

```php
abstract class Node
{
    public static function type(): string;
    public function definition(): NodeDefinition;
    public function defaultConfig(): array;
    public function validate(array $config): array;
    public int $tries = 3;
}

interface HandlesSubject
{
    public function forSubject(SubjectContext $context): NodeResult;
}

interface HandlesAudience
{
    public function forAudience(AudienceContext $context): NodeResult;
}
```

`SubjectContext` exposes `runId()`, `correlationId()`, `nodeId()`, `subject()`, `subjectId()`, `config()`, `triggerData()`, `isTest()`, `continue()`, and `fail()`. `AudienceContext` exposes the corresponding run/config/trigger methods plus `subjectIds()`, `subjectType()`, `subjects()`, `partition()`, and `all()`. Contexts do not expose the mutable `Run` model.

`NodeResult` factories are `forSubject()`, `partition()`, `failed()`, `empty()`, and `merge()`. Its read methods are `outputs()`, `failures()`, and `subjectCount()`.

## Trigger contracts

```php
namespace Nodeflow\Contracts;

interface TriggerDriver
{
    public static function key(): string;
    public function sourceRegistered(TriggerSource $source): void;
    public function validate(TriggerActivationDescriptor $descriptor): array;
}

interface TriggerNode
{
    public static function type(): string;
    public function definition(): TriggerDefinition;
    public function driver(): string;
    public function defaultConfig(): array;
    public function source(array $config): string;
    public function supportsSource(TriggerSource $source): bool;
    public function validate(array $config, TriggerSourceRegistry $sources): array;
    public function compile(array $config): TriggerActivationDescriptor;
}

interface TriggerSource
{
    public static function key(): string;
    public static function driver(): string;
    public function definition(): TriggerDefinition;
    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch;
}
```

`AbstractTriggerNode` is the recommended base for host nodes. It implements empty defaults, reads the flat `source` key, checks driver/source compatibility, and validates combined node/source fields. Subclasses provide a stable type, definition, driver, optional narrower `sourceType()`, and pure deterministic `compile()`.

The stable-key grammar is `[a-z][a-z0-9._-]*`. Driver/source keys are limited to 191 bytes; trigger node types are limited to 255 bytes. A trigger definition has exactly one output, `started`.

### Built-in source contracts

```php
use Nodeflow\Contracts\TriggerSource as SourceContract;

interface WebhookTriggerSource extends SourceContract
{
}

interface ModelObserverTriggerSource extends SourceContract
{
    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    public static function modelClass(): string;
}

interface LaravelEventTriggerSource extends SourceContract
{
    /** @return class-string */
    public static function eventClass(): string;
    public function snapshot(object $event): LaravelEventOccurrence;
}
```

The built-in source interfaces are marker/narrowing contracts for their drivers. A class that declares the right driver key but not the matching interface is incompatible and cannot publish.

### Occurrences and matches

```php
new TriggerOccurrence(
    string $driver,
    string $source,
    mixed $payload,
    ?string $qualifier = null,
    ?array $activations = null,
);

TriggerMatch::make()->forTenant(
    string $tenantId,
    string $subjectType,
    iterable $subjectIds,
    array $triggerData = [],
    ?string $occurrenceId = null,
);
```

`forTenant()` is immutable: each call returns a new match. Reusing a tenant key replaces that tenant's previous value. `tenants()` returns `TriggerTenantMatch[]`. Tenant ID and subject type must be nonblank, every subject ID must be nonblank, and occurrence identity is null or nonblank.

The `array $config` passed to `TriggerSource::resolve()` is the pinned activation descriptor metadata emitted by `TriggerNode::compile()`. It is not the raw request body or automatically the full graph-node config.

The optional `TriggerOccurrence::$activations` accepts only trusted snapshots from `TriggerActivationRepository`; never deserialize it from a request or event. It pins the versions active at emission time.

Built-in typed payloads are:

```php
new WebhookOccurrence(array $payload, string $deliveryId, int $timestamp);

new ModelOccurrence(
    string $modelClass,
    string $modelKey,
    string $connectionName,
    string $event,
    array $attributes,
    array $original,
    array $changedFields,
);

new LaravelEventOccurrence(string $eventClass, array $data);
```

`LaravelEventOccurrence` validates immutable JSON-safe values. `ModelOccurrence` is created by the model driver from a normalized snapshot. Complete source examples are in [Writing triggers](../building-automations/writing-triggers.md).

## Trigger activation descriptor

```php
new TriggerActivationDescriptor(
    string $driver,
    string $source,
    ?string $qualifier,
    array $metadata,
);
```

`toArray()` returns those four keys. `TriggerNode::compile()` must return the same descriptor for the same validated config. Publication stores `metadata` as the activation's `descriptor`; runtime repeats compilation and driver validation against the pinned graph before source code can run.

## Facade methods

```php
Nodeflow::nodes(): NodeRegistry;
Nodeflow::register(array $nodeClasses): void;
Nodeflow::triggerNodes(): TriggerNodeRegistry;
Nodeflow::triggerDrivers(): TriggerDriverRegistry;
Nodeflow::triggerSources(): TriggerSourceRegistry;
Nodeflow::registerTriggerNodes(array $classes): void;
Nodeflow::registerTriggerDrivers(array $classes): void;
Nodeflow::registerTriggerSources(array $classes): void;
Nodeflow::routes(): void;
Nodeflow::webhookRoutes(): void;
```

Drivers must be registered before nodes/sources that name them, and nodes before flows using their graph types are validated. Source registration invokes the resolved driver's `sourceRegistered()` hook.

## Start and authoring services

- `StartRun::forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run` performs a manual start and bypasses source matching.
- `PublishFlow::publish(Flow $flow, array $graph, ?string $publishedBy = null, ?int $expectedDraftRevision = null): PublishResult` creates a version and activation.
- `SaveDraft::save(Flow $flow, array $graph, ?int $lastSeenRevision): int` performs compare-and-swap draft persistence.
- `CreateRun::resume(int|string $runId): Run` retries durable dispatch from persisted run intent.

Use the higher-level trigger dispatcher/starter path for occurrence starts; constructing activation snapshots from untrusted values would bypass the repository's publication authority.

## React/TypeScript exports

The package root exports `FlowEditor`, `FlowRun`, `Canvas`, editor/controller types, renderer/control extension types, and server wire types including `Graph`, `GraphNode`, `GraphEdge`, `GraphComponentKind`, `TriggerNodeTypePayload`, `TriggerSourcePayload`, `TriggerSourcesPayload`, `WebhookMetadata`, `EditorUrls`, `RunSummary`, and overlay types. `TriggerPayload` remains a deprecated type alias for `TriggerNodeTypePayload`.

Only these wire types are public frontend contracts. PHP registry instances, activation descriptors, and trigger occurrences are server runtime objects and are not TypeScript exports.
