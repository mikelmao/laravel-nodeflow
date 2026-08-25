# Status reference

Status columns are strings, not database enums or checked state machines. The values below distinguish what the current package actually writes from values that are only recognised by selected readers or operational tools.

## Flow status

| Value | Current writer | Meaning |
| --- | --- | --- |
| `draft` | Database default; a host may create a flow with it. | Flow has not been published by the package. |
| `active` | `PublishFlow`. | The flow has a current published version. |

No package lifecycle transition writes a flow back to `draft`, and the schema accepts other strings without package-defined meaning.

## Run status

| Value | Current writer | Current use |
| --- | --- | --- |
| `pending` | `CreateRun` (also the database default). | Run and subjects were persisted; the engine workflow has not yet loaded the graph. |
| `running` | `LoadGraphActivity`. | The engine loaded the pinned graph and recorded `started_at`. |
| `completed` | `CompleteRunActivity`. | The interpreter loop ended and recorded `ended_at`. It is terminal. |
| `failed` | `ProjectWorkflowFailure` after a terminal durable `WorkflowFailed`. | The run's durable error is recorded and `ended_at` uses the durable event timestamp. It is terminal. |
| `cancelled` | No current package writer. | Recognised as terminal for the overlay and pruning when a host writes it. |

`nodeflow_runs.error` is run-level durable failure evidence, separate from the aggregate per-node business failure counts in `nodeflow_node_executions`. `started_at` is written only when `LoadGraphActivity` changes the run to `running`; `ended_at` is written when the run becomes `completed` or when durable failure projection makes it `failed`.

The schema also allows the live states below. The package currently has no writer that moves a run to `waiting` or `blocked`, and no packaged run-level cancellation transition.

| Recognised value | Readers or tools that recognise it | Current consequence |
| --- | --- | --- |
| `waiting` | `FlowVersion::hasLiveRuns()`, `SubjectExiter` live-run check, pruning exclusion. | Considered live/recoverable; not written when a `core.wait` begins. |
| `blocked` | `FlowVersion::hasLiveRuns()`, `SubjectExiter` live-run check, pruning exclusion. | Considered live/recoverable; never automatically pruned. |
| `failed` | `ProjectWorkflowFailure`, `nodeflow:prune`, overlay. | Terminal and prunable after the retention cutoff. |
| `cancelled` | `nodeflow:prune`, overlay; documented as terminal by the subject-exit live-run check. | Terminal and prunable after the retention cutoff, but no current run-level cancellation writer sets it. |

For a matching terminal durable failure, projection updates the run and every still-active subject in one database transaction: subjects become `failed`, receive the bounded run error, and have `current_node_id` cleared. It is idempotent for an already-terminal run. Cancellation still has no packaged run-level transition, and the max-steps guard ends normally, so completion can still mark a run `completed` while subjects remain at an unprocessed node. The failure listener is queued after commit rather than an atomic outbox; queue publication failure requires operational recovery or future reconciliation.

## Engine dispatch status and run origin

`engine_dispatch_status` is distinct from run `status`:

| Value | Meaning |
| --- | --- |
| `pending` | Run, audience, exact `engine_entry_node_id`, and dispatch intent are persisted; engine start is not yet confirmed. |
| `dispatched` | `engine_workflow_id` is persisted for the deterministic workflow identity. |
| `failed` | Initial dispatch failed; `engine_dispatch_error` contains only `Workflow dispatch failed; recovery required.` and retry/recovery may resume it. |

`RetryRunDispatch` tries recovery three times with 10- and 60-second backoffs. A recovered run moves to `dispatched` without changing its audience, pinned version, trigger origin, or entry node.

`started_via` records `manual`, `subflow`, or the stable trigger driver key (`webhook`, `model`, `event`, or an extension key). `trigger_node_id` always records the published graph trigger even though trigger nodes are declarative and are never executed. `trigger_data` is the source-owned value snapshot for a trigger start, null for manual starts, and inherited from the parent for sub-flow starts.

## Run-subject status

Each `nodeflow_run_subjects` row is the current state of one unique `(run_id, subject_type, subject_id)` pair, not a visit history.

| Value | Current writer | Cursor, timestamp, and error semantics |
| --- | --- | --- |
| `active` | `AudienceMaterialiser`. | Starts at the executable target of the trigger's `started` edge. It remains active while waiting at or moving between executable nodes; `current_node_id` names its current node. |
| `completed` | `NodeRunner` when an output has no target, or when a processed subject returns no output/failure. | `current_node_id` is set to `null`. No completion timestamp is stored. |
| `failed` | `NodeRunner` when a node returns a subject failure or throws during subject execution; `ProjectWorkflowFailure` for every still-active subject after a matching terminal durable failure. | `current_node_id` is set to `null`; `last_error` stores the subject failure or the bounded run-level durable error. No failure timestamp is stored. |
| `exited` | `SubjectExiter`. | `current_node_id` is set to `null` and `exited_at` is set. |
| `waiting` | No current package writer. | The string is schema-valid but has no package-defined subject-state behavior. Active subjects at a `core.wait` remain `active`; the overlay's `waiting` count is a label for active subjects at a node, not this status. |

Terminal subject transitions made by the package clear `current_node_id`. A host-written terminal row with a cursor is ignored by the node drill-down and not counted as waiting by the overlay, but it is not repaired automatically.

## Overlay and retention

`GET` overlay responses set `terminal` with this exact predicate:

```php
$terminal = in_array($run->status, ['completed', 'failed', 'cancelled'], true);
```

The overlay's per-node `waiting` field is the count of rows with both `status = 'active'` and that node as `current_node_id`; it is independent of the run's status. It aggregates `node_executions` by node and output, reporting null-output execution rows as per-node failures. It cannot identify the individual subjects who passed through or failed at a node after their cursor has been cleared.

`nodeflow:prune` recognises only run statuses `completed`, `failed`, and `cancelled` as deletable. It selects them by `created_at` before the cutoff, explicitly deletes their subject and execution rows, then deletes each run. It does not prune `pending`, `running`, `waiting`, or `blocked`; a blocked run remains an operator decision even at any age.

## Next step

Use [Pruning and retention](../operations/pruning-and-retention.md) to schedule cleanup, and [Database schema](database-schema.md) for the rows that hold current subject state and aggregate execution data.
