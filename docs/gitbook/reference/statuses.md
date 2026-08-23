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
| `pending` | `StartRun` (also the database default). | Run and subjects were persisted; the engine workflow has not yet loaded the graph. |
| `running` | `LoadGraphActivity`. | The engine loaded the pinned graph and recorded `started_at`. |
| `completed` | `CompleteRunActivity`. | The interpreter loop ended and recorded `ended_at`. This is the only status the overlay currently treats as terminal. |

`nodeflow_runs.error` is nullable, but the current package has no writer for it: run-level failures do not populate the column. `started_at` is written only when `LoadGraphActivity` changes the run to `running`; `ended_at` is written only when `CompleteRunActivity` changes it to `completed`.

The schema allows every value in the next table, but the package currently has no writer that moves a run to `waiting`, `blocked`, `failed`, or `cancelled`.

| Recognised value | Readers or tools that recognise it | Current consequence |
| --- | --- | --- |
| `waiting` | `FlowVersion::hasLiveRuns()`, `SubjectExiter` live-run check, pruning exclusion. | Considered live/recoverable; not written when a `core.wait` begins. |
| `blocked` | `FlowVersion::hasLiveRuns()`, `SubjectExiter` live-run check, pruning exclusion. | Considered live/recoverable; never automatically pruned. |
| `failed` | `nodeflow:prune`. | Prunable after the retention cutoff, but no current run-level failure writer sets it. |
| `cancelled` | `nodeflow:prune`; documented as terminal by the subject-exit live-run check. | Prunable after the retention cutoff, but no current run-level cancellation writer sets it. |

There is no lifecycle reconciler between the durable engine and `nodeflow_runs`. An engine activity failure, an engine cancellation, or an interpreter stop at `nodeflow.limits.max_steps_per_run` can therefore leave a run as `pending` or `running` (or leave active subjects) rather than writing a terminal failure status. The max-steps guard ends the loop normally, so the completion activity can mark the run `completed` while subjects remain at an unprocessed node.

## Run-subject status

Each `nodeflow_run_subjects` row is the current state of one unique `(run_id, subject_type, subject_id)` pair, not a visit history.

| Value | Current writer | Cursor, timestamp, and error semantics |
| --- | --- | --- |
| `active` | `AudienceMaterialiser`. | Starts at the graph start node. It remains active while waiting at or moving between nodes; `current_node_id` names its current node. |
| `completed` | `NodeRunner` when an output has no target, or when a processed subject returns no output/failure. | `current_node_id` is set to `null`. No completion timestamp is stored. |
| `failed` | `NodeRunner` when a node returns a subject failure or throws during subject execution. | `current_node_id` is set to `null`; `last_error` stores the failure message. No failure timestamp is stored. |
| `exited` | `SubjectExiter`. | `current_node_id` is set to `null` and `exited_at` is set. |
| `waiting` | No current package writer. | The string is schema-valid but has no package-defined subject-state behavior. Active subjects at a `core.wait` remain `active`; the overlay's `waiting` count is a label for active subjects at a node, not this status. |

Terminal subject transitions made by the package clear `current_node_id`. A host-written terminal row with a cursor is ignored by the node drill-down and not counted as waiting by the overlay, but it is not repaired automatically.

## Overlay and retention

`GET` overlay responses set `terminal` with this exact predicate:

```php
$terminal = $run->status === 'completed';
```

They do not treat `failed` or `cancelled` as terminal today. The overlay's per-node `waiting` field is the count of rows with both `status = 'active'` and that node as `current_node_id`; it is independent of the run's status. It aggregates `node_executions` by node and output, reporting null-output execution rows as per-node failures. It cannot identify the individual subjects who passed through or failed at a node after their cursor has been cleared.

`nodeflow:prune` recognises only run statuses `completed`, `failed`, and `cancelled` as deletable. It selects them by `created_at` before the cutoff, explicitly deletes their subject and execution rows, then deletes each run. It does not prune `pending`, `running`, `waiting`, or `blocked`; a blocked run remains an operator decision even at any age.

## Next step

Use [Pruning and retention](../operations/pruning-and-retention.md) to schedule cleanup, and [Database schema](database-schema.md) for the rows that hold current subject state and aggregate execution data.
