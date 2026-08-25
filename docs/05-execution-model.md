# 5. Execution model

How a stored graph becomes a durable execution. Read this before you rely on the timing or the
cancellation semantics.

## The pieces

**`FlowInterpreter`** is the durable workflow. It contains only control flow — pick the next node, set a
timer, wait — and touches no database, clock or randomness. It has to: the engine replays a workflow
body from the start on every resume, feeding recorded results back into the same suspension points, so
anything non-deterministic there would produce a different execution on replay. The engine enforces
this with a boot-time scan that rejects `DB::`, `Http::` and `random_int()` in workflow classes.

**`InterpreterLoop`** is a plain generator holding the actual traversal — the cursor, wait placement,
the step guard. Extracting it from the workflow is what makes the control flow testable without a queue
or an engine.

**Activities** do everything with a side effect: load the pinned graph, run a node, complete the run.

**`NodeRunner`** is the heart. Given a run, a graph and a node id, it loads the subjects sitting at that
node, executes the node against them in chunks, merges the results, and advances each subject along the
edge matching the output it returned.

## A run, step by step

1. A trigger fires (or you call `StartRun`). One run is created per tenant, pinning `flow_version_id`.
2. The audience is materialised transactionally into `run_subjects`. Ownership is enforced centrally
   in fixed-size batches through optional `BatchTenantResolver::ownedSubjectIds()` or the safe scalar
   `TenantResolver::ownsSubject()` fallback; any rejection rolls back run creation.
3. The engine starts the interpreter with the run id. The graph is loaded through an activity, so its
   contents land in the workflow's replay history rather than being re-read.
4. The cursor begins at the start node. For each node: if it is a wait, the workflow suspends; then
   `NodeRunner` executes the node and returns the node ids that now hold subjects.
5. The cursor advances. Convergent branches dedupe.
6. When no node holds subjects, the run is marked `completed`.

A step guard (1,000 by default, `nodeflow.limits.max_steps_per_run`) bounds the loop.

## Waits and cancellation

Every wait compiles to a single primitive:

```php
self::awaitWithTimeout($duration, 'audienceEmptied');
```

It resumes on whichever comes first: the duration elapsing, or the `audienceEmptied` signal.

**The signal fires only when the run's remaining active subject count reaches zero** — never once per
departing subject. That bound is load-bearing: the engine caps pending signals at 5,000, and a
six-figure conversion wave under a per-subject scheme would blow straight through it. One signal per
wait, regardless of audience size, makes the ceiling unreachable.

It also means a per-subject run and a cohort run are the same code path. For a cohort of one, the single
subject leaving *is* the audience emptying, so the wait wakes immediately and cancellation is exact.

### Cancelling a subject

```php
use Nodeflow\Execution\SubjectExiter;

app(SubjectExiter::class)->exit($run, ['42']);
```

This marks the subjects `exited` and, if that empties the run, signals it. At the next node the exited
subject is simply not there — it receives nothing further. That is what "cancel the pending follow-up"
means in practice: not an interrupt, an absence.

Wire it from your own event listener:

```php
Event::listen(CustomerConverted::class, function ($event) {
    // Find the live runs this subject is in, then exit them.
});
```

> **Gap you will hit.** There is no packaged API for "given this subject, find their live runs".
> `SubjectExiter::exit()` requires the `Run`. You will write that query yourself — and note that
> `run_subjects` carries **no tenant scope**, so scope it explicitly by the run's tenant or you will
> reach across customers.

## Cohort-relative timing

"Wait one day" means one day after the wait step ran for the cohort — not one day after each person's
own message landed.

At a few thousand subjects that difference is seconds. **At six figures it is bounded by how long your
send takes to drain.** If a 100,000-message batch takes forty minutes, the last person's "wait 5
minutes" step fires roughly forty-five minutes after the first person's. Short waits after large sends
are a design smell; the validator warns when a wait is shorter than expected drain time is likely to be.

If you need genuine per-person anchoring — "three days after *this* customer's disbursement" — trigger a
per-subject run from an individual event instead. Same code path, exact timing.

## Publish-time validation

`PublishFlow::publish()` rejects a graph that:

- has no valid start node
- references an unregistered node type
- contains a node implementing neither cardinality interface
- has a node whose config fails its own field rules (including unparseable durations)
- has an edge pointing at a missing node, or on an output the node does not declare
- has more than one edge from the same output
- contains duplicate node ids
- contains a cycle (the error names the nodes on it)

It **warns** without blocking when two branches both contain waits — see below.

Failures throw `GraphInvalidException`, whose `errors()` returns the array so an editor can render them
per field. Nothing is written when validation fails.

## Known limitations

Be aware of these before you build on the package.

### No parallel branches

There is deliberately **no split node**. A subject occupies exactly one node at a time —
`run_subjects` has a unique constraint on `(run_id, subject_type, subject_id)` and a single
`current_node_id` — so "this person is in two places" is unrepresentable. A split node existed briefly
and silently routed every subject down only its last branch while reporting full counts on both. It was
removed rather than half-fixed. Adding fan-out needs a schema change, not an interpreter change.

### Branch waits are sequential

The interpreter processes its cursor in order, so two branches that each contain a wait elapse one
after the other rather than concurrently. Total elapsed time is the sum, not the maximum. The validator
warns at publish when it detects this shape. Concurrent branch waits need nested generators under the
engine's `all()` and are deferred.

### The interpreter has not run against a real engine

Every signature is verified against the installed engine source and the control flow is covered by
tests, but nothing in this package's suite has executed the interpreter on a real queue worker with the
real durable engine. Two API-shape corrections were already found this way during development. **Run
the canonical journey on a real worker before trusting it in production.**

### Terminal durable failures are projected

The normal lifecycle is `pending` → `running` → `completed`. A matching terminal `FlowInterpreter`
failure is projected by a queued, after-commit listener: it atomically sets the run to `failed`, stores a
bounded run-level durable error and event time, and marks every still-active subject `failed` while
clearing its cursor. This run-level error is distinct from the per-node business-failure records. The
overlay treats `completed`, `failed`, and `cancelled` as terminal, so each stops polling.

The projection job retries transient failures, but it is not an atomic outbox. If queue publication fails
after the durable transaction commits, use queue operations and a safe reconciliation process to compare
durable terminal state with Nodeflow runs. The step guard still ends normally and may record
`completed` while subjects remain at an unprocessed node; no current package service writes `blocked`
or run-level `cancelled`.

### A node that released nobody writes no execution row

`NodeRunner::advance()` writes a `nodeflow_node_executions` row per named output the node released
subjects on, plus one for failures. `NodeResult::empty()` produces neither, so a node that ran and
released nobody — `core.exit` is the common case, since it is a terminal node by definition — writes no
row at all. The run view's overlay derives "reached" from row existence (or an active subject currently
sitting on the node), so once every subject has moved past `core.exit` it shows as never reached, even
though the run genuinely executed it. The per-output counts elsewhere on the graph are unaffected; only
that one node's dimming misleads. Closing this means writing a row on the durable execution path, which
the run view deliberately does not touch — see open issue C-1's neighbour in
`docs/superpowers/open-issues.md`.

### Three models carry no tenant scope

`FlowVersion`, `RunSubject` and `NodeExecution` have no global tenant scope — they are reached through
their parents today. **Add the scope, or an explicit ownership join, before exposing any HTTP route**,
or `FlowVersion::find($request->version)` becomes a cross-tenant read.

### Other follow-ups

- Six-figure audiences require a host `BatchTenantResolver`; the scalar `ownsSubject()` fallback remains
  safe but can make one ownership lookup per subject.
- Nothing persists until every chunk of a node completes, so a crash mid-node loses the advancement
  record for subjects already processed — and there is no send-deduplication key.
- The test suite is SQLite-only. Run it against your production database engine in CI; a Postgres
  type-strictness issue would pass locally.
