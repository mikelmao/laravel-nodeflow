# First-Class Trigger Nodes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace mutable flow-level triggers with one extensible, versioned trigger node per graph and ship signed webhook, after-commit Eloquent model, and Laravel event drivers.

**Architecture:** Trigger nodes and executable nodes share a collision-safe graph type catalog but implement separate contracts. Publication compiles the graph trigger into one indexed activation; drivers resolve allowlisted occurrences into tenant audiences and pass them through a common exact-version run starter. The React editor authors the same trigger node stored in the immutable graph.

**Tech Stack:** PHP 8.3, Laravel 12/13 components, Eloquent, Pest 4, React 18/19, TypeScript 7, React Flow 12, Vitest 4.

---

## File and dependency map

The implementation is one sequential plan because every built-in driver and editor surface depends
on the same trigger contracts and activation projection. Each task ends with a passing focused suite
and a reviewable commit.

New trigger foundation files:

- `src/Contracts/TriggerNode.php` — non-executable graph-entry contract.
- `src/Contracts/TriggerDriver.php` — activation-driver lifecycle contract.
- `src/Contracts/TriggerSource.php` — allowlisted occurrence-to-match contract.
- `src/Graph/GraphTypeCatalog.php` — collision-safe ownership of executable and trigger types.
- `src/Triggers/AbstractTriggerNode.php` — field validation/default-config convenience base.
- `src/Triggers/TriggerActivationDescriptor.php` — compiled JSON-safe routing value.
- `src/Triggers/TriggerActivationSnapshot.php` — immutable projection/version snapshot safe across
  concurrent publication.
- `src/Triggers/TriggerOccurrence.php` — immutable driver/source occurrence envelope.
- `src/Triggers/TriggerTenantMatch.php` — one tenant audience and safe data.
- `src/Triggers/TriggerMatch.php` — immutable collection of tenant matches.
- `src/Triggers/TriggerNodeRegistry.php`, `TriggerDriverRegistry.php`, and
  `TriggerSourceRegistry.php` — separate extension registries backed by the shared catalog.

New persistence/orchestration files:

- `src/Models/TriggerActivation.php` and `WebhookEndpoint.php` — derived activation and stable
  webhook identity.
- `src/Triggers/TriggerActivationRepository.php` — active, exact-version lookup boundary.
- `src/Triggers/TriggerOccurrenceDispatcher.php` — source resolution and per-activation isolation.
- `src/Triggers/TriggerRunStarter.php` — exact-version triggered-start boundary.
- `src/Execution/CreateRun.php` — shared transactional run/materialization/engine implementation.

Built-in driver files live under `src/Triggers/Webhook`, `src/Triggers/ModelObserver`, and
`src/Triggers/LaravelEvent`. Their source interfaces extend the common `TriggerSource` contract and
add only the typed class/payload declaration their driver needs.

Editor changes stay inside existing `resources/js/graph`, `canvas`, and `editor` boundaries. Trigger
definitions are adapted into the canvas definition map, while `kind: 'trigger' | 'executable'`
controls handles and authoring rules without built-in type-name checks.

### Task 1: Public trigger contracts and collision-safe registries

**Files:**
- Create: `src/Contracts/TriggerNode.php`
- Create: `src/Contracts/TriggerDriver.php`
- Create: `src/Contracts/TriggerSource.php`
- Create: `src/Graph/GraphTypeCatalog.php`
- Create: `src/Triggers/AbstractTriggerNode.php`
- Create: `src/Triggers/TriggerActivationDescriptor.php`
- Create: `src/Triggers/TriggerActivationSnapshot.php`
- Create: `src/Triggers/TriggerOccurrence.php`
- Create: `src/Triggers/TriggerTenantMatch.php`
- Rewrite: `src/Triggers/TriggerMatch.php`
- Create: `src/Triggers/TriggerNodeRegistry.php`
- Create: `src/Triggers/TriggerDriverRegistry.php`
- Create: `src/Triggers/TriggerSourceRegistry.php`
- Modify: `src/Nodes/NodeRegistry.php`
- Modify: `src/Schema/TriggerDefinition.php`
- Modify: `src/Nodeflow.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Unit/TriggerRegistriesTest.php`
- Test support: `tests/Support/FakeTriggerDriver.php`
- Test support: `tests/Support/FakeTriggerNode.php`
- Test support: `tests/Support/FakeTriggerSource.php`

- [ ] **Step 1: Write failing registry and DTO tests**

```php
it('registers a custom driver node and source without class-name persistence', function () {
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([FakeTriggerNode::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect(app(TriggerDriverRegistry::class)->resolve('test.fake'))
        ->toBeInstanceOf(FakeTriggerDriver::class)
        ->and(app(TriggerNodeRegistry::class)->resolve('test.fake_trigger'))
        ->toBeInstanceOf(FakeTriggerNode::class)
        ->and(app(TriggerSourceRegistry::class)->resolve('test.fake', 'test.orders'))
        ->toBeInstanceOf(FakeTriggerSource::class);
});

it('rejects one stable graph type claimed by both node families', function () {
    Nodeflow::registerTriggerNodes([FakeTriggerNode::class]);

    expect(fn () => Nodeflow::register([FakeExecutableUsingTriggerType::class]))
        ->toThrow(InvalidGraphTypeRegistration::class);
});
```

- [ ] **Step 2: Run the focused tests and confirm the contracts are absent**

Run: `vendor/bin/pest tests/Unit/TriggerRegistriesTest.php --compact`

Expected: FAIL because the trigger contracts and registries do not exist.

- [ ] **Step 3: Implement immutable values and public contracts**

Use these signatures consistently in every later task:

```php
interface TriggerNode
{
    public static function type(): string;
    public function definition(): TriggerDefinition;
    public function driver(): string;
    public function defaultConfig(): array;
    public function validate(array $config, TriggerSourceRegistry $sources): array;
    public function compile(array $config): TriggerActivationDescriptor;
}

interface TriggerDriver
{
    public static function key(): string;
    public function sourceRegistered(TriggerSource $source): void;
    public function validate(TriggerActivationDescriptor $descriptor): array;
}

interface TriggerSource
{
    public static function key(): string;
    public static function driver(): string;
    public function definition(): TriggerDefinition;
    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch;
}
```

Make `TriggerActivationDescriptor` accept only `driver`, `source`, nullable `qualifier`, and array
`metadata`; give it `toArray()`. Make `TriggerMatch::forTenant()` return a new instance rather than
mutating the original, and normalize tenant, subject type, and subject IDs to strings. Use this
constructor for its per-tenant value throughout the implementation:

```php
final readonly class TriggerTenantMatch
{
    public function __construct(
        public string $tenantId,
        public string $subjectType,
        public array $subjectIds,
        public array $triggerData = [],
        public ?string $occurrenceId = null,
    ) {}
}
```

`TriggerActivationSnapshot` contains activation ID, flow ID, flow-version ID, tenant ID, driver,
source, qualifier, trigger node ID, and descriptor. `TriggerOccurrence` uses this constructor so a
driver may pin candidates at occurrence time:

```php
public function __construct(
    public readonly string $driver,
    public readonly string $source,
    public readonly mixed $payload,
    public readonly ?string $qualifier = null,
    /** @var TriggerActivationSnapshot[]|null */
    public readonly ?array $activations = null,
) {}
```

`FakeTriggerNode::compile()` uses driver `test.fake`, the configured `source`, a null qualifier, and
all remaining config as descriptor metadata. `FakeTriggerSource::resolve()` reads the array payload
keys `tenant_id`, `subject_id`, and `occurrence_id` used by Task 7 and returns trigger data
`['occurrence' => $occurrenceId]`.

- [ ] **Step 4: Implement registries and shared graph type ownership**

```php
final class GraphTypeCatalog
{
    private array $claims = [];

    public function claim(string $type, string $family, string $class): void
    {
        if (isset($this->claims[$type]) && $this->claims[$type] !== [$family, $class]) {
            throw InvalidGraphTypeRegistration::collision($type, $this->claims[$type], [$family, $class]);
        }

        $this->claims[$type] = [$family, $class];
    }

    public function family(string $type): ?string
    {
        return $this->claims[$type][0] ?? null;
    }
}
```

`TriggerDriverRegistry::register()` must register drivers before sources. `TriggerSourceRegistry`
must key entries by `driver."\0".source`, reject an unknown driver, and call
`$driver->sourceRegistered($source)` exactly once. Extend `TriggerDefinition` with `icon()`,
`fieldObjects()`, and wire output matching `NodeDefinition` where applicable.

- [ ] **Step 5: Bind registries and facade methods, then rerun tests**

Run: `vendor/bin/pest tests/Unit/TriggerRegistriesTest.php tests/Unit/ArchitectureTest.php --compact`

Expected: PASS with the fake custom extension registered and existing engine-boundary tests green.

- [ ] **Step 6: Commit the contract slice**

```bash
git add src/Contracts src/Graph/GraphTypeCatalog.php src/Triggers src/Nodes/NodeRegistry.php src/Schema/TriggerDefinition.php src/Nodeflow.php src/NodeflowServiceProvider.php tests/Unit/TriggerRegistriesTest.php tests/Support/FakeTriggerDriver.php tests/Support/FakeTriggerNode.php tests/Support/FakeTriggerSource.php
git commit -m "feat: add extensible trigger contracts"
```

### Task 2: Built-in trigger node definitions

**Files:**
- Create: `src/Triggers/Webhook/WebhookTriggerNode.php`
- Create: `src/Triggers/Webhook/WebhookTriggerDriver.php`
- Create: `src/Triggers/Webhook/WebhookTriggerSource.php`
- Create: `src/Triggers/ModelObserver/ModelObserverTriggerNode.php`
- Create: `src/Triggers/ModelObserver/ModelObserverTriggerDriver.php`
- Create: `src/Triggers/ModelObserver/ModelObserverTriggerSource.php`
- Create: `src/Triggers/LaravelEvent/LaravelEventTriggerNode.php`
- Create: `src/Triggers/LaravelEvent/LaravelEventTriggerDriver.php`
- Create: `src/Triggers/LaravelEvent/LaravelEventTriggerSource.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Unit/BuiltInTriggerDefinitionsTest.php`

- [ ] **Step 1: Write failing definition and registration tests**

```php
it('registers the three built-in trigger nodes and drivers', function () {
    expect(app(TriggerNodeRegistry::class)->all())->toHaveKeys([
        'core.trigger.webhook',
        'core.trigger.model_observer',
        'core.trigger.laravel_event',
    ])->and(app(TriggerDriverRegistry::class)->all())->toHaveKeys([
        'webhook', 'model', 'event',
    ]);
});

it('compiles a model trigger into indexed routing values', function () {
    $descriptor = app(ModelObserverTriggerNode::class)->compile([
        'source' => 'orders',
        'event' => 'updated',
        'changed_fields' => ['status'],
    ]);

    expect($descriptor->driver)->toBe('model')
        ->and($descriptor->source)->toBe('orders')
        ->and($descriptor->qualifier)->toBe('updated');
});
```

- [ ] **Step 2: Run and confirm missing built-ins**

Run: `vendor/bin/pest tests/Unit/BuiltInTriggerDefinitionsTest.php --compact`

Expected: FAIL because the three built-in classes do not exist.

- [ ] **Step 3: Implement the built-in authoring definitions**

Use required `source` for all three. Model additionally defines required single-select `event` with
`created`, `updated`, `deleted`, and `restored`, plus optional `changed_fields`. Every definition has
only `started` output at the trigger-contract level; the trigger palette wire shape declares
`kind => trigger` and `outputs => ['started']`.

```php
public function compile(array $config): TriggerActivationDescriptor
{
    return new TriggerActivationDescriptor(
        driver: 'model',
        source: (string) $config['source'],
        qualifier: (string) $config['event'],
        metadata: ['changed_fields' => array_values($config['changed_fields'] ?? [])],
    );
}
```

Driver methods may remain listener-free in this task, but must validate their descriptor driver and
the corresponding typed source interface.

- [ ] **Step 4: Register built-ins unconditionally and rerun tests**

Run: `vendor/bin/pest tests/Unit/BuiltInTriggerDefinitionsTest.php tests/Unit/TriggerRegistriesTest.php --compact`

Expected: PASS.

- [ ] **Step 5: Commit built-in definitions**

```bash
git add src/Triggers/Webhook src/Triggers/ModelObserver src/Triggers/LaravelEvent src/NodeflowServiceProvider.php tests/Unit/BuiltInTriggerDefinitionsTest.php
git commit -m "feat: define built-in trigger nodes"
```

### Task 3: Trigger-aware graph validation and test fixtures

**Files:**
- Modify: `src/Graph/Graph.php`
- Modify: `src/Graph/GraphValidator.php`
- Create: `tests/Support/TriggeredGraph.php`
- Create: `tests/Feature/TriggerGraphValidationTest.php`
- Modify mechanically: every PHP test returned by
  `rg -l "'start'\s*=>" tests/Feature tests/Unit`

- [ ] **Step 1: Add failing semantic graph tests**

```php
it('accepts exactly one trigger leading to an executable node', function () {
    $result = app(GraphValidator::class)->validate(Graph::fromArray(triggeredGraph([
        'start' => 'send',
        'nodes' => [['id' => 'send', 'type' => 'test.send', 'config' => []]],
        'edges' => [],
    ])));

    expect($result->passes())->toBeTrue();
});

it('rejects a graph whose start is executable or whose trigger has an incoming edge', function () {
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => 'send',
        'nodes' => [
            ['id' => 'trigger', 'type' => 'test.fake_trigger', 'config' => ['source' => 'test.orders']],
            ['id' => 'send', 'type' => 'test.send', 'config' => []],
        ],
        'edges' => [
            ['from' => 'send', 'output' => 'sent', 'to' => 'trigger'],
            ['from' => 'trigger', 'output' => 'started', 'to' => 'send'],
        ],
    ]));

    expect($result->errors())
        ->toContain('The graph start must be its trigger node.')
        ->toContain('Trigger node [trigger] cannot have incoming edges.');
});
```

- [ ] **Step 2: Run the new tests and see current validator failures**

Run: `vendor/bin/pest tests/Feature/TriggerGraphValidationTest.php --compact`

Expected: FAIL because trigger types are treated as unknown executable nodes.

- [ ] **Step 3: Add graph entry helpers and trigger validation**

Add `triggerNodeIds(GraphTypeCatalog $types)`, `incomingEdges(string $id)`, and
`entryNodeId(GraphTypeCatalog $types)` to `Graph`. `entryNodeId()` must require one `started` target
and return that executable ID. Refactor `GraphValidator` to resolve trigger nodes through
`TriggerNodeRegistry`, merge node/source rules, and keep existing cycle/output validation for both
families.

- [ ] **Step 4: Introduce one test graph wrapper and migrate fixtures**

```php
function triggeredGraph(
    array $executable,
    string $source = 'test.orders',
    array $triggerConfig = [],
): array
{
    $oldStart = (string) ($executable['start'] ?? '');

    return [
        ...$executable,
        'start' => 'trigger',
        'nodes' => [
            ['id' => 'trigger', 'type' => 'test.fake_trigger', 'config' => [
                'source' => $source,
                ...$triggerConfig,
            ]],
            ...($executable['nodes'] ?? []),
        ],
        'edges' => [
            ['from' => 'trigger', 'output' => 'started', 'to' => $oldStart],
            ...($executable['edges'] ?? []),
        ],
    ];
}

function triggeredExitGraph(
    string $source = 'test.orders',
    array $triggerConfig = [],
): array
{
    return triggeredGraph([
        'start' => 'first-action',
        'nodes' => [['id' => 'first-action', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ], $source, $triggerConfig);
}
```

Register fake driver/node/source in `tests/TestCase.php`. Wrap every existing publishable fixture;
leave intentionally malformed raw graphs unwrapped and update their expected errors to the new entry
rules.

- [ ] **Step 5: Run graph and publication suites**

Run: `vendor/bin/pest tests/Feature/TriggerGraphValidationTest.php tests/Feature/PublishFlowTest.php tests/Feature/StructuredPublishErrorsTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit graph semantics**

```bash
git add src/Graph tests/TestCase.php tests/Support/TriggeredGraph.php tests/Feature tests/Unit
git commit -m "feat: require a trigger graph entry"
```

### Task 4: Activation, webhook endpoint, and run schema

**Files:**
- Modify: `composer.json`
- Modify: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Modify: `src/Models/Flow.php`
- Modify: `src/Models/FlowVersion.php`
- Modify: `src/Models/Run.php`
- Create: `src/Models/TriggerActivation.php`
- Create: `src/Models/WebhookEndpoint.php`
- Create: `tests/Feature/TriggerSchemaTest.php`
- Modify mechanically: PHP test fixtures returned by `rg -l "'trigger_type'\s*=>" tests`
- Modify run fixtures: `tests/Feature/AudienceMaterialiserTest.php`
- Modify run fixtures: `tests/Feature/FlowVersionReferenceGuardTest.php`
- Modify run fixtures: `tests/Feature/NodeContextSurfaceTest.php`
- Modify run fixtures: `tests/Feature/NodeRunnerTest.php`
- Modify run fixtures: `tests/Feature/NodeTypeResolutionTest.php`
- Modify run fixtures: `tests/Feature/PolicyTest.php`
- Modify run fixtures: `tests/Feature/PruneCommandTest.php`
- Modify run fixtures: `tests/Feature/PublishFlowTest.php`
- Modify run fixtures: `tests/Feature/RunNodeActivityTest.php`
- Modify run fixtures: `tests/Feature/RunOverlayTest.php`
- Modify run fixtures: `tests/Feature/RunSubjectsTest.php`
- Modify run fixtures: `tests/Feature/RunViewTest.php`
- Modify run fixtures: `tests/Feature/SchemaTest.php`
- Modify run fixtures: `tests/Feature/SubFlowStarterTest.php`
- Modify run fixtures: `tests/Feature/SubjectExiterTest.php`

- [ ] **Step 1: Write failing schema and relation tests**

```php
it('stores one current activation and one stable webhook endpoint per flow', function () {
    expect(Schema::hasColumns('nodeflow_trigger_activations', [
        'flow_id', 'flow_version_id', 'tenant_id', 'driver', 'source',
        'qualifier', 'trigger_node_id', 'descriptor',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('nodeflow_webhook_endpoints', [
            'flow_id', 'token', 'signing_secret', 'secret_rotated_at',
        ]))->toBeTrue();
});

it('casts trigger metadata on models', function () {
    expect((new TriggerActivation)->getCasts()['descriptor'])->toBe('array')
        ->and((new Run)->getCasts()['trigger_data'])->toBe('array');
});
```

- [ ] **Step 2: Run schema tests and confirm missing tables**

Run: `vendor/bin/pest tests/Feature/TriggerSchemaTest.php --compact`

Expected: FAIL because the projection tables and run columns do not exist.

- [ ] **Step 3: Rewrite the unused base migration and models**

Remove flow `trigger_type`, `trigger_config`, and their index. Add the activation and endpoint tables
with the constraints from the design. Add non-null run `started_via`, non-null `trigger_node_id`, and
nullable JSON `trigger_data`; retain `(flow_version_id, idempotency_key)` uniqueness. Use an
`encrypted` cast for `WebhookEndpoint::signing_secret`, add `illuminate/encryption` matching the
package's Laravel 12/13 constraint to `composer.json`, and freeze activation tenant/version/flow
references after creation.

Run: `composer update illuminate/encryption --with-all-dependencies`

Expected: dependency resolution succeeds for the currently locked Laravel major and updates
`composer.lock` without removing the durable workflow dependency.

- [ ] **Step 4: Remove obsolete flow fixture fields and old model casts**

Run `rg -l "'trigger_type'\s*=>" tests` to obtain the exact list, remove only those key/value entries,
and remove assertions for the old editor flow props. Do not change graph trigger config added in Task
3. Add `started_via => manual`, `trigger_node_id => trigger`, and `trigger_data => null` to every
direct run fixture in the files listed above; triggered/event fixtures created through orchestration
must assert the runtime-authored values instead of supplying them.

- [ ] **Step 5: Run schema, tenancy, and version-reference tests**

Run: `vendor/bin/pest tests/Feature/TriggerSchemaTest.php tests/Feature/SchemaTest.php tests/Feature/TenancyTest.php tests/Feature/FlowVersionReferenceGuardTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit schema changes**

```bash
git add composer.json composer.lock database/migrations src/Models tests/Feature tests/Unit
git commit -m "feat: persist trigger activations and run origins"
```

### Task 5: Atomic publication and activation compilation

**Files:**
- Create: `src/Triggers/TriggerActivationRepository.php`
- Create: `src/Publishing/CompileTriggerActivation.php`
- Create: `src/Publishing/PublishResult.php`
- Modify: `src/Publishing/PublishFlow.php`
- Modify: `src/Models/Flow.php`
- Create: `tests/Feature/PublishTriggerActivationTest.php`

- [ ] **Step 1: Write failing publication projection tests**

```php
it('publishes the trigger graph and replaces its activation atomically', function () {
    $first = app(PublishFlow::class)->publish($flow, triggeredExitGraph(triggerConfig: ['channel' => 'alpha']));
    $second = app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph(triggerConfig: ['channel' => 'beta']));

    $activation = TriggerActivation::withoutTenancy()->sole();

    expect($activation->flow_version_id)->toBe($second->version->id)
        ->and($activation->source)->toBe('test.orders')
        ->and($activation->descriptor['channel'])->toBe('beta')
        ->and(TriggerActivation::withoutTenancy()->count())->toBe(1);
});

it('keeps the old version active when activation compilation fails', function () {
    $old = app(PublishFlow::class)->publish($flow, triggeredExitGraph());

    expect(fn () => app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph('missing.source')))
        ->toThrow(GraphInvalidException::class)
        ->and($flow->fresh()->current_version_id)->toBe($old->version->id)
        ->and(TriggerActivation::withoutTenancy()->sole()->flow_version_id)->toBe($old->version->id);
});
```

- [ ] **Step 2: Run and confirm no activation is written**

Run: `vendor/bin/pest tests/Feature/PublishTriggerActivationTest.php --compact`

Expected: FAIL because publication does not compile or persist activations.

- [ ] **Step 3: Implement compilation and transactional replacement**

`CompileTriggerActivation` must resolve `graph.start` through `TriggerNodeRegistry`, validate the
selected source and driver, call `compile()`, and return attributes derived from the trusted flow and
new version. Inside the existing publish transaction, delete the old flow activation and create the
new one before updating `current_version_id`.

```php
$attributes = $compiler->for($flow, $version, Graph::fromArray($graph));
$flow->triggerActivation()->delete();
TriggerActivation::create($attributes);
$flow->update(['current_version_id' => $version->id, 'status' => 'active', /* draft cleanup */]);
```

Return a `PublishResult` value with public readonly `FlowVersion $version` and nullable one-time
webhook credentials. The credentials remain null until Task 8; introducing the result now keeps the
public return type stable when webhook provisioning is added. Update existing publisher callers and
tests to read `$result->version`.

- [ ] **Step 4: Add active repository lookups and race assertions**

Implement `forDriverSource(string $driver, string $source, ?string $qualifier = null)` and
`forWebhookToken(string $token)`. Both must join or constrain the parent flow to `status = active`
and return `TriggerActivationSnapshot` values, not re-read `Flow::current_version_id` later.

- [ ] **Step 5: Run focused and existing publish tests**

Run: `vendor/bin/pest tests/Feature/PublishTriggerActivationTest.php tests/Feature/PublishFlowTest.php tests/Feature/FlowVersionReferenceGuardTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit atomic publication**

```bash
git add src/Publishing src/Triggers/TriggerActivationRepository.php src/Models/Flow.php tests/Feature/PublishTriggerActivationTest.php tests/Feature/PublishFlowTest.php
git commit -m "feat: compile trigger activations on publish"
```

### Task 6: Shared run creation, exact-version starts, and trigger data

**Files:**
- Create: `src/Execution/CreateRun.php`
- Modify: `src/Execution/StartRun.php`
- Create: `src/Triggers/TriggerRunStarter.php`
- Modify: `src/Triggers/SubFlowStarter.php`
- Modify: `src/Execution/SubjectContext.php`
- Modify: `src/Execution/AudienceContext.php`
- Modify: `src/Runs/RunOverlay.php`
- Modify: `src/Http/Controllers/RunViewController.php`
- Modify: `config/nodeflow.php`
- Test: `tests/Feature/TriggerRunStarterTest.php`
- Modify test: `tests/Feature/StartRunTest.php`
- Modify test: `tests/Feature/SubFlowStarterTest.php`
- Modify test: `tests/Feature/NodeContextSurfaceTest.php`
- Modify test: `tests/Feature/RunOverlayTest.php`

- [ ] **Step 1: Write failing exact-version and context tests**

```php
it('starts the activation version even after the flow publishes a newer version', function () {
    app(PublishFlow::class)->publish($flow, triggeredExitGraph(triggerConfig: ['channel' => 'alpha']));
    $activation = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph(triggerConfig: ['channel' => 'beta']));

    $run = app(TriggerRunStarter::class)->start($activation, new TriggerTenantMatch(
        tenantId: 'org-1',
        subjectType: 'user',
        subjectIds: ['7'],
        triggerData: ['order_id' => 91],
        occurrenceId: 'order-91',
    ));

    expect($run->flow_version_id)->toBe($activation->flow_version_id)
        ->and($run->trigger_data)->toBe(['order_id' => 91])
        ->and($run->subjects()->sole()->current_node_id)->toBe('first-action');
});
```

Add context assertions for `triggerData()`, manual `started_via`, sub-flow inheritance, and overlay
badges without a `NodeExecution` for the trigger.

- [ ] **Step 2: Run focused tests and confirm StartRun enters at the trigger**

Run: `vendor/bin/pest tests/Feature/TriggerRunStarterTest.php tests/Feature/StartRunTest.php tests/Feature/NodeContextSurfaceTest.php --compact`

Expected: FAIL because current `StartRun` materializes at `graph.start` and run contexts expose no
trigger data.

- [ ] **Step 3: Extract version-specific CreateRun**

Move the existing transaction, uniqueness recovery, audience materialization and engine start into:

```php
public function forVersion(
    FlowVersion $version,
    string $subjectType,
    iterable $subjectIds,
    string $entryNodeId,
    array $options,
): Run
```

Persist `started_via`, `trigger_node_id`, and validated JSON-safe `trigger_data`. Enforce
`nodeflow.limits.trigger_data_bytes` before opening the transaction. Keep current idempotency-race
recovery and post-commit engine-start ordering unchanged. Add the limit to `config/nodeflow.php` with
a 65,536-byte default and test the byte count against the JSON-encoded snapshot.

- [ ] **Step 4: Make manual, sub-flow, and triggered entry explicit**

`StartRun::forFlow()` loads the current version, resolves the trigger and entry edge, and calls
`CreateRun` with `started_via => manual`. `TriggerRunStarter` accepts the activation snapshot and uses
its version directly. `SubFlowStarter` passes `started_via => subflow` and the parent trigger data.

Add this identical API to both contexts:

```php
public function triggerData(?string $key = null, mixed $default = null): mixed
{
    $data = $this->run->trigger_data ?? [];

    return $key === null ? $data : ($data[$key] ?? $default);
}
```

- [ ] **Step 5: Decorate trigger entry without executions and rerun suites**

Run: `vendor/bin/pest tests/Feature/TriggerRunStarterTest.php tests/Feature/StartRunTest.php tests/Feature/SubFlowStarterTest.php tests/Feature/NodeContextSurfaceTest.php tests/Feature/RunOverlayTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit run orchestration**

```bash
git add src/Execution src/Triggers/TriggerRunStarter.php src/Triggers/SubFlowStarter.php src/Runs src/Http/Controllers/RunViewController.php config/nodeflow.php tests/Feature/TriggerRunStarterTest.php tests/Feature/StartRunTest.php tests/Feature/SubFlowStarterTest.php tests/Feature/NodeContextSurfaceTest.php tests/Feature/RunOverlayTest.php
git commit -m "feat: start runs through trigger entries"
```

### Task 7: Generic occurrence dispatcher and custom-driver proof

**Files:**
- Create: `src/Triggers/TriggerOccurrenceDispatcher.php`
- Modify: `tests/Support/FakeTriggerDriver.php`
- Modify: `tests/Support/FakeTriggerSource.php`
- Create: `tests/Feature/CustomTriggerDriverTest.php`

- [ ] **Step 1: Write a failing end-to-end custom-driver test**

```php
it('starts a custom-driver flow without package type switches', function () {
    $flow = Flow::create(['name' => 'Custom trigger']);
    app(PublishFlow::class)->publish($flow, triggeredExitGraph());
    $activation = TriggerActivation::withoutTenancy()->sole();

    app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        driver: 'test.fake',
        source: 'test.orders',
        payload: ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'occ-9'],
    ));

    $run = Run::withoutTenancy()->sole();
    expect($run->flow_version_id)->toBe($activation->flow_version_id)
        ->and($run->started_via)->toBe('test.fake')
        ->and($run->trigger_data)->toBe(['occurrence' => 'occ-9']);
});
```

- [ ] **Step 2: Run and confirm there is no common dispatcher**

Run: `vendor/bin/pest tests/Feature/CustomTriggerDriverTest.php --compact`

Expected: FAIL because `TriggerOccurrenceDispatcher` does not exist.

- [ ] **Step 3: Implement candidate lookup, source resolution, and isolation**

Use `$occurrence->activations` when supplied; otherwise query the repository by
driver/source/qualifier. For each candidate activation, resolve the source, call
`resolve($occurrence, $triggerConfig)`, select only the activation tenant, and invoke
`TriggerRunStarter`. Catch/report each activation failure and continue. Reject a returned tenant that
does not equal the activation tenant before calling the audience materializer.

- [ ] **Step 4: Prove idempotency namespacing and isolated failures**

Add two activations and make one fake source audience fail tenancy. Assert the other starts and one
exception is reported. Dispatch two source identities with the same raw occurrence ID and assert
their normalized run keys differ.

- [ ] **Step 5: Run custom extension and tenancy tests**

Run: `vendor/bin/pest tests/Feature/CustomTriggerDriverTest.php tests/Feature/StartRunTest.php tests/Feature/TenancyTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit the extension proof**

```bash
git add src/Triggers/TriggerOccurrenceDispatcher.php tests/Support/FakeTriggerDriver.php tests/Support/FakeTriggerSource.php tests/Feature/CustomTriggerDriverTest.php
git commit -m "feat: dispatch custom trigger occurrences"
```

### Task 8: Signed webhook runtime and secret management

**Files:**
- Create: `src/Triggers/Webhook/WebhookOccurrence.php`
- Create: `src/Triggers/Webhook/WebhookSignature.php`
- Create: `src/Triggers/Webhook/WebhookCredentials.php`
- Implement: `src/Triggers/Webhook/WebhookTriggerDriver.php`
- Create: `src/Http/Controllers/WebhookTriggerController.php`
- Create: `src/Http/Controllers/WebhookSecretController.php`
- Create: `src/Http/webhook-routes.php`
- Modify: `src/Nodeflow.php`
- Modify: `src/Http/routes.php`
- Modify: `src/Publishing/PublishFlow.php`
- Modify: `config/nodeflow.php`
- Test: `tests/Feature/WebhookTriggerTest.php`
- Test: `tests/Feature/WebhookSecretTest.php`

- [ ] **Step 1: Write failing HTTP contract tests**

```php
it('accepts a signed idempotent webhook and returns the same run on retry', function () {
    [$url, $secret] = publishedWebhookEndpoint();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $first = $this->call('POST', $url, server: signedHeaders($timestamp, $signature, 'delivery-1'), content: $body);
    $second = $this->call('POST', $url, server: signedHeaders($timestamp, $signature, 'delivery-1'), content: $body);

    $first->assertAccepted()->assertJsonPath('duplicate', false);
    $second->assertAccepted()->assertJsonPath('duplicate', true)
        ->assertJsonPath('run_id', $first->json('run_id'));
});
```

Add explicit tests for 404 inactive/unknown tokens, 401 bad/missing/expired signatures, 422 missing
idempotency key/malformed JSON/zero or cross-tenant matches, non-success start failures, and body
size rejection.

- [ ] **Step 2: Run and confirm webhook routes are absent**

Run: `vendor/bin/pest tests/Feature/WebhookTriggerTest.php tests/Feature/WebhookSecretTest.php --compact`

Expected: FAIL because webhook routes and endpoint creation do not exist.

- [ ] **Step 3: Implement stable endpoint creation and one-time secret responses**

On first webhook publication, generate token and secret with `bin2hex(random_bytes(32))`, store the
token and encrypted secret in the publication transaction, and return a transient publication result
containing plaintext only for the controller response. Reuse the endpoint on later publications.
Add `nodeflow.webhooks.replay_window_seconds = 300` and
`nodeflow.webhooks.max_body_bytes = 1048576` to `config/nodeflow.php`.

- [ ] **Step 4: Implement exact raw-body verification and webhook dispatch**

```php
$signed = $timestamp.'.'.$request->getContent();
$expected = hash_hmac('sha256', $signed, $endpoint->signing_secret);

abort_unless(hash_equals($expected, $providedDigest), 401);
```

Normalize the run idempotency identity from the required header. Require exactly one non-empty match
for the endpoint activation tenant, call `TriggerRunStarter`, and return `202` with `run_id` and
`duplicate`.

- [ ] **Step 5: Implement authenticated rotation and route opt-in**

`Nodeflow::webhookRoutes()` loads only the public POST route. The authenticated editor route file
gets `POST flows/{flow}/webhook-secret/rotate`, authorizes `update`, writes a new encrypted secret in
a transaction, and returns it once. A GET/editor payload exposes URL, active state, and rotation time
only.

- [ ] **Step 6: Run webhook and publication suites**

Run: `vendor/bin/pest tests/Feature/WebhookTriggerTest.php tests/Feature/WebhookSecretTest.php tests/Feature/PublishTriggerActivationTest.php --compact`

Expected: PASS.

- [ ] **Step 7: Commit webhook functionality**

```bash
git add src/Triggers/Webhook src/Http src/Publishing/PublishFlow.php src/Nodeflow.php config/nodeflow.php tests/Feature/WebhookTriggerTest.php tests/Feature/WebhookSecretTest.php
git commit -m "feat: add signed webhook triggers"
```

### Task 9: After-commit Eloquent model driver

**Files:**
- Create: `src/Triggers/ModelObserver/ModelOccurrence.php`
- Implement: `src/Triggers/ModelObserver/ModelObserverTriggerDriver.php`
- Test support: `tests/Support/Models/ObservedOrder.php`
- Test support: `tests/Support/OrderModelTriggerSource.php`
- Test: `tests/Feature/ModelObserverTriggerTest.php`

- [ ] **Step 1: Write lifecycle and transaction tests**

```php
it('starts only after the outer model transaction commits', function () {
    DB::transaction(function () {
        ObservedOrder::create(['tenant_id' => 'org-1', 'user_id' => '42', 'status' => 'new']);
        expect(Run::withoutTenancy()->count())->toBe(0);
    });

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('does not start for a rolled-back model change', function () {
    try {
        DB::transaction(function () {
            ObservedOrder::create(['tenant_id' => 'org-1', 'user_id' => '42', 'status' => 'new']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Run::withoutTenancy()->count())->toBe(0);
});
```

Add created, updated changed-field hit/miss, deleted, restored, nested transaction, no-transaction,
immutable snapshot, and query-builder bulk-update tests.

- [ ] **Step 2: Run and confirm no model listeners fire**

Run: `vendor/bin/pest tests/Feature/ModelObserverTriggerTest.php --compact`

Expected: FAIL with zero runs after model events.

- [ ] **Step 3: Implement typed source registration and listener deduplication**

Require `ModelObserverTriggerSource::modelClass(): string`. On source registration, attach exactly one
listener for each supported `eloquent.{event}: {modelClass}` pair. At emission, capture matching
activation snapshots and a value-only `ModelOccurrence` before registering the connection's
`afterCommit()` callback.

- [ ] **Step 4: Apply qualifier and changed-field filtering, then dispatch**

Skip activations whose qualifier differs. For `updated`, skip when configured non-empty
`changed_fields` has no intersection with `ModelOccurrence::changes`. Send the remaining immutable
occurrence through `TriggerOccurrenceDispatcher` after commit.

- [ ] **Step 5: Run model and custom-driver tests**

Run: `vendor/bin/pest tests/Feature/ModelObserverTriggerTest.php tests/Feature/CustomTriggerDriverTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit model triggers**

```bash
git add src/Triggers/ModelObserver tests/Support/Models/ObservedOrder.php tests/Support/OrderModelTriggerSource.php tests/Feature/ModelObserverTriggerTest.php
git commit -m "feat: add after-commit model triggers"
```

### Task 10: Laravel event driver and removal of the legacy trigger system

**Files:**
- Create: `src/Triggers/LaravelEvent/LaravelEventOccurrence.php`
- Implement: `src/Triggers/LaravelEvent/LaravelEventTriggerDriver.php`
- Delete: `src/Triggers/Trigger.php`
- Delete: `src/Triggers/TriggerRegistry.php`
- Delete: `src/Triggers/EventTriggerListener.php`
- Rewrite: `tests/Feature/EventTriggerTest.php`
- Modify: `tests/Feature/MakeTriggerCommandTest.php`
- Test support: `tests/Support/OrderPlacedEventSource.php`

- [ ] **Step 1: Rewrite event tests against an allowlisted source**

```php
it('starts every matching active tenant flow through the real dispatcher', function () {
    Nodeflow::registerTriggerSources([OrderPlacedEventSource::class]);
    publishEventFlow('org-1', minimumTotal: 50);
    publishEventFlow('org-2', minimumTotal: 100);

    Event::dispatch(new OrderPlacedAcrossTenants([
        'org-1' => ['users' => ['1'], 'total' => 75],
        'org-2' => ['users' => ['2'], 'total' => 75],
    ]));

    expect(Run::withoutTenancy()->pluck('tenant_id')->all())->toBe(['org-1']);
});
```

Retain real-dispatch, shared-event deduplication, multi-tenant fan-out, idempotency, and isolated
failure coverage from the old test under the new source contract.

- [ ] **Step 2: Run and confirm legacy listener shape fails**

Run: `vendor/bin/pest tests/Feature/EventTriggerTest.php --compact`

Expected: FAIL because the event source interface and driver listener are not implemented.

- [ ] **Step 3: Implement one listener per event class**

Require `LaravelEventTriggerSource::eventClass(): string`. `sourceRegistered()` records source keys by
event class and installs one `Event::listen()` callback per class. The callback creates a typed
occurrence and dispatches each compatible registered source once; activation/source config performs
per-flow filtering.

- [ ] **Step 4: Delete legacy runtime and update generator expectations**

Remove the old mutable-flow `Trigger`, registry, and listener. Update imports, architecture tests,
service-provider bindings, and make-trigger tests so no production reference to the old contract
remains:

Run: `rg -n "EventTriggerListener|Triggers\\\\TriggerRegistry|extends Trigger" src tests`

Expected: no matches outside an explicit generator migration assertion.

- [ ] **Step 5: Run event, registration, and architecture tests**

Run: `vendor/bin/pest tests/Feature/EventTriggerTest.php tests/Unit/TriggerRegistriesTest.php tests/Unit/ArchitectureTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit event driver cleanup**

```bash
git add -A src/Triggers src/NodeflowServiceProvider.php tests/Feature/EventTriggerTest.php tests/Feature/MakeTriggerCommandTest.php tests/Support/OrderPlacedEventSource.php tests/Unit/ArchitectureTest.php
git commit -m "feat: replace legacy event triggers"
```

### Task 11: Server-authored editor trigger payload and webhook management URLs

**Files:**
- Modify: `src/Http/Controllers/FlowEditorController.php`
- Modify: `src/Http/routes.php`
- Modify: `src/Http/Controllers/FieldOptionsController.php`
- Modify: `src/Schema/TriggerDefinition.php`
- Modify: `tests/Feature/EditorRoutesTest.php`
- Modify: `tests/Feature/FieldOptionsRouteTest.php`

- [ ] **Step 1: Write failing Inertia payload assertions**

```php
$response->assertJsonPath('props.trigger_nodes.0.kind', 'trigger')
    ->assertJsonPath('props.trigger_nodes.0.outputs.0', 'started')
    ->assertJsonPath('props.trigger_sources.webhook.0.key', 'test.webhook')
    ->assertJsonPath('props.webhook.endpoint_url', $expectedUrl)
    ->assertJsonMissingPath('props.webhook.signing_secret')
    ->assertJsonPath('props.urls.rotate_webhook_secret', $expectedRotateUrl);
```

Assert `props.flow` no longer contains `trigger_type`, and dynamic options remain server-authored and
tenant-scoped for both executable and trigger/source fields.

- [ ] **Step 2: Run and confirm the old payload shape**

Run: `vendor/bin/pest tests/Feature/EditorRoutesTest.php tests/Feature/FieldOptionsRouteTest.php --compact`

Expected: FAIL on missing trigger-node/source payloads and obsolete `trigger_type`.

- [ ] **Step 3: Build combined trigger palettes on the server**

Return `trigger_nodes`, sources grouped by driver, and webhook metadata. Combine selected source
fields with the node definition on validation/publish, reject reserved-field collisions with a
structured node error, and expose dynamic-options URL templates keyed only by stable type/source and
field keys.

- [ ] **Step 4: Add named secret-rotation route metadata**

Add the authenticated rotation route to `src/Http/routes.php`, use `ResolvesRouteNames`, and send its
resolved URL rather than hard-coding the package prefix.

- [ ] **Step 5: Run server editor tests**

Run: `vendor/bin/pest tests/Feature/EditorRoutesTest.php tests/Feature/FieldOptionsRouteTest.php tests/Feature/WebhookSecretTest.php --compact`

Expected: PASS.

- [ ] **Step 6: Commit server editor integration**

```bash
git add src/Http src/Schema/TriggerDefinition.php tests/Feature/EditorRoutesTest.php tests/Feature/FieldOptionsRouteTest.php tests/Feature/WebhookSecretTest.php
git commit -m "feat: expose trigger authoring metadata"
```

### Task 12: TypeScript graph model and trigger-safe controller mutations

**Files:**
- Modify: `resources/js/graph/types.ts`
- Modify: `resources/js/graph/toCanvas.ts`
- Modify: `resources/js/graph/toGraph.ts`
- Modify: `resources/js/editor/FlowEditor.tsx`
- Modify: `resources/js/editor/useEditorController.ts`
- Modify: `resources/js/editor/history.ts`
- Test: `resources/js/graph/toCanvas.test.ts`
- Test: `resources/js/graph/toGraph.test.ts`
- Test: `resources/js/editor/useEditorController.test.tsx`
- Modify: `resources/js/editor/history.test.ts`

- [ ] **Step 1: Add failing type and mutation tests**

```ts
it('adds one trigger as graph start and replaces it without losing its target', () => {
    const first = addTrigger(document, webhookDefinition)
    const replaced = replaceTrigger(first, eventDefinition)

    expect(replaced.startId).toBe(replaced.nodes[0]!.id)
    expect(replaced.nodes.filter((node) => node.kind === 'trigger')).toHaveLength(1)
    expect(replaced.edges.find((edge) => edge.source === replaced.startId)?.target).toBe('send')
})

it('refuses connections targeting a trigger', () => {
    expect(connect(document, { source: 'send', sourceHandle: 'sent', target: 'trigger' }))
        .toBe(document)
})
```

- [ ] **Step 2: Run focused Vitest files and confirm type/model failures**

Run: `npm test -- resources/js/graph/toCanvas.test.ts resources/js/graph/toGraph.test.ts resources/js/editor/useEditorController.test.tsx`

Expected: FAIL because payloads have no component kind and the controller permits ordinary starts.

- [ ] **Step 3: Define the new wire and canvas types**

```ts
export type GraphComponentKind = 'trigger' | 'executable'
export type TriggerNodeTypePayload = {
    kind: 'trigger'; type: string; driver: string; label: string; icon: string | null;
    description: string | null; outputs: ['started']; fields: FieldPayload[];
    default_config: GraphConfig
}
export type ExecutableNodeTypePayload = NodeTypePayload & { kind: 'executable' }
export type GraphComponentPayload = TriggerNodeTypePayload | ExecutableNodeTypePayload
```

Remove `trigger_type` from `FlowSummary`. Add typed source and webhook metadata props and resolved
rotation URL.

- [ ] **Step 4: Implement add/replace/delete/connect invariants**

The controller automatically sets start on trigger add, offers a separate replace mutation when a
trigger exists, clears start on delete, removes ordinary Make start, preserves the old outgoing
target during replacement, and refuses target-trigger or trigger-trigger connections. Ensure every
mutation participates in existing undo/autosave history.

- [ ] **Step 5: Run graph, controller, and type checks**

Run: `npm test -- resources/js/graph resources/js/editor/useEditorController.test.tsx resources/js/editor/history.test.ts`

Run: `npm run types:check`

Expected: both commands PASS.

- [ ] **Step 6: Commit client graph semantics**

```bash
git add resources/js/graph resources/js/editor/FlowEditor.tsx resources/js/editor/useEditorController.ts resources/js/editor/useEditorController.test.tsx resources/js/editor/history.ts resources/js/editor/history.test.ts
git commit -m "feat: model trigger nodes in the editor"
```

### Task 13: Trigger library, cards, inspector, and webhook UI

**Files:**
- Modify: `resources/js/editor/NodeLibrary.tsx`
- Modify: `resources/js/canvas/NodeCard.tsx`
- Modify: `resources/js/editor/NodeInspector.tsx`
- Modify: `resources/js/editor/ConfigPanel.tsx`
- Modify: `resources/js/editor/FlowOverview.tsx`
- Create: `resources/js/editor/WebhookDetails.tsx`
- Modify: `resources/js/editor/useEditorController.ts`
- Modify: `resources/js/http.ts`
- Modify: `resources/js/presentation/node.ts`
- Test: `resources/js/editor/NodeLibrary.test.tsx`
- Test: `resources/js/canvas/canvas.test.tsx`
- Test: `resources/js/editor/Inspector.test.tsx`
- Test: `resources/js/editor/FlowEditor.test.tsx`

- [ ] **Step 1: Write failing interaction and accessibility tests**

```tsx
it('offers replacement instead of adding a second trigger', async () => {
    renderEditor({ graph: webhookGraph(), triggerNodes })
    await user.click(screen.getByRole('button', { name: 'Add Laravel event' }))

    expect(screen.getByRole('dialog', { name: 'Replace trigger' })).toBeVisible()
    expect(currentGraph().nodes.filter((node) => node.type.startsWith('core.trigger.'))).toHaveLength(1)
})

it('renders a trigger with no target handle and one started handle', () => {
    renderTriggerCard()
    expect(screen.queryByTestId('target-handle')).not.toBeInTheDocument()
    expect(screen.getByLabelText('Output started')).toBeVisible()
});
```

Add tests for no-source empty states, source-contributed fields, trigger deletion, structured field
errors, webhook URL display, one-time publish secret, rotation confirmation, and secret removal after
the response is acknowledged.

- [ ] **Step 2: Run UI tests and confirm current generic node behavior fails**

Run: `npm test -- resources/js/editor/NodeLibrary.test.tsx resources/js/canvas/canvas.test.tsx resources/js/editor/Inspector.test.tsx resources/js/editor/FlowEditor.test.tsx`

Expected: FAIL because trigger presentation and webhook management do not exist.

- [ ] **Step 3: Render triggers through kind-based component behavior**

Group trigger definitions before executable groups. In `NodeCard`, branch only on `def.kind` to omit
the target handle and add TRIGGER/START badges; custom trigger types receive identical treatment.
Keep mandatory errors, host body renderers, run decorations, keyboard focus, and read-only behavior.

- [ ] **Step 4: Implement source-aware inspector and replacement UI**

Combine base and selected-source fields before passing them to `ConfigPanel`. Disable publication and
show a direct registration explanation when no compatible source exists. Use an accessible confirm
dialog for replacement and preserve the existing connected target only after confirmation.

- [ ] **Step 5: Implement safe webhook secret presentation and rotation**

Show endpoint URL, active state, and rotated timestamp persistently. Hold a newly returned plaintext
secret only in component state, label it as one-time, provide Copy and Acknowledge actions, and clear
it on acknowledgement, editor unmount, flow identity change, and successful subsequent rotation.

- [ ] **Step 6: Run all editor tests and type checks**

Run: `npm test -- resources/js/editor resources/js/canvas resources/js/graph`

Run: `npm run types:check`

Expected: both commands PASS.

- [ ] **Step 7: Commit the authoring experience**

```bash
git add resources/js/editor resources/js/canvas resources/js/http.ts resources/js/presentation/node.ts
git commit -m "feat: author trigger nodes visually"
```

### Task 14: Generators, installer, health checks, and public exports

**Files:**
- Rewrite: `src/Console/MakeTriggerCommand.php`
- Create: `src/Console/MakeTriggerSourceCommand.php`
- Create: `src/Console/MakeTriggerDriverCommand.php`
- Modify: `src/Console/NodeRegistrationWriter.php`
- Modify: `src/Console/Install/ProviderStep.php`
- Modify: `src/Console/InstallCommand.php`
- Modify: `src/Console/CheckNodeTypesResolver.php`
- Modify: `src/Console/CheckNodeTypesCommand.php`
- Modify: `src/NodeflowServiceProvider.php`
- Modify: `resources/js/index.ts`
- Modify: `stubs/nodeflow-provider.stub`
- Create: `stubs/trigger-node.stub`
- Create: `stubs/trigger-source.stub`
- Create: `stubs/trigger-driver.stub`
- Rewrite test: `tests/Feature/MakeTriggerCommandTest.php`
- Create test: `tests/Feature/MakeTriggerSourceCommandTest.php`
- Create test: `tests/Feature/MakeTriggerDriverCommandTest.php`
- Modify test: `tests/Feature/Install/ProviderStepTest.php`
- Modify test: `tests/Feature/CheckNodeTypesCommandTest.php`
- Modify test: `resources/js/index.test.ts`

- [ ] **Step 1: Write failing generator and installer tests**

```php
it('scaffolds a trigger node against a registered driver', function () {
    $this->artisan('nodeflow:make-trigger', ['name' => 'StripePayment', '--driver' => 'webhook'])
        ->assertExitCode(0);

    expect($this->root.'/app/Nodeflow/Triggers/StripePayment.php')->toBeFile()
        ->and(file_get_contents($this->root.'/app/Nodeflow/Triggers/StripePayment.php'))
        ->toContain('implements TriggerNode')
        ->toContain("return 'webhook';");
});
```

Add equivalent parse/register/execute tests for source and driver generators, exact manual fallback
tests for all three provider anchors, provider registration-order assertions, health-check failures
for missing active trigger components, and TypeScript public export assertions.

- [ ] **Step 2: Run command and install tests to confirm missing commands**

Run: `vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeTriggerSourceCommandTest.php tests/Feature/MakeTriggerDriverCommandTest.php tests/Feature/Install/ProviderStepTest.php tests/Feature/CheckNodeTypesCommandTest.php --compact`

Expected: FAIL because the new commands, anchors, and health checks are absent.

- [ ] **Step 3: Implement distinct anchors and generators**

Add exact anchors `$triggerDrivers`, `$triggerNodes`, and `$triggerSources`; register in that order.
Each generator validates stable keys through existing `NodeTypeLiteral` rules, verifies the selected
driver is registered, writes parseable PHP, inserts the fully-qualified class under only its own
anchor, and prints a fully-qualified manual registration call when insertion is unsafe.

- [ ] **Step 4: Expand health checks and public exports**

Inspect active activations plus versions with live runs. Report missing trigger node, driver, and
source keys with flow/version/node identity and alias/remediation guidance. Export new TypeScript
payload/component types from `resources/js/index.ts`.

- [ ] **Step 5: Run tooling, public-boundary, and architecture tests**

Run: `vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeTriggerSourceCommandTest.php tests/Feature/MakeTriggerDriverCommandTest.php tests/Feature/Install tests/Feature/CheckNodeTypesCommandTest.php tests/Unit/ArchitectureTest.php --compact`

Run: `npm test -- resources/js/index.test.ts resources/js/run/boundary.test.ts`

Expected: both commands PASS.

- [ ] **Step 6: Commit tooling and exports**

```bash
git add src/Console src/NodeflowServiceProvider.php stubs resources/js/index.ts resources/js/index.test.ts tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeTriggerSourceCommandTest.php tests/Feature/MakeTriggerDriverCommandTest.php tests/Feature/Install tests/Feature/CheckNodeTypesCommandTest.php tests/Unit/ArchitectureTest.php
git commit -m "feat: scaffold trigger extensions"
```

### Task 15: Documentation migration and complete verification

**Files:**
- Rewrite: `README.md`
- Rewrite: `docs/gitbook/getting-started/quick-start.md`
- Rewrite: `docs/gitbook/building-automations/writing-triggers.md`
- Modify: `docs/gitbook/building-automations/starting-runs.md`
- Modify: `docs/gitbook/building-automations/flows-and-versions.md`
- Modify: `docs/gitbook/integration/registering-domain-components.md`
- Modify: `docs/gitbook/integration/tenancy.md`
- Rewrite: `docs/gitbook/reference/database-schema.md`
- Modify: `docs/gitbook/reference/contracts.md`
- Modify: `docs/gitbook/reference/routes.md`
- Modify: `docs/gitbook/reference/artisan-commands.md`
- Modify: `docs/gitbook/reference/statuses.md`
- Rewrite: `docs/gitbook/example-application/flood-alert-workflow.md`
- Modify: `docs/gitbook/example-application/testing-the-workflow.md`
- Modify: `docs/gitbook/contributing/architecture.md`
- Modify: `tests/Feature/WorkflowStudioDocumentationTest.php`
- Create: `tests/Feature/TriggerDocumentationTest.php`

- [ ] **Step 1: Write failing documentation contract tests**

```php
it('documents only the first-class trigger API', function () {
    $docs = documentationCorpus();

    expect($docs)->toContain('core.trigger.webhook')
        ->toContain('Nodeflow::registerTriggerSources')
        ->toContain('X-Nodeflow-Signature')
        ->not->toContain("'trigger_type' =>")
        ->not->toContain('TriggerRegistry::class');
});
```

- [ ] **Step 2: Run documentation tests and inventory stale API references**

Run: `vendor/bin/pest tests/Feature/TriggerDocumentationTest.php tests/Feature/WorkflowStudioDocumentationTest.php --compact`

Run: `rg -n "trigger_type|trigger_config|TriggerRegistry|EventTriggerListener|extends Trigger" README.md docs/gitbook src resources tests`

Expected: documentation tests FAIL and the inventory contains only files scheduled in this task or
intentional historical design/plan documents under `docs/superpowers`.

- [ ] **Step 3: Rewrite integration and reference documentation**

Document complete copyable source classes for webhook, model, and Laravel event integrations;
provider registration; signed request construction; mandatory idempotency; after-commit semantics;
manual/sub-flow bypass; trigger data; custom node-on-built-in-driver; custom driver registration; all
new routes, columns, contracts, commands, limits, and health checks. State explicitly that unsigned
webhooks, arbitrary class input, expression interpolation, multiple triggers, and bulk-update
observation are unsupported.

- [ ] **Step 4: Run focused trigger suites before the full build**

Run: `vendor/bin/pest tests/Unit/TriggerRegistriesTest.php tests/Unit/BuiltInTriggerDefinitionsTest.php tests/Feature/TriggerGraphValidationTest.php tests/Feature/PublishTriggerActivationTest.php tests/Feature/TriggerRunStarterTest.php tests/Feature/CustomTriggerDriverTest.php tests/Feature/WebhookTriggerTest.php tests/Feature/WebhookSecretTest.php tests/Feature/ModelObserverTriggerTest.php tests/Feature/EventTriggerTest.php tests/Feature/EditorRoutesTest.php tests/Feature/TriggerDocumentationTest.php --compact`

Expected: PASS.

- [ ] **Step 5: Run complete PHP, frontend, and package verification**

Run: `vendor/bin/pest --compact`

Expected: all PHP tests PASS.

Run: `npm test`

Expected: all Vitest tests PASS.

Run: `npm run types:check`

Expected: TypeScript exits 0 with no diagnostics.

Run: `php artisan nodeflow:install --check`

Expected: exit 0 and every installation check reports ready in the package test host. If the package
root is not a full Laravel host, run the existing installer feature suite as the authoritative
replacement and record that environment fact.

Run: `git diff --check`

Expected: no whitespace errors.

- [ ] **Step 6: Verify the final stale-reference inventory**

Run: `rg -n "trigger_type|trigger_config|TriggerRegistry|EventTriggerListener|extends Trigger" README.md docs/gitbook src resources tests`

Expected: no production, current documentation, or active test references. Historical files under
`docs/superpowers/specs` and `docs/superpowers/plans` are excluded from this command and remain
unchanged evidence.

- [ ] **Step 7: Commit documentation and final compatibility fixes**

```bash
git add README.md docs/gitbook tests/Feature/WorkflowStudioDocumentationTest.php tests/Feature/TriggerDocumentationTest.php
git commit -m "docs: document first-class trigger nodes"
```

- [ ] **Step 8: Request final code review before integration**

Invoke `superpowers:requesting-code-review` against the full implementation range. Address verified
findings with new failing tests, rerun the complete verification commands above, and keep unrelated
working-tree changes untouched.
