# 6. Operations

Running this in production: testing journeys safely, catching breakage before it bites, and keeping the
tables from growing without bound.

## Test mode

The only safe way to validate a journey. Start a run with `is_test`:

```php
app(StartRun::class)->forFlow($flow, 'user', ['42'], ['is_test' => true]);
```

Every node then sees `$context->isTest() === true` and is expected to suppress externally visible side
effects while still returning its normal output, so the journey's routing is exercised without anyone
being messaged.

**This depends on node authors honouring it.** Nothing enforces it. A node that ignores `isTest()`
turns a dry run into a live send, so treat it as part of every node review — see the
[node checklist](03-writing-nodes.md#a-checklist-before-you-ship-a-node).

There is no audience-wide dry-run that projects counts per branch without executing anything; that is
future work. Today, test mode means a real run whose nodes choose to be quiet.

## Health checks

### `nodeflow:check-node-types`

```bash
php artisan nodeflow:check-node-types
```

Graph versions are immutable, but node **classes** are your code and ship independently. Rename or
delete a class while runs sit mid-multi-day wait on a version referencing its type, and those runs fail
at resume — potentially days later, long after the deploy that caused it.

This command checks every node type referenced by a version **with live runs** (`pending`, `running`,
`waiting`, `blocked`) and exits non-zero naming the version, node and type if any is unresolvable. Runs
that have all finished are ignored on purpose: flagging them would be noise, and noise trains people to
ignore the command.

**Use it as a deploy gate.** If it fails, either restore the class or register an alias:

```php
Nodeflow::nodes()->alias('app.old_type', 'app.new_type');
```

### The boot-time check

```php
// config/nodeflow.php
'check_node_types_on_boot' => true,
```

Runs the same check at application boot, at most once per process, logging at error level rather than
throwing — taking an application down over a data condition would be worse than the condition. Any
failure of the check itself (an unmigrated database, an unavailable connection) is caught and logged as
a warning, so enabling this cannot break boot.

Off by default. Enable it on workers and in deploy contexts where a long-lived process boots once;
leave it off where it would add a query per process for no benefit.

## Retention and pruning

Two of the tables grow with use: `run_subjects` at one row per subject per run, `node_executions` at
one row per (node, output) per run. A six-figure audience means a six-figure `run_subjects` insert.

```bash
php artisan nodeflow:prune --dry-run          # report only
php artisan nodeflow:prune                    # uses config retention window
php artisan nodeflow:prune --days=30          # override
```

Default window is `nodeflow.retention.runs_days` (90). Dry-run reports the same count the real run would
delete, computed from the same query.

**Only terminal runs are pruned** — `completed`, `failed`, `cancelled`. Specifically **not** `blocked`,
because a blocked run is recoverable once a missing node type is re-registered, and pruning it would
destroy state a fix could still resume. The consequence: a run stuck `blocked` forever is never pruned
at any age. Clearing genuinely abandoned blocked runs is a deliberate operator decision, not something
the command will do for you.

A run's `run_subjects` and `node_executions` rows are deleted explicitly along with it, so this does not
depend on database cascade behaviour.

Schedule it:

```php
// bootstrap/app.php or a scheduler file
Schedule::command('nodeflow:prune')->daily();
```

### The engine's own tables

The durable engine keeps its own tables (workflow instances, runs, history events, activity attempts)
and **this command does not touch them.** The engine ships no end-to-end prune of its own either —
archive and prune are separate concerns there. At any real volume you need your own retention job over
those tables, in dependency order: history events, tasks and attempts first, then runs and instances.
Budget for it before you are at six figures per alert.

## Status lifecycles

### `runs.status`

| Status | Meaning |
|---|---|
| `pending` | created, engine workflow not yet started |
| `running` | interpreter is executing |
| `waiting` | intended for a run inside a wait |
| `completed` | terminal, prunable |
| `failed` | terminal, prunable |
| `cancelled` | terminal, prunable |
| `blocked` | recoverable — a node type could not be resolved. Never pruned. |

**Be aware:** only `running` and `completed` are actually written today. An activity exception leaves a
run `running`, and a run exhausting the step guard is recorded `completed`. Do not build alerting that
assumes `failed` appears — see [Execution model](05-execution-model.md#runs-do-not-reach-a-failure-state).

### `run_subjects.status`

| Status | Meaning |
|---|---|
| `active` | in the journey, sitting at `current_node_id` |
| `completed` | left the journey successfully (reached a node with no onward edge, or a terminal node) |
| `failed` | a node failed for this subject; `last_error` holds why |
| `exited` | removed mid-journey, usually by cancellation. `exited_at` is set. |

A finished run should have **no** `active` subjects. If you see one, something advanced incorrectly —
that invariant is asserted in the test suite and is the clearest signal of a routing bug.

## Observability

There is no dashboard. What you have:

**`node_executions`** — one row per (run, node, output) with a subject count, duration, and an error
summary. Deliberately not one row per subject: at 100k subjects across six nodes that is roughly 18
rows instead of 600,000. This is what a run-view overlay would read to show "2,940 sent, 46 failed,
1,204 waiting".

**`run_subjects`** — per-subject current node, status and `last_error`. This answers "why didn't this
person get the message?" without storing a full per-subject history.

Your messaging system remains the authoritative record of what was actually delivered. nodeflow records
what it *decided*, not what a provider did with it.

## Failure modes worth knowing

| Symptom | Likely cause |
|---|---|
| Runs created, nothing happens | No queue worker, or the queue driver is `sync` |
| Audience is always empty | `TenantResolver::ownsSubject()` returning false — the shipped default does |
| `SubjectResolver` throws about binding | You have not bound your own implementation |
| A trigger's event fires and no run appears | Trigger registered somewhere that never executed; register in `boot()` |
| Subject stuck `active` on a completed run | A routing bug; check `current_node_id` against the graph's edges |
| A wait fires immediately | A duration that parses to zero — now rejected at publish, but check older versions |
