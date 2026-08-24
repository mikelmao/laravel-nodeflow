# Nodeflow Portia Production Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Nodeflow safely admit 100,000-subject external audiences, apply each published node's retry policy to its durable activity, and project terminal durable-engine failures into Nodeflow runs.

**Architecture:** A replayable subject stream crosses the trigger boundary and is validated/inserted in fixed-size batches without whole-audience PHP arrays. Publication freezes node activity policy into the immutable graph, and the interpreter passes that snapshot to durable-workflow `ActivityOptions`. A package listener projects terminal `WorkflowFailed` events into the Nodeflow run and its still-active subjects.

**Tech Stack:** PHP 8.3, Laravel 12/13 components, Eloquent, PostgreSQL/SQLite, Pest 4, durable-workflow/workflow V2.

---

## Prerequisite and scope

The first-class trigger plan is satisfied by commit `e99f8d7`. This plan is based on the merged
`TriggerMatch`, `TriggerTenantMatch`, `TriggerRunStarter`, webhook driver, and `CreateRun` APIs. It
removes every eager audience conversion now present at those boundaries and preserves the merged
trigger activation, run-idempotency, and dispatch-recovery behavior.

This plan changes only generic Nodeflow behavior. It does not add Rada, Portia, Yaya, SMS, offer, or
HTTP-client concepts to the package.

## File and dependency map

Audience admission:

- `src/Execution/ReplayableSubjectIds.php` — a fresh iterable for each matching flow/run.
- `src/Contracts/BatchTenantResolver.php` — optional set-based ownership capability.
- `src/Triggers/TriggerMatch.php` — accepts a replayable source without eagerly converting it.
- `src/Triggers/TriggerTenantMatch.php` — carries the replayable stream instead of an array.
- `src/Triggers/TriggerRunStarter.php` — delegates admission and ownership checks to one boundary.
- `src/Triggers/Webhook/WebhookTriggerDriver.php` — validates a replayable audience without consuming it.
- `src/Execution/AudienceMaterialiser.php` — fixed-size validation and `insertOrIgnore` batches.
- `src/Execution/CreateRun.php` — streams IDs and derives strategy from the admitted count.

Activity durability:

- `src/Execution/NodeActivityPolicy.php` — validated, JSON-safe published retry policy.
- `src/Publishing/CompileNodeActivityPolicies.php` — freezes policy into executable graph nodes.
- `src/Nodes/Node.php` — author-facing retry/backoff/timeout declarations.
- `src/Workflows/FlowInterpreter.php` — passes the frozen policy as `ActivityOptions`.

Failure projection:

- `src/Workflows/ProjectWorkflowFailure.php` — idempotent durable failure listener.
- `src/NodeflowServiceProvider.php` — registers the listener.
- `src/Runs/RunOverlay.php` — recognizes failed/cancelled terminal statuses.

### Task 1: Replayable lazy trigger audiences

**Files:**
- Create: `src/Execution/ReplayableSubjectIds.php`
- Modify: `src/Triggers/TriggerMatch.php`
- Modify: `src/Triggers/TriggerTenantMatch.php`
- Modify: `src/Triggers/Webhook/WebhookTriggerDriver.php`
- Modify: `tests/Feature/CustomTriggerDriverTest.php`
- Modify: `tests/Feature/TriggerRunStarterTest.php`
- Modify: `tests/Feature/WebhookTriggerTest.php`
- Modify: `tests/Unit/TriggerRegistriesTest.php`
- Create: `tests/Unit/ReplayableSubjectIdsTest.php`

- [ ] **Step 1: Write failing replay and laziness tests**

```php
use Nodeflow\Execution\ReplayableSubjectIds;

it('does not invoke an audience factory until iteration and replays it per run', function () {
    $calls = 0;
    $ids = ReplayableSubjectIds::from(function () use (&$calls): iterable {
        $calls++;
        yield '10';
        yield '20';
    });

    expect($calls)->toBe(0)
        ->and(iterator_to_array($ids, false))->toBe(['10', '20'])
        ->and(iterator_to_array($ids, false))->toBe(['10', '20'])
        ->and($calls)->toBe(2);
});

it('rejects a one-shot generator unless its factory is supplied', function () {
    $generator = (function (): iterable { yield '10'; })();

    expect(fn () => ReplayableSubjectIds::from($generator))
        ->toThrow(InvalidArgumentException::class, 'one-shot');
});

it('rejects blank identifiers lazily without consuming a later replay', function () {
    $ids = ReplayableSubjectIds::from(fn (): iterable => ['10', ' ', '20']);

    expect(fn () => iterator_to_array($ids, false))
        ->toThrow(InvalidArgumentException::class, 'blank subject ID');
});
```

In `CustomTriggerDriverTest`, publish two matching flows for one activation tenant and configure the
source to return the same `TriggerMatch` instance for both activation resolutions. Build that match
from an audience factory closure and assert each run receives all subjects, proving the first run
cannot consume the second run's source.

- [ ] **Step 2: Run the focused tests and confirm the value is absent**

Run: `vendor/bin/pest tests/Unit/ReplayableSubjectIdsTest.php tests/Feature/CustomTriggerDriverTest.php --compact`

Expected: FAIL because `ReplayableSubjectIds` does not exist and the merged trigger boundary still
converts every audience to an array.

- [ ] **Step 3: Implement the replayable iterable**

```php
<?php

namespace Nodeflow\Execution;

use Closure;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/** @implements IteratorAggregate<int, string> */
final class ReplayableSubjectIds implements IteratorAggregate
{
    private function __construct(private Closure $factory) {}

    public static function from(iterable|Closure $source): self
    {
        if ($source instanceof Closure) {
            return new self($source);
        }

        if (is_array($source)) {
            return new self(static fn (): iterable => $source);
        }

        if ($source instanceof IteratorAggregate) {
            return new self(static fn (): Traversable => $source->getIterator());
        }

        throw new InvalidArgumentException(
            'A one-shot subject iterator must be supplied as a closure that creates a fresh iterable.'
        );
    }

    public function getIterator(): Traversable
    {
        $subjects = ($this->factory)();

        if (! is_iterable($subjects)) {
            throw new InvalidArgumentException('The subject factory must return an iterable.');
        }

        foreach ($subjects as $subjectId) {
            $subjectId = (string) $subjectId;

            if (trim($subjectId) === '') {
                throw new InvalidArgumentException('A trigger audience must not contain a blank subject ID.');
            }

            yield $subjectId;
        }
    }

    public function isEmpty(): bool
    {
        foreach ($this as $_) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 4: Make each tenant match normalize its source**

Replace the array-promoted `subjectIds` property in `TriggerTenantMatch` with:

```php
final readonly class TriggerTenantMatch
{
    public function __construct(
        string $tenantId,
        string $subjectType,
        iterable|Closure $subjectIds,
        public array $triggerData = [],
        ?string $occurrenceId = null,
    ) {
        if (trim($tenantId) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank tenant ID.');
        }

        if (trim($subjectType) === '') {
            throw new InvalidArgumentException('A trigger tenant match must have a nonblank subject type.');
        }

        if ($occurrenceId !== null && trim($occurrenceId) === '') {
            throw new InvalidArgumentException('A trigger tenant match occurrence ID must be null or nonblank.');
        }

        $this->tenantId = $tenantId;
        $this->subjectType = $subjectType;
        $this->subjectIds = ReplayableSubjectIds::from($subjectIds);
        $this->occurrenceId = $occurrenceId;
    }

    public string $tenantId;
    public string $subjectType;
    public ReplayableSubjectIds $subjectIds;
    public ?string $occurrenceId;
}
```

Change `TriggerMatch::forTenant()` to accept `iterable|Closure` and pass the source directly into
`TriggerTenantMatch`; remove its `iterator_to_array()` conversion. Change the webhook driver's empty
audience check from strict array comparison to `$matches[0]->subjectIds->isEmpty()`. That check starts
a fresh replay, so the later run still receives the complete audience.

Keep `TriggerOccurrenceDispatcher` passing each replayable match unchanged. Existing arrays remain
source-compatible; large remote audiences use a closure returning a fresh cursor/page generator.
Update existing assertions that compare `subjectIds` to an array to explicitly collect the iterable
with `iterator_to_array($match->subjectIds, false)`. Tests that previously expected blank IDs to fail
at match construction must now begin iteration and assert the same failure there.

- [ ] **Step 5: Rerun and commit the replay boundary**

Run: `vendor/bin/pest tests/Unit/ReplayableSubjectIdsTest.php tests/Unit/TriggerRegistriesTest.php tests/Feature/CustomTriggerDriverTest.php tests/Feature/TriggerRunStarterTest.php tests/Feature/WebhookTriggerTest.php --compact`

Expected: PASS, including two runs consuming the same lazy source independently.

```bash
git add src/Execution/ReplayableSubjectIds.php src/Triggers/TriggerMatch.php src/Triggers/TriggerTenantMatch.php src/Triggers/Webhook/WebhookTriggerDriver.php tests/Unit/ReplayableSubjectIdsTest.php tests/Unit/TriggerRegistriesTest.php tests/Feature/CustomTriggerDriverTest.php tests/Feature/TriggerRunStarterTest.php tests/Feature/WebhookTriggerTest.php
git commit -m "feat: stream replayable trigger audiences"
```

### Task 2: Bounded audience materialization and batch tenancy

**Files:**
- Create: `src/Contracts/BatchTenantResolver.php`
- Modify: `src/Execution/AudienceMaterialiser.php`
- Modify: `src/Execution/CreateRun.php`
- Modify: `src/Triggers/TriggerRunStarter.php`
- Modify: `config/nodeflow.php`
- Modify: `tests/Feature/AudienceMaterialiserTest.php`
- Modify: `tests/Feature/TriggerRunStarterTest.php`

- [ ] **Step 1: Write failing bounded-batch tests**

Bind a resolver implementing the new contract and record each call:

```php
$resolver = new class implements BatchTenantResolver {
    public array $calls = [];
    public function currentTenantId(): ?string { return 'org-1'; }
    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        throw new LogicException('The scalar fallback must not run.');
    }
    public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
    {
        $this->calls[] = $subjectIds;
        return $subjectIds;
    }
};
app()->instance(TenantResolver::class, $resolver);

config()->set('nodeflow.limits.materialise_chunk', 2);
$count = app(AudienceMaterialiser::class)->materialise(
    $run,
    'user',
    (function (): iterable { yield from ['1', '2', '3', '1', '4']; })(),
    'first-action',
);

expect($count)->toBe(4)
    ->and(array_map('count', $resolver->calls))->toBe([2, 2, 1])
    ->and($run->subjects()->count())->toBe(4);
```

Add a second case whose batch resolver omits subject `3`; assert
`CrossTenantSubjectException('3')` and zero run subjects. Retain the existing scalar
`TenantResolver` test to prove backwards-compatible per-subject validation. Add a
`TriggerRunStarterTest` case that starts a trigger match through a `BatchTenantResolver`, asserts
that scalar `ownsSubject()` is never called, and records only bounded batch calls. This proves
ownership is enforced once in the materializer rather than once in the starter and again during
admission.

- [ ] **Step 2: Run and confirm eager/scalar behavior**

Run: `vendor/bin/pest tests/Feature/AudienceMaterialiserTest.php tests/Feature/TriggerRunStarterTest.php --compact`

Expected: FAIL because `BatchTenantResolver` and `materialise_chunk` do not exist.

- [ ] **Step 3: Add the optional batch contract**

```php
<?php

namespace Nodeflow\Contracts;

interface BatchTenantResolver extends TenantResolver
{
    /**
     * Return every ID from $subjectIds that belongs to the tenant.
     * Missing IDs fail closed; returned IDs must be a subset of the request.
     *
     * @param list<string> $subjectIds
     * @return list<string>
     */
    public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array;
}
```

Add `'materialise_chunk' => 1000` under `nodeflow.limits`.

- [ ] **Step 4: Replace whole-audience buffers with fixed-size flushes**

Use this algorithm in `AudienceMaterialiser`; preserve the surrounding all-or-nothing transaction:

```php
public function materialise(Run $run, string $subjectType, iterable $subjectIds, ?string $startNodeId = null): int
{
    return DB::transaction(function () use ($run, $subjectType, $subjectIds, $startNodeId): int {
        $inserted = 0;
        $buffer = [];
        $limit = max(1, (int) config('nodeflow.limits.materialise_chunk', 1000));

        foreach ($subjectIds as $subjectId) {
            $buffer[] = (string) $subjectId;

            if (count($buffer) === $limit) {
                $inserted += $this->flush($run, $subjectType, $buffer, $startNodeId);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $inserted += $this->flush($run, $subjectType, $buffer, $startNodeId);
        }

        return $inserted;
    });
}
```

The private `flush()` normalizes and deduplicates only its current batch. When the resolver implements
`BatchTenantResolver`, normalize its returned list, reject unexpected IDs with
`UnexpectedValueException`, and throw `CrossTenantSubjectException` for the first requested ID not
returned. Otherwise call `ownsSubject()` for each ID. Build the existing run-subject rows and return:

```php
return DB::table('nodeflow_run_subjects')->insertOrIgnore($rows);
```

The unique `(run_id, subject_type, subject_id)` key deduplicates IDs repeated in later batches without
an unbounded PHP `$seen` set.

- [ ] **Step 5: Make `CreateRun` derive strategy after streaming**

Remove the `TenantResolver` dependency, scalar ownership loop, and audience array conversion from
`TriggerRunStarter`; pass `$match->subjectIds` directly to `CreateRun`. `AudienceMaterialiser` is the
single ownership-enforcement boundary, using `BatchTenantResolver` when available and the scalar
fallback otherwise. A `CrossTenantSubjectException` still propagates through `TriggerRunStarter`, so
the merged webhook driver continues translating it into `WebhookSourceRejected`.

Remove `iterator_to_array()` and `count($ids)` from `CreateRun`. Create the pending run with an
explicit option or provisional `cohort`, stream directly into the materializer, then update the
automatic strategy from the inserted count inside the same transaction:

```php
$run = Run::create([
    // existing trusted version/tenant/origin fields
    'strategy' => $options['strategy'] ?? 'cohort',
    'status' => 'pending',
]);

$subjectCount = $this->materialiser->materialise(
    $run, $subjectType, $subjectIds, $entryNodeId
);

if (! array_key_exists('strategy', $options)) {
    $run->update(['strategy' => $subjectCount === 1 ? 'subject' : 'cohort']);
}
```

Keep merged `CreateRun` idempotency recovery, deterministic workflow instance identity, and dispatch
recovery unchanged. Start the engine only after the materialization transaction commits.

- [ ] **Step 6: Rerun and commit bounded admission**

Run: `vendor/bin/pest tests/Feature/AudienceMaterialiserTest.php tests/Feature/TriggerRunStarterTest.php tests/Feature/StartRunTest.php tests/Feature/TenancyTest.php --compact`

Expected: PASS with batch calls bounded to configured size, duplicates ignored, and invalid ownership
rolling back the entire audience.

```bash
git add src/Contracts/BatchTenantResolver.php src/Execution/AudienceMaterialiser.php src/Execution/CreateRun.php src/Triggers/TriggerRunStarter.php config/nodeflow.php tests/Feature/AudienceMaterialiserTest.php tests/Feature/TriggerRunStarterTest.php
git commit -m "feat: materialize large audiences in tenant-safe batches"
```

### Task 3: Freeze node activity policy into published graphs

**Files:**
- Create: `src/Execution/NodeActivityPolicy.php`
- Create: `src/Publishing/CompileNodeActivityPolicies.php`
- Modify: `src/Nodes/Node.php`
- Modify: `src/Publishing/PublishFlow.php`
- Create: `tests/Support/FakeRetryingAudienceNode.php`
- Create: `tests/Unit/NodeActivityPolicyTest.php`
- Modify: `tests/Feature/PublishFlowTest.php`

- [ ] **Step 1: Write failing policy normalization and publication tests**

```php
it('normalizes a node retry policy into durable activity options', function () {
    $policy = NodeActivityPolicy::fromNode(new FakeRetryingAudienceNode);
    $options = $policy->activityOptions();

    expect($policy->toArray())->toBe([
        'max_attempts' => 5,
        'backoff' => [1, 5, 30, 120],
        'start_to_close_timeout' => 90,
        'non_retryable_error_types' => [InvalidArgumentException::class],
    ])->and($options->maxAttempts)->toBe(5)
        ->and($options->backoff)->toBe([1, 5, 30, 120])
        ->and($options->startToCloseTimeout)->toBe(90);
});

it('freezes executable activity policy in the published graph', function () {
    $result = app(PublishFlow::class)->publish($flow, triggeredGraphWith('test.retrying-audience'));
    $node = collect($result->version->graph['nodes'])->firstWhere('type', 'test.retrying-audience');

    expect($node['runtime']['activity']['max_attempts'])->toBe(5)
        ->and($node['runtime']['activity']['start_to_close_timeout'])->toBe(90);
});
```

- [ ] **Step 2: Run and confirm policy is not persisted**

Run: `vendor/bin/pest tests/Unit/NodeActivityPolicyTest.php tests/Feature/PublishFlowTest.php --compact`

Expected: FAIL because nodes expose only `$tries` and publication stores no runtime policy.

- [ ] **Step 3: Extend the author-facing node policy**

Add these properties to `Node`; the values are node-type defaults, not editable workflow fields:

```php
/** Maximum durable attempts for one logical node execution. */
public int $tries = 3;

/** @var int|list<int> seconds between durable attempts */
public int|array $backoff = [1, 2, 5, 10, 15, 30, 60, 120];

/** Maximum seconds for one activity attempt; null uses the engine default. */
public ?int $timeout = null;

/** @var list<class-string<\Throwable>> */
public array $nonRetryableErrorTypes = [];
```

`FakeRetryingAudienceNode` implements `HandlesAudience`, declares the values from the test, and
returns `$context->all('accepted')` without external effects.

- [ ] **Step 4: Implement the immutable policy value**

`NodeActivityPolicy` validates `maxAttempts >= 1`, non-negative integer backoff values, a positive
nullable timeout, and non-empty exception class strings. It exposes `fromNode()`, `fromArray()`,
`toArray()`, and:

```php
public function activityOptions(): ActivityOptions
{
    return new ActivityOptions(
        maxAttempts: $this->maxAttempts,
        backoff: $this->backoff,
        startToCloseTimeout: $this->startToCloseTimeout,
        nonRetryableErrorTypes: $this->nonRetryableErrorTypes,
    );
}
```

Use named readonly constructor fields `maxAttempts`, `backoff`, `startToCloseTimeout`, and
`nonRetryableErrorTypes`. `fromArray([])` returns the same defaults declared by `Node` so legacy
published graphs remain executable.

- [ ] **Step 5: Compile policy before version hashing and persistence**

`CompileNodeActivityPolicies` receives `NodeRegistry`, skips `graph.start` because it is the trigger
node, resolves every executable node type, and writes:

```php
$definition['runtime']['activity'] = NodeActivityPolicy::fromNode($node)->toArray();
```

Inject the compiler into `PublishFlow`. After graph validation and before `content_hash` or
`FlowVersion::create`, replace `$graph` with `$this->activityPolicies->compile($graph)`. Thus two
versions with different policies have different hashes, and an existing run never observes a later
node-class property change.

- [ ] **Step 6: Rerun and commit immutable policy compilation**

Run: `vendor/bin/pest tests/Unit/NodeActivityPolicyTest.php tests/Feature/PublishFlowTest.php tests/Feature/PublishTriggerActivationTest.php --compact`

Expected: PASS with trigger metadata intact and executable nodes carrying JSON-safe activity policy.

```bash
git add src/Execution/NodeActivityPolicy.php src/Publishing/CompileNodeActivityPolicies.php src/Nodes/Node.php src/Publishing/PublishFlow.php tests/Support/FakeRetryingAudienceNode.php tests/Unit/NodeActivityPolicyTest.php tests/Feature/PublishFlowTest.php
git commit -m "feat: freeze node activity policies on publish"
```

### Task 4: Apply published policy to durable node activities

**Files:**
- Modify: `src/Workflows/FlowInterpreter.php`
- Create: `tests/Feature/FlowInterpreterActivityPolicyTest.php`
- Modify: `tests/Feature/FlowInterpreterSignalTest.php`

- [ ] **Step 1: Write a failing interpreter integration test**

Publish a graph containing `FakeRetryingAudienceNode`, start it through the real
`DurableWorkflowEngine`, and fake the queue so each durable task can be driven explicitly. Run the
initial workflow task, the `LoadGraphActivity` task, and the resumed workflow task in order:

```php
Queue::fake();
app()->bind(WorkflowEngine::class, DurableWorkflowEngine::class);

$run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1']);
$engineRun = WorkflowRun::query()
    ->where('workflow_instance_id', $run->engine_workflow_id)
    ->sole();

$workflowTask = WorkflowTask::query()
    ->where('workflow_run_id', $engineRun->id)
    ->where('task_type', TaskType::Workflow->value)
    ->where('status', TaskStatus::Ready->value)
    ->sole();
app()->call([new RunWorkflowTask($workflowTask->id), 'handle']);

$loadGraphTask = WorkflowTask::query()
    ->where('workflow_run_id', $engineRun->id)
    ->where('task_type', TaskType::Activity->value)
    ->where('status', TaskStatus::Ready->value)
    ->sole();
app()->call([new RunActivityTask($loadGraphTask->id), 'handle']);

$resumedTask = WorkflowTask::query()
    ->where('workflow_run_id', $engineRun->id)
    ->where('task_type', TaskType::Workflow->value)
    ->where('status', TaskStatus::Ready->value)
    ->sole();
app()->call([new RunWorkflowTask($resumedTask->id), 'handle']);

$execution = ActivityExecution::query()
    ->where('workflow_run_id', $engineRun->id)
    ->where('activity_class', RunNodeActivity::class)
    ->sole();
```

Then assert:

```php
expect($execution->retry_policy)->toMatchArray([
    'max_attempts' => 5,
    'backoff_seconds' => [1, 5, 30, 120],
    'start_to_close_timeout' => 90,
    'non_retryable_error_types' => [InvalidArgumentException::class],
]);
```

Use the durable package's `RunWorkflowTask` job against the ready workflow task; do not run the
activity itself. This isolates scheduling policy from node behavior and leaves no network dependency.

- [ ] **Step 2: Run and confirm the activity still uses class defaults**

Run: `vendor/bin/pest tests/Feature/FlowInterpreterActivityPolicyTest.php --compact`

Expected: FAIL because `FlowInterpreter` calls `self::activity()` without `ActivityOptions`, leaving
`RunNodeActivity` at one attempt.

- [ ] **Step 3: Pass options as the first activity argument**

Replace the `RunNodeStep` branch with:

```php
} elseif ($step instanceof RunNodeStep) {
    $definition = $graph->node($step->nodeId);
    $policy = NodeActivityPolicy::fromArray(
        $definition['runtime']['activity'] ?? []
    );

    $send = self::activity(
        RunNodeActivity::class,
        $policy->activityOptions(),
        $runId,
        $step->nodeId,
    );
}
```

Import `NodeActivityPolicy`. `ActivityOptions` must be the first variadic argument because
durable-workflow removes only the first argument when it is an options instance; putting it after the
run/node IDs would serialize it into `RunNodeActivity::handle()` instead.

- [ ] **Step 4: Add legacy-graph coverage and rerun**

Create a version fixture without `runtime.activity`, schedule it, and assert the stable
`NodeActivityPolicy::fromArray([])` defaults. Also rerun the signal test to prove activity options do
not change wait/signal compilation.

Run: `vendor/bin/pest tests/Feature/FlowInterpreterActivityPolicyTest.php tests/Feature/FlowInterpreterSignalTest.php tests/Feature/RunNodeActivityTest.php --compact`

Expected: PASS; the activity execution stores the published values and the handler still receives
only integer run ID and string node ID.

- [ ] **Step 5: Commit durable activity policy wiring**

```bash
git add src/Workflows/FlowInterpreter.php tests/Feature/FlowInterpreterActivityPolicyTest.php tests/Feature/FlowInterpreterSignalTest.php
git commit -m "feat: apply node policies to durable activities"
```

### Task 5: Project terminal durable failures into Nodeflow

**Files:**
- Create: `src/Workflows/ProjectWorkflowFailure.php`
- Modify: `src/NodeflowServiceProvider.php`
- Modify: `src/Runs/RunOverlay.php`
- Create: `tests/Feature/WorkflowFailureProjectionTest.php`
- Modify: `tests/Feature/RunOverlayTest.php`

- [ ] **Step 1: Write failing projection tests**

Create a live Nodeflow run with active subjects but leave `engine_workflow_id` null. This models the
real race where the deterministic durable workflow has started and emitted a failure before
`CreateRun` persisted its returned handle. Dispatch this event twice through Laravel's event
dispatcher so the test proves provider registration, race handling, and idempotency:

```php
$instanceId = "nodeflow-run:{$run->id}";

$event = new WorkflowFailed(
    instanceId: $instanceId,
    runId: 'durable-run-4',
    workflowType: 'class',
    workflowClass: FlowInterpreter::class,
    exceptionClass: RuntimeException::class,
    message: 'Yaya remained unavailable',
    committedAt: now()->toISOString(),
);

Event::dispatch($event);
Event::dispatch($event);
```

Assert the run is `failed`, `ended_at` is set, `error` contains the exception/message, every formerly
active subject is `failed` with a null cursor, and duplicate delivery does not change the original
terminal timestamp. Add coverage for a run whose `engine_workflow_id` already equals its
deterministic instance ID. Events with a malformed or unrelated instance ID, a mismatched persisted
handle, or a non-Nodeflow workflow class must change nothing.

- [ ] **Step 2: Run and confirm no listener exists**

Run: `vendor/bin/pest tests/Feature/WorkflowFailureProjectionTest.php tests/Feature/RunOverlayTest.php --compact`

Expected: FAIL because terminal workflow failure is not projected and the overlay considers only
`completed` terminal.

- [ ] **Step 3: Implement the idempotent failure projector**

```php
final class ProjectWorkflowFailure
{
    private const LIVE = ['pending', 'running', 'waiting', 'blocked'];

    private const INSTANCE_PREFIX = 'nodeflow-run:';

    public function handle(WorkflowFailed $event): void
    {
        if ($event->workflowClass !== FlowInterpreter::class) {
            return;
        }

        DB::transaction(function () use ($event): void {
            $run = $this->runFor($event);

            if ($run === null || ! in_array($run->status, self::LIVE, true)) {
                return;
            }

            $error = Str::limit(
                $event->exceptionClass.': '.$event->message,
                65535,
                ''
            );

            $run->subjects()->where('status', 'active')->update([
                'status' => 'failed',
                'last_error' => $error,
                'current_node_id' => null,
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $error,
                'ended_at' => $event->committedAt,
            ]);
        });
    }

    private function runFor(WorkflowFailed $event): ?Run
    {
        if (! str_starts_with($event->instanceId, self::INSTANCE_PREFIX)) {
            return null;
        }

        $id = substr($event->instanceId, strlen(self::INSTANCE_PREFIX));

        if ($id === '' || ! ctype_digit($id) || (int) $id < 1) {
            return null;
        }

        $run = Run::withoutTenancy()->whereKey((int) $id)->lockForUpdate()->first();

        if ($run === null) {
            return null;
        }

        $expected = self::INSTANCE_PREFIX.$run->id;

        if (! hash_equals($expected, $event->instanceId)) {
            return null;
        }

        if ($run->engine_workflow_id !== null
            && ! hash_equals($expected, (string) $run->engine_workflow_id)) {
            return null;
        }

        return $run;
    }
}
```

Import `DB`, `Str`, `Run`, `FlowInterpreter`, and the durable event. Looking up by
the exact merged `nodeflow-run:{run_id}` identity closes the start-before-handle-persistence race.
The workflow-class check, strict prefix parsing, primary-key lookup, recomputed identity, and optional
persisted-handle comparison prevent unrelated durable workflows from mutating Nodeflow runs.

- [ ] **Step 4: Register the event and expose terminal state**

In `NodeflowServiceProvider::boot()` register:

```php
Event::listen(WorkflowFailed::class, ProjectWorkflowFailure::class);
```

Import Laravel's `Event` facade and both classes. Change `RunOverlay::TERMINAL_STATUSES` to:

```php
private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];
```

Replace the obsolete comment claiming only completion can be terminal. Add an overlay assertion that
a failed run returns `terminal => true`, its status, and the run-level error through the existing run
payload if that payload already exposes `error`; do not overload per-node failure counts with the
workflow-level exception.

- [ ] **Step 5: Rerun and commit failure projection**

Run: `vendor/bin/pest tests/Feature/WorkflowFailureProjectionTest.php tests/Feature/RunOverlayTest.php tests/Feature/RunViewTest.php tests/Feature/PruneCommandTest.php --compact`

Expected: PASS; a terminal durable failure stops client polling and becomes eligible for normal
failed-run retention.

```bash
git add src/Workflows/ProjectWorkflowFailure.php src/NodeflowServiceProvider.php src/Runs/RunOverlay.php tests/Feature/WorkflowFailureProjectionTest.php tests/Feature/RunOverlayTest.php
git commit -m "feat: project durable workflow failures into runs"
```

### Task 6: Scale proof, documentation, and full verification

**Files:**
- Create: `tests/Feature/LargeAudienceAdmissionTest.php`
- Modify: `docs/gitbook/integration/required-contracts.md`
- Modify: `docs/gitbook/building-automations/writing-nodes.md`
- Modify: `docs/gitbook/operations/durable-execution.md`
- Modify: `docs/gitbook/reference/configuration.md`
- Modify: `docs/gitbook/reference/statuses.md`
- Modify: `docs/gitbook/experimental/known-limitations.md`

- [ ] **Step 1: Add an opt-in 100,000-subject scale test**

```php
it('admits a six-figure lazy audience in fixed ownership batches', function () {
    $total = (int) (getenv('NODEFLOW_SCALE_SUBJECTS') ?: 100000);
    config()->set('nodeflow.limits.materialise_chunk', 1000);

    $resolver = new RecordingBatchTenantResolver;
    app()->instance(TenantResolver::class, $resolver);

    $ids = function () use ($total): iterable {
        for ($id = 1; $id <= $total; $id++) {
            yield (string) $id;
        }
    };

    $run = app(CreateRun::class)->forVersion(
        $version,
        'yaya-user',
        ReplayableSubjectIds::from($ids),
        'first-action',
        ['started_via' => 'scale-test', 'trigger_node_id' => 'trigger'],
    );

    expect($run->subjects()->count())->toBe($total)
        ->and($resolver->largestBatch)->toBeLessThanOrEqual(1000)
        ->and($resolver->scalarCalls)->toBe(0);
})->group('scale');
```

Define `RecordingBatchTenantResolver` in the same test file with public counters,
`ownedSubjectIds()` returning its input, and `ownsSubject()` incrementing `scalarCalls` before
returning true. Reuse the graph/version setup from `TriggerRunStarterTest`.

- [ ] **Step 2: Run the bounded default probe**

Run: `NODEFLOW_SCALE_SUBJECTS=10000 vendor/bin/pest tests/Feature/LargeAudienceAdmissionTest.php --compact`

Expected: PASS with 10,000 persisted subjects, no scalar ownership calls, and a largest batch of
1,000. Run the 100,000-subject form against PostgreSQL in the Portia integration phase rather than
making every package test run pay that cost.

- [ ] **Step 3: Update the public contract and operations documentation**

Document these exact behaviors:

- `required-contracts.md`: `BatchTenantResolver` is optional and falls back safely to
  `ownsSubject()`; hosts serving large remote audiences should bind the batch contract. Document
  that `TriggerMatch`, `TriggerTenantMatch`, `TriggerRunStarter`, and the webhook driver preserve a
  replayable audience and that ownership is enforced centrally during materialization.
- `writing-nodes.md`: `$tries`, `$backoff`, `$timeout`, and `$nonRetryableErrorTypes` are frozen at
  publish; audience-node transport exceptions retry the durable activity, while stable business
  rejection should return a `NodeResult` output.
- `durable-execution.md`: retry replays one logical node activity, so externally visible actions need
  deterministic idempotency keys.
- `configuration.md`: add `limits.materialise_chunk` and its 1,000 default.
- `statuses.md`: `failed` is terminal when projected from `WorkflowFailed`; active subjects are failed
  and their cursors cleared in the same transaction.
- `known-limitations.md`: remove the stale “durable failure leaves a run running” limitation and keep
  unrelated limitations unchanged.

- [ ] **Step 4: Run format, static, focused, and full tests**

Run:

```bash
vendor/bin/pint --test
vendor/bin/pest tests/Unit/ReplayableSubjectIdsTest.php tests/Feature/AudienceMaterialiserTest.php tests/Unit/NodeActivityPolicyTest.php tests/Feature/FlowInterpreterActivityPolicyTest.php tests/Feature/WorkflowFailureProjectionTest.php tests/Feature/LargeAudienceAdmissionTest.php --compact
vendor/bin/pest --compact
```

Expected: Pint exits 0; the focused readiness suites pass; then the complete package suite passes
with no regression in trigger, tenancy, publishing, interpreter, editor, or run-view tests.

- [ ] **Step 5: Commit the verified readiness slice**

```bash
git add tests/Feature/LargeAudienceAdmissionTest.php docs/gitbook/integration/required-contracts.md docs/gitbook/building-automations/writing-nodes.md docs/gitbook/operations/durable-execution.md docs/gitbook/reference/configuration.md docs/gitbook/reference/statuses.md docs/gitbook/experimental/known-limitations.md
git commit -m "docs: publish Nodeflow production readiness guidance"
```

## Completion gate

Do not begin the Portia–Yaya capability-foundation plan until all six tasks pass and a Nodeflow
release/tag containing merged first-class triggers plus this readiness work is available to
`portia-engine`.
The release contract must include replayable audiences, `BatchTenantResolver`, published activity
policy, and terminal failure projection; downstream plans must use those APIs rather than copying
their mechanics into Portia.
