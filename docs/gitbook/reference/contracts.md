# PHP contracts reference

These are the public host-facing methods in the current contracts, nodes, triggers, schema builders, registries, contexts, and orchestration services. Constructors are omitted below when Nodeflow owns construction; do not instantiate runtime contexts, results, or orchestration services yourself unless a method provides a factory.

## Required host implementations

Bind these interfaces in a service provider. `TenantResolver` and `SubjectResolver` are required for a real host integration.

```php
interface TenantResolver
{
    public function currentTenantId(): ?string;
    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool;
}

interface SubjectResolver
{
    public function resolve(string $subjectType, array $subjectIds): array;
}
```

`ownsSubject()` must reject a subject outside the supplied tenant. `resolve()` returns a map keyed by subject ID; an absent map entry becomes `null` in a subject-node context.

`AudienceResolver` is public but the current runtime never resolves or calls it. Binding it has no runtime effect today.

```php
interface AudienceResolver
{
    public function subjectType(): string;
    public function subjectIds(string $tenantId, array $payload): iterable;
}
```

See [Required contracts](../integration/required-contracts.md) and [Tenancy](../integration/tenancy.md).

## Nodes

Extend `Node` and implement one or both cardinality interfaces. `NodeRegistry::register()` rejects a class that does not extend `Node` or implements neither interface.

```php
abstract class Node
{
    abstract public static function type(): string;
    abstract public function definition(): NodeDefinition;
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

`type()` is the stable graph identifier. `definition()` supplies editor metadata and publish-time rules. `defaultConfig()` returns `[]` unless overridden. A node implementing both interfaces must give equivalent outcomes for the same subjects.

## Schema builders and option sources

`Field` and definitions have private constructors; use their factories.

```php
class Field
{
    public static function text(string $key): self;
    public static function number(string $key): self;
    public static function boolean(string $key): self;
    public static function select(string $key): self;
    public static function multiselect(string $key): self;
    public static function duration(string $key): self;
    public static function custom(string $key, string $type, string $baseRule = 'string'): self;
    public function label(string $label): self;
    public function help(string $help): self;
    public function required(bool $required = true): self;
    public function default(mixed $default): self;
    public function options(array $options): self;
    public function optionsFrom(string $sourceClass): self;
    public function optionsSourceClass(): ?string;
    public function toArray(): array;
    public function toWireArray(): array;
    public function rules(): array;
}

class NodeDefinition
{
    public static function make(string $label): self;
    public function group(string $group): self;
    public function icon(string $icon): self;
    public function description(string $description): self;
    public function outputs(array $outputs): self;
    public function fields(array $fields): self;
    public function fieldObjects(): array;
    public function outputNames(): array;
    public function toArray(): array;
    public function rules(): array;
}

interface OptionSource
{
    public function options(): array;
}
```

Built-in `FieldType` cases are `Text`, `Number`, `Boolean`, `Select`, `Multiselect`, and `Duration`; its public helper is `public function baseRule(): string;`. `optionsFrom()` names an `OptionSource`; the editor receives that options are dynamic, not the backing class. `toWireArray()` is the HTTP shape.

```php
class SubjectAttribute
{
    public static function make(string $key, string $label, string $type, callable $resolver): self;
    public function value(mixed $subject): mixed;
}

class SubjectAttributeRegistry implements OptionSource
{
    public function register(SubjectAttribute ...$attributes): self;
    public function options(): array;
    public function has(string $key): bool;
    public function value(string $key, mixed $subject): mixed;
    public function get(string $key): ?SubjectAttribute;
}
```

Register attributes through the singleton registry. `value()` throws for an unknown key. See [Subject attributes](../building-automations/subject-attributes.md).

## Registries and facade

Use `Nodeflow::register()` from a host or package provider, or obtain the same singleton through `Nodeflow::nodes()`.

```php
class Nodeflow
{
    public static function nodes(): NodeRegistry;
    public static function register(array $nodeClasses): void;
    public static function routes(): void;
}

class NodeRegistry
{
    public function register(string ...$classes): self;
    public function alias(string $oldType, string $newType): self;
    public function has(string $type): bool;
    public function resolve(string $type): Node;
    public function all(): array;
    public function palette(): array;
}
```

An alias maps an old graph type to a registered new type. `resolve()` throws `UnknownNodeTypeException` for an unknown type. `routes()` registers routes only; it does not authorize callers.

## Triggers

Extend `Trigger`, register it through `TriggerRegistry`, and return a `TriggerMatch` for an event. Trigger construction is runtime-owned.

```php
abstract class Trigger
{
    abstract public static function type(): string;
    abstract public static function event(): string;
    abstract public function definition(): TriggerDefinition;
    abstract public function resolve(object $event): TriggerMatch;
    public function idempotencyKey(object $event): ?string;
    public function matchesConfig(object $event, array $config): bool;
}

class TriggerMatch
{
    public static function make(): self;
    public function forTenant(string $tenantId, string $subjectType, iterable $subjectIds): self;
    public function tenants(): array;
}

class TriggerDefinition
{
    public static function make(string $label): self;
    public function description(string $description): self;
    public function fields(array $fields): self;
    public function toArray(): array;
    public function rules(): array;
}

class TriggerRegistry
{
    public function register(string ...$classes): self;
    public function has(string $type): bool;
    public function resolve(string $type): Trigger;
    public function all(): array;
    public function forEvent(string $eventClass): array;
    public function palette(): array;
}
```

The base `idempotencyKey()` returns `null`; override it for a stable event identity. The base `matchesConfig()` returns `true`. First registration for an event class attaches its listener. See [Writing triggers](../building-automations/writing-triggers.md).

## Contexts and results

The runtime constructs contexts. Nodes should use their accessors and result helpers, rather than construct contexts or mutate run rows.

```php
class SubjectContext
{
    public function runId(): int;
    public function correlationId(): ?string;
    public function nodeId(): string;
    public function subject(): mixed;
    public function subjectId(): string;
    public function config(?string $key = null, mixed $default = null): mixed;
    public function isTest(): bool;
    public function continue(string $output = 'default'): NodeResult;
    public function fail(string $message): NodeResult;
}

class AudienceContext
{
    public function runId(): int;
    public function correlationId(): ?string;
    public function nodeId(): string;
    public function subjectIds(): array;
    public function subjectType(): string;
    public function subjects(): array;
    public function config(?string $key = null, mixed $default = null): mixed;
    public function isTest(): bool;
    public function partition(array $outputToSubjectIds): NodeResult;
    public function all(string $output = 'default'): NodeResult;
}

class NodeResult
{
    public static function forSubject(string $subjectId, string $output = 'default'): self;
    public static function partition(array $outputToSubjectIds): self;
    public static function failed(string $subjectId, string $message): self;
    public static function empty(): self;
    public static function merge(self ...$results): self;
    public function outputs(): array;
    public function failures(): array;
    public function subjectCount(): int;
}
```

`AudienceContext::subjects()` calls the bound `SubjectResolver`. `isTest()` means no external side effect. `NodeResult` has a private constructor; use its factories or a context helper.

## Workflow and direct services

These are public container services, but their constructors depend on runtime collaborators and are not host construction APIs.

```php
class StartRun
{
    public function forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run;
}

class PublishFlow
{
    public function publish(Flow $flow, array $graph, ?string $publishedBy = null): FlowVersion;
}

class GraphInvalidException extends RuntimeException
{
    public function errors(): array;
    public function nodeErrors(): array;
}

class SubjectExiter
{
    public function exit(Run $run, array $subjectIds): void;
}

interface WorkflowEngine
{
    public function start(string $workflowClass, array $args): string;
    public function signal(string $workflowId, string $method, array $args = []): void;
    public function cancel(string $workflowId): void;
    public function isRunning(string $workflowId): bool;
}
```

`StartRun::forFlow()` accepts optional `idempotency_key`, `correlation_id`, `strategy`, and `is_test` entries. It throws when a flow has no published version and returns the existing run for a matching non-null idempotency key and flow version. `PublishFlow::publish()` validates its graph and throws `GraphInvalidException` when invalid. `errors()` returns `string[]` general graph failures. `nodeErrors()` returns `array<int, array{node: ?string, field: ?string, message: string}>`, so an editor can attach a failure to a node or field when known. `SubjectExiter::exit()` removes active listed subjects and signals an emptied live audience where applicable.

These methods do not authorize a caller. Keep them behind application policies, gates, and tenant checks; a public direct action is not permission to expose it.

### Deliberate exclusions

The mechanical public-method scan also finds exception factories (`InvalidNodeException` and `UnknownOptionSourceException`), the `UnknownNodeTypeException` constructor, `ValidDuration::validate(string $attribute, mixed $value, Closure $fail): void`, `ValidDuration::seconds(string $value): ?int`, `EventTriggerListener::handle()`, and `SubFlowStarter::start()`. They are package error, validation parsing, listener, or core-node support APIs rather than host implementation surfaces: do not construct or call them as host integration APIs. `core.start_flow` is the supported graph-level entry to the sub-flow starter and is documented in [Core nodes](core-nodes.md).

## Next step

Follow [Writing nodes](../building-automations/writing-nodes.md), [Writing triggers](../building-automations/writing-triggers.md), and [Starting runs](../building-automations/starting-runs.md) for lifecycle and authorization context.
