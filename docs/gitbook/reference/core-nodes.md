# Core nodes reference

Nodeflow registers these four nodes automatically. Their stable `core.*` types are reserved; do not reuse or rename them in published graphs.

| Type | Label / group | Cardinality | Outputs |
| --- | --- | --- | --- |
| `core.wait` | Wait / Flow | audience | `default` |
| `core.condition` | Condition / Flow | subject | `yes`, `no` |
| `core.start_flow` | Start Another Flow / Flow | audience | `default` |
| `core.exit` | Exit / Flow | audience | none |

## `core.wait`

**Outcome:** pauses the active audience, then sends the subjects still active to `default`.

| Field | Field type | Rules | Default |
| --- | --- | --- | --- |
| `duration` | `duration` | `required`, `string`, `ValidDuration` | none |

`duration` accepts a relative duration such as `5 minutes`, `1 day`, or `2 weeks`. The interpreter waits before it runs the node body, using the configured duration; when the timer elapses, `WaitNode` returns every still-active subject on `default`.

The wait races its timer with the `audienceEmptied` signal. `SubjectExiter` signals only when the last active subject leaves a live run, so an entirely exited audience wakes early and nobody continues. The node is audience-based: each `NodeRunner` invocation receives a batch of up to `nodeflow.limits.audience_chunk` active subjects. A wait is still one interpreter wait per graph node, not one delay per batch.

Branch waits are currently sequential in the interpreter: two reachable branch waits do not overlap, so their durations can add.

## `core.condition`

**Outcome:** resolves one registered subject attribute and routes that subject to `yes` or `no`.

| Field | Field type | Rules | Default |
| --- | --- | --- | --- |
| `attribute` | `select` with dynamic options from `SubjectAttributeRegistry` | `required`, `string` | none |
| `operator` | `select` | `required`, `string`, `in:is_true,is_false,equals,not_equals,in,greater_than,less_than` | none |
| `value` | `text` | `nullable`, `string` | none |

The available operators are:

| Key | Editor label | Comparison |
| --- | --- | --- |
| `is_true` | is true | `(bool) $actual === true` |
| `is_false` | is false | `(bool) $actual === false` |
| `equals` | equals | Type-aware equality. |
| `not_equals` | does not equal | Negation of type-aware equality. |
| `in` | is one of | Any type-aware equality against the comma-separated `value` list. |
| `greater_than` | is greater than | Numeric, strict greater-than. |
| `less_than` | is less than | Numeric, strict less-than. |

For a registered attribute of type `boolean`, equality uses `filter_var(..., FILTER_VALIDATE_BOOL)` on both sides. For type `number`, both values must be numeric and are compared as floats. Every other type, including an unrecognised registered type, compares the string casts. `in` splits a string expected value on commas and trims each item; an array value is used as-is. Greater-than and less-than ignore the registered attribute type and require both operands to be numeric.

A `null` actual value never matches `equals`, `in`, `greater_than`, or `less_than`; therefore it matches `not_equals`. For the boolean operators it is falsey, so it routes `is_false` to `yes` and `is_true` to `no`. An unknown operator throws at execution. An unknown attribute key also throws when the registry resolves its value; it does not silently use the text fallback. Use [Subject attributes](../building-automations/subject-attributes.md) to register attributes before publishing a condition.

## `core.start_flow`

**Outcome:** starts a child run for the current audience and, by default, completes those subjects in the parent flow.

| Field | Field type | Rules | Default |
| --- | --- | --- | --- |
| `flow_id` | `select` | `required`, `string` | none |
| `exit_this_flow` | `boolean` | `nullable`, `boolean` | `true` |

`flow_id` is cast to `int`. The destination flow must exist for the parent run's tenant; otherwise the node fails. The child receives the current subject type and every subject in the current audience batch. Because this is an audience node, a large parent audience is processed in `nodeflow.limits.audience_chunk` batches, and each batch starts a separate child run.

The child copies `is_test` and appends the parent run ID to `correlation_id`, using `>` as the lineage separator. A lineage with five existing non-empty segments does not start another child: the starter returns `null`, and this node still follows its normal parent-flow result. The destination uses normal `StartRun` behavior, including its current published version and subject tenancy checks.

No idempotency key is passed for this node. Re-executing a batch can therefore create another child run; make the surrounding workflow and any child actions safe to retry. With the default `exit_this_flow: true`, the node returns `NodeResult::empty()`, so the batch's parent subjects complete and receive no `default` edge. Set it to `false` to keep them in the parent and route them through `default` after starting the child.

## `core.exit`

**Outcome:** completes every subject that reaches it, with no outgoing edge.

It has no fields, no field defaults, and no outputs. It returns an empty result for its audience batch. The runtime treats each active subject that the node processed but did not name in an output or failure as completed and clears its cursor. It is an executed terminal node, not a graph marker: do not expect its arrival to leave subjects active for later work.

## Next step

Read [Writing nodes](../building-automations/writing-nodes.md) for custom node contracts and [Publishing flows](../building-automations/publishing-flows.md) before using a core node in an active flow.
