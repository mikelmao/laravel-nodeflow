# Starting runs

> **Experimental:** Nodeflow is pre-release software. Starting a run creates durable workflow work; authorize direct starts and make every node honour test mode before exposing a manual-start endpoint.

`StartRun` pins a new run to the flow's current published version, materializes its subject audience at the graph's start node, then starts the workflow engine.

## Start an authorized flow manually

The current service signature is:

```php
public function forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run
```

**File: `app/Http/Controllers/ManualFlowRunController.php`**

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;

class ManualFlowRunController
{
    public function store(Request $request, Flow $flow): JsonResponse
    {
        Gate::authorize('runManually', $flow);

        $data = $request->validate([
            'subject_type' => ['required', 'string'],
            'subject_ids' => ['required', 'array'],
            'subject_ids.*' => ['string'],
        ]);

        $run = app(StartRun::class)->forFlow(
            flow: $flow,
            subjectType: $data['subject_type'],
            subjectIds: $data['subject_ids'],
            options: [
                'idempotency_key' => null,
                'correlation_id' => null,
                'strategy' => 'cohort',
                'is_test' => false,
            ],
        );

        return response()->json(['id' => $run->id], 201);
    }
}
```

`$flow` must be a tenant-scoped, authorized model supplied by the host route. `StartRun` does not authorize a direct action and `Flow` allows mass assignment, so never resolve a flow from an untrusted ID without tenant scoping and an explicit `runManually` authorization check. Do not take `tenant_id`, a version ID, or `flow_version_id` from the request.

The recognized option keys are:

| Option | Default | Behavior |
| --- | --- | --- |
| `idempotency_key` | `null` | A non-null string identifies one start for the current flow version. |
| `correlation_id` | `null` | Stored on the run. Sub-flow starts use it for `>`-separated parent-run lineage. |
| `strategy` | `subject` for one supplied ID, otherwise `cohort` | Stored as supplied when present. The package does not validate this value; use only `subject` and `cohort`. |
| `is_test` | `false` | Stored as a boolean on the run and exposed to nodes through their execution contexts. |

The automatic strategy decision uses the number of supplied IDs before audience normalization. A one-item input becomes `subject`; any other count becomes `cohort`. If you need predictable display semantics, pass `strategy` explicitly. Although the meaningful current values are `subject` and `cohort`, there is no enum or service validation: an arbitrary supplied string is persisted unchanged.

The flow must have `current_version_id`; otherwise `forFlow()` throws `RuntimeException` and creates nothing. This is the package's unpublished-flow rejection. It reads that version, derives the graph start node, and creates a pending run whose `flow_version_id` is that version—not a future version that may be published later.

## Materialize a safe audience

Before the transaction writes audience rows, `AudienceMaterialiser` string-casts each subject ID, removes repeated IDs, and calls:

```php
TenantResolver::ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
```

for every remaining ID. If any check returns false, it throws `CrossTenantSubjectException`. The run insert and materialization are one transaction, and the materializer performs every ownership check before its own insert transaction, so the outcome is all or nothing: no run and no run subjects remain for a rejected start. Treat `ownsSubject()` as tenant isolation, not a convenience eligibility filter. See [Required contracts](../integration/required-contracts.md) for a complete resolver.

## Start from a trigger

**Listener call (partial):** `EventTriggerListener` calls `StartRun::forFlow()` once per matched tenant audience and active flow, with the trigger's idempotency key:

```php
app(StartRun::class)->forFlow(
    $flow,
    $audience['subject_type'],
    $audience['subject_ids'],
    ['idempotency_key' => $trigger->idempotencyKey($event)],
);
```

The listener selects active flows with a current version and calls the trigger's `matchesConfig()` before this start. It catches and reports a failed start per flow, so one bad audience does not prevent another tenant's matching flow from starting. Implement the trigger contract in [Writing triggers](writing-triggers.md).

## Start a child flow from a graph

The built-in `core.start_flow` node runs once for its audience. Its configuration is:

| Field | Required | Default | Behavior |
| --- | --- | --- | --- |
| `flow_id` | yes | none | Integer-cast target flow ID. The target must belong to the parent run's tenant. |
| `exit_this_flow` | no | `true` | When true, the node returns no output, so the current audience completes this flow after starting the child. When false, it returns the current audience on `default`. |

Internally it calls the exact service method:

```php
public function start(Run $parentRun, int $flowId, string $subjectType, array $subjectIds): ?Run
```

`SubFlowStarter` reads the target without tenancy scope but requires both the target ID and `tenant_id` to match the parent run. A cross-tenant target therefore raises Laravel's `ModelNotFoundException`. It passes the parent audience unchanged to `StartRun`, carries `is_test` from the parent, and creates the child correlation ID by appending the parent run ID to the existing `>`-separated lineage. A parent with no correlation ID creates one containing its own ID.

The maximum lineage depth is `SubFlowStarter::MAX_DEPTH`, currently `5`; it is a source constant, not a `nodeflow.php` setting. When the existing correlation ID already has five non-empty `>`-separated entries, `start()` returns `null` before it looks up a flow or creates a run. The node does not treat that `null` as an error; its `exit_this_flow` behavior still applies.

## Understand idempotency and engine timing

With a non-null `idempotency_key`, `StartRun` first looks for an existing run with the same `(flow_version_id, idempotency_key)` and returns it. The database has the same unique constraint. If concurrent deliveries both miss the pre-check, the losing insert catches the matching unique-constraint error and returns the winner. A null key has no recovery path because database `NULL` values are distinct; keyless starts are not deduplicated.

The engine starts only after the database transaction has committed the run and all audience rows. It receives `FlowInterpreter::class`, the run ID, and `nodeflow.limits.max_steps_per_run` (default `1000`), then the run is updated with the engine workflow ID. If the engine start or that update fails, the committed pending run remains; calling `forFlow()` again with the same idempotency key returns that run and does not retry the engine start. Monitor and recover such pending runs in application operations rather than assuming the idempotency mechanism makes engine startup exactly once.

## Exit subjects from a live run

The current exit API is:

```php
public function exit(Run $run, array $subjectIds): void
```

It finds active `RunSubject` rows in that run whose string IDs are supplied, then sets `status` to `exited`, fills `exited_at`, and clears `current_node_id`. It does not alter the run's `status`; run statuses include `pending`, `running`, `waiting`, `blocked`, `completed`, `failed`, and `cancelled` in the current execution model.

If that update leaves no active subjects and the run has an engine workflow ID and is live (`pending`, `running`, `waiting`, or `blocked`), it sends one `audienceEmptied` signal to the engine. A `core.wait` races its timeout against that signal, so the wait can wake early when the last active subject exits. Repeating an exit against an already exited subject has no extra row update; a terminal run records its exit but does not signal its engine workflow.

Nodeflow does not package a subject-to-live-runs lookup. If your application needs an event-driven exit, query from tenant-scoped parent runs and then pass only the runs your application has authorized:

**File: `app/Services/ExitResidentFromLiveRuns.php`**

```php
<?php

namespace App\Services;

use App\Models\Resident;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Models\Run;

class ExitResidentFromLiveRuns
{
    public function exit(Resident $resident): void
    {
        Run::query()
            ->where('tenant_id', (string) $resident->organization_id)
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->whereHas('subjects', fn ($query) => $query
                ->where('subject_type', 'resident')
                ->where('subject_id', (string) $resident->getKey())
                ->where('status', 'active'))
            ->each(function (Run $run) use ($resident): void {
                app(SubjectExiter::class)->exit($run, [(string) $resident->getKey()]);
            });
    }
}
```

Keep the tenant condition even when an application appears single-tenant; it documents the isolation requirement before a future integration adds tenancy. Add any application-specific authorization around the caller of this service.

## Treat test mode as a side-effect obligation

`is_test` is propagated from `StartRun` to `Run` and from a parent run to a sub-flow. It reaches node bodies as `SubjectContext::isTest()` and `AudienceContext::isTest()`. The runtime does not automatically suppress email, HTTP calls, writes, payments, or other external effects. Every custom node must use test mode to return a safe routing result without performing externally visible work. See [Writing nodes](writing-nodes.md) for the node contract.

## Next step

Review a run's progress and exited subjects in [Inspecting runs](../editor-and-run-view/inspecting-runs.md).
