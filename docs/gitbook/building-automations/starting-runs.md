# Starting runs

Every run is created for an exact published `FlowVersion` and exact executable entry node. The graph's trigger is origin metadata; it is not the first executed node.

## Trigger starts

A driver captures or loads an immutable `TriggerActivationSnapshot`, then a registered `TriggerSource` returns `TriggerMatch` audiences. `TriggerOccurrenceDispatcher` validates each pinned activation before source code runs, selects only the matching tenant audience, and isolates failures so one flow cannot prevent other matching flows from starting.

`TriggerRunStarter` independently revalidates the activation and tenant boundary. It starts the snapshot's `flow_version_id` and the executable target of the snapshot's `trigger_node_id`, even when the flow has since published a newer version. It writes:

| Run field | Trigger value |
| --- | --- |
| `started_via` | Stable driver key: `webhook`, `model`, `event`, or an extension key |
| `trigger_node_id` | Trigger node ID from the pinned activation |
| `trigger_data` | JSON-safe, source-owned value data, limited by `nodeflow.limits.trigger_data_bytes` |
| `idempotency_key` | A SHA-256 identity derived from driver, source, and the source occurrence identity |
| `engine_entry_node_id` | The one executable target of the trigger's `started` edge |

Source occurrence identities should be stable for redelivery: the webhook driver requires `Idempotency-Key`; model and Laravel-event sources may supply `occurrenceId`. The database unique key `(flow_version_id, idempotency_key)` makes the same occurrence idempotent per published version. A duplicate returns the existing run and repairs its engine dispatch if necessary; it does not rematerialize a possibly different audience.

Sources must return tenant-scoped subjects. Run creation enforces ownership centrally while it transactionally materializes the audience: `BatchTenantResolver::ownedSubjectIds()` is used for each bounded batch when the host provides it, otherwise `TenantResolver::ownsSubject()` is the safe scalar fallback. An ownership rejection rolls back the run creation. A source may fan out tenants for model/event occurrences, but each activation starts only from the audience whose tenant equals that activation's stored tenant. The webhook driver is narrower: exactly one non-empty audience for its one activation. See [Required contracts](../integration/required-contracts.md).

## Manual starts

Both manual and sub-flow starts bypass trigger matching. They still require a published graph whose start is a registered trigger and they skip it to the same executable entry node.

```php
use Nodeflow\Execution\StartRun;

$run = app(StartRun::class)->forFlow(
    $flow,
    subjectType: 'customer',
    subjectIds: ['customer-1', 'customer-2'],
    options: [
        'idempotency_key' => 'admin-import-2026-08-24',
        'correlation_id' => 'support-case-42',
        'is_test' => false,
    ],
);
```

`StartRun` selects and validates the flow's current version, writes `started_via = manual`, records the graph trigger ID, writes `trigger_data = null`, and executes the trigger's `started` target. The caller is responsible for loading the flow through tenant-scoped, authorized application code and validating the audience. The editor's policies do not authorize direct service calls.

## Sub-flow starts

The built-in `core.start_flow` executable uses `SubFlowStarter`. It loads only a child flow in the parent's tenant, validates the child's current version and trigger-first graph, and starts its executable entry. It writes `started_via = subflow`, records the child's trigger node ID, carries the parent's `trigger_data`, correlation lineage, and `is_test` flag, and enforces the current maximum depth of five.

The child trigger source is not consulted. This is intentional: the parent node already selected the audience and is the occurrence.

## Read trigger data inside a node

Both `SubjectContext` and `AudienceContext` expose the same narrow API:

```php
$all = $context->triggerData();
$orderId = $context->triggerData('order_id');
$fallback = $context->triggerData('missing', 'default');
```

`triggerData()` returns an empty array when the run has no data. A key lookup is literal and shallow; `triggerData('customer.id')` reads the key containing that dot and does not traverse nested arrays. Expression interpolation is not supported.

## Transactions and durable dispatch

Run creation, subject materialization, and dispatch intent are persisted transactionally. `engine_dispatch_status` starts as `pending`; `engine_entry_node_id` is immutable. If no outer transaction exists, Nodeflow starts the workflow immediately after its transaction. If the caller owns an outer transaction, start is deferred until the outermost commit and forgotten on rollback.

A successful engine start stores a deterministic workflow identity (`nodeflow-run:{run_id}`), the engine handle, and `engine_dispatch_status = dispatched`. A start failure records `engine_dispatch_status = failed` and the sanitized `engine_dispatch_error = Workflow dispatch failed; recovery required.`. Nodeflow queues `RetryRunDispatch`, which tries three times with 10- and 60-second backoffs. Calling `CreateRun::resume($runId)` is the public recovery primitive used by that job. Because workflow identity and entry intent are persisted, retries converge on the same run and workflow instead of creating another execution.

Webhook callers receive `503` when initial dispatch cannot be confirmed and should retry the same signed body with the same idempotency key. A recovery can then return `202 Accepted` with `duplicate: true`.

## Trigger data and idempotency limits

- `trigger_data` must be `null` or JSON-safe array data and defaults to a 65,536-byte encoded limit.
- A raw run `idempotency_key`, when supplied to the low-level run creator, must be a non-empty string of at most 255 bytes.
- Trigger matching identities are length-prefixed before hashing so driver/source/component boundaries cannot collide.
- Trigger nodes are declarative and are never executed; execution begins from persisted `engine_entry_node_id`.

See [Flows and versions](flows-and-versions.md) for publication snapshots and [Durable execution](../operations/durable-execution.md) for replay boundaries.
