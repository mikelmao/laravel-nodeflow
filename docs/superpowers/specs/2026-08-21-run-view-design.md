# The run view — Design

**Date:** 2026-08-21
**Status:** approved section by section, pending implementation plan
**Extends:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` (the editor spec)
**Extends:** `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` (the foundation spec)

This document is Plan 4 of the editor spec's six, promoted from that document's §6 outline into an
implementable design. §6 is an architectural sketch written before the engine's recording behaviour
was read closely; three of its claims do not survive contact with the code, and this document
corrects them by numbered decision rather than by quietly implementing something else.

Decisions continue the editor spec's **E** series at **E13**, so the foundation spec's **D1–D14**
still need no renumbering. Where an E decision here corrects earlier prose it says which and why.

---

## 1. What the code says that §6 did not

Four findings from reading the engine before designing anything. Each one changes the design.

**A node that ran and released nobody writes no row at all.** `NodeRunner::advance()` inserts
`node_executions` rows by iterating `$result->outputs()` and `$result->failures()`.
`NodeResult::empty()` has both empty, so `core.exit` (`ExitNode::forAudience()` returns
`NodeResult::empty()`) and `core.start_flow` on its `exit_this_flow` path insert nothing;
`reconcileDepartures()` updates subject rows only. §6's "A node with no `node_executions` row was
never touched" is therefore false for the package's own terminal node. Both demo flows end in
`core.exit`, so a naive implementation dims the node every subject passed through.

**The node holding the audience mid-wait has no row either.** `InterpreterLoop::steps()` yields
`WaitStep` *before* the `RunNodeStep` for the same node, so while a run hibernates its subjects sit
at the wait node with `current_node_id` set and `status = 'active'`, and nothing has been recorded
against that node yet. "100k waiting at the 24-hour timer" is the headline state the run view exists
to show, and row-existence alone renders it inert.

**Only active subjects carry a node.** Every terminal transition — `advance()`,
`reconcileDepartures()`, `SubjectExiter::exit()` — writes `current_node_id = null` alongside the new
status. There is no per-subject visit history anywhere; `node_executions` holds aggregate counts
only. So `nodeflow_run_subjects` can answer "who is at this node now" and nothing else.

**The package client imports no Inertia.** `grep -rn "@inertiajs" resources/js/` returns nothing, by
design: `http.ts` states it has no axios or Inertia dependency, and E4's `urls` prop exists so
components consume server-authored endpoints without knowing route names or the page framework.
§6's "interval partial reload of the overlay prop" would require importing Inertia's router into the
package for the first time, and would leave the `runs/{run}/overlay` route it also specifies unused.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| E13 | **`reached` is `(an execution row exists for the node) OR (an active subject sits at the node)`.** This corrects §6's claim that a node with no `node_executions` row was never touched | Row existence alone dims the node holding the whole audience mid-wait, which is the state the view exists to show. Both disjuncts are still existence, never a released-subject count, so the collapse `reached = total > 0` — the one the mandated fixture kills — remains impossible. The residue is honest and documented: a node that ran, released nobody and is now empty reads as never reached. Closing that needs a row written on the durable execution path, which is deliberately not Plan 4's territory |
| E14 | **`GET runs/{run}/overlay` is a plain JSON endpoint**, polled on an interval through the package's own `send()` helper against a server-authored `urls.overlay`. `GET runs/{run}` embeds the identical snapshot as its initial `overlay` prop. This resolves §6's "partial reload of the overlay prop" | Keeps the package free of an Inertia dependency, which E4 and §5.6 both rest on; keeps the specified overlay route alive rather than dead; makes polling unit-testable with a mocked `fetch` exactly as `useFieldOptions` already is; and gives one payload shape two transports, so first paint costs no request |
| E15 | **The drill-down answers "who is here now".** `runs/{run}/nodes/{node}/subjects` lists active subjects at the node, cursor-paginated. Per-node **failures are countable but not listable**. The `nodeflow_run_subjects` index gains `id` as a fourth column | The schema permits nothing else: terminal statuses null `current_node_id`, and no visit history exists. Cursor pagination rather than offset is what makes six figures survivable; the fourth index column is what makes the ordered read a range scan rather than a filesort on Postgres and SQLite, where a secondary index does not implicitly carry the primary key. Folded into the existing migration, as §5.1 did, because nothing is installed anywhere |
| E16 | **Run decoration reaches the shared `NodeCard` as an additive data prop**, `nodeDecorations: Record<string, NodeDecoration>` carried on the existing canvas context. Not a type-keyed renderer map, and not a forked card | `renderers` is keyed by node *type* while the overlay is keyed by node *id*, so the renderer route must enumerate every type — leaving a pinned node whose type is no longer registered, the node most in need of a badge, without one — and it consumes the host's own `nodeRenderers` slot, so a host could never theme a run card. Plain data also keeps run vocabulary out of `canvas/` and lets Vitest assert data and DOM independently |
| E17 | **`terminal` is computed server-side from `['completed']` alone** and travels in both the initial prop and every polled response | C-1's caveat then lives in exactly one place instead of being hardcoded in the client, so the day a durable failure state exists the client needs no change. Plan 4 invents no run state |
| E18 | **The request-context rule becomes "request-context code never names these tables"**, matched over comment-stripped source. This resolves **G-1** | An ever-growing list of `table|from|join|leftJoin|joinSub…` is a list you eventually lose, and it still misses `DB::select('… from nodeflow_run_subjects')`. One bright line catches every G-1 form, raw SQL and subquery closures at once. Stripping comments first is what lets `GraphValidator`'s useful comment about the table's unique constraint survive the rule |

---

## 3. The server contract

### 3.1 Routes

Appended to `src/Http/routes.php`, names following the existing `nodeflow.flows.*` convention.

| Method | URI | Name | Gate |
|---|---|---|---|
| `GET` | `runs/{run}` | `nodeflow.runs.show` | `viewAny` |
| `GET` | `runs/{run}/overlay` | `nodeflow.runs.overlay` | `viewAny` |
| `GET` | `runs/{run}/nodes/{node}/subjects` | `nodeflow.runs.subjects` | `viewAny` |

`{run}` binds through the tenant-scoped `Run`, so a cross-tenant id is a 404 before any controller
code runs — a 403 would confirm the row exists. All three call `$this->authorize('view', $run)`,
which `RunPolicy::view` delegates to the host's `nodeflow.viewAny` gate with the `Run` passed, so a
host can decide per run without a fifth gate no host knows to define.

`FlowEditorController::routeName()` moves to a shared `Nodeflow\Http\ResolvesRouteNames` trait
parameterised by the caller's own route name, rather than being copied into a second controller.

### 3.2 No controller queries the untenanted tables

Two readers, mirroring `src/Editor/SaveDraft.php`: `src/Runs/RunOverlay.php` and
`src/Runs/RunSubjects.php`. Both take a `Run` and reach data only through `$run->nodeExecutions()`
and `$run->subjects()`. Neither names those models statically nor their tables, so both pass
`RequestContextScanner` with **no allowlist entry** — which is the proof that E1's structural
invariant holds here, rather than an exemption asserting it.

### 3.3 Overlay aggregation

Two grouped queries, both through the scoped `Run`, both covered by existing indexes
(`['run_id','node_id']` and `['run_id','current_node_id','status']`):

```php
$run->nodeExecutions()
    ->selectRaw('node_id, output, SUM(subject_count) as subjects, MAX(error) as error')
    ->groupBy('node_id', 'output')

$run->subjects()
    ->selectRaw('current_node_id, status, COUNT(*) as subjects')
    ->whereNotNull('current_node_id')
    ->groupBy('current_node_id', 'status')
```

The only raw SQL is a constant column-and-aggregate list. No request value is interpolated into
either; `{node}` is bound as a parameter where it is used.

`MAX(error)` keeps this to two queries at the cost of one *representative* message when a node
failed on two separate visits — reachable in a diamond graph, since `GraphValidator` forbids cycles
but not convergence. Documented rather than silent.

### 3.4 Assembly

One entry per node id **in the pinned graph**, so "never reached" is server-authored and the client
never gap-fills. Rows naming a node id absent from the graph are ignored.

| Field | Source |
|---|---|
| `byOutput` | non-null-`output` rows, `{output => (int) sum}` |
| `failed` | sum of the `output IS NULL` rows — the failure bucket `advance()` writes |
| `error` | `MAX(error)` over those rows, else `null` |
| `waiting` | the `status = 'active'` bucket of query two |
| `reached` | `executionRowExists \|\| waiting > 0` (E13) |

`reached` is not simplifiable to a count of subjects released, and that is the whole point. A node
with one row summing to zero and nobody waiting returns `reached: true, byOutput: {sent: 0}`; a node
with no row and nobody waiting returns `reached: false, byOutput: {}`. The naive
`reached = total > 0` collapses those two, and §7's mandated fixture exists to fail it.

`waiting` counts only `active`. Grouping by `status` anyway is what lets a test assert that a
non-active row is not counted as waiting, instead of the query's shape making it accidentally true.

### 3.5 Response shapes

The polled endpoint and the initial prop carry the identical envelope:

```ts
{ status: string, terminal: boolean, nodes: Record<string, NodeOverlay> }

type NodeOverlay = {
  reached: boolean
  byOutput: Record<string, number>
  waiting: number
  failed: number
  error: string | null
}
```

`GET runs/{run}` renders the `nodeflow/run` Inertia component with `run` (id, status, terminal,
strategy, `is_test`, `started_at`, `ended_at`, `error`, version, flow id and name), `graph` —
`$run->flowVersion->graph`, never `draft_graph` and never `flow->currentVersion` — `palette`,
`overlay`, and `urls: { overlay, subjects }`, where `subjects` carries a `__NODEFLOW_NODE__`
sentinel under E4's established contract, including failing by name when a sentinel is absent.

The overlay endpoint returns the envelope **alone**: no graph, no palette. A test asserts the
absence of those keys, because an endpoint that quietly grew a graph payload would make polling cost
a full page render per interval without any test noticing.

**Two encoding details, written down rather than discovered.** `nodes` and `byOutput` are both cast
to `(object)` before encoding: PHP coerces numeric-string array keys to integers, so a graph whose
node ids are `"0"`, `"1"`, `"2"` — or an output literally named `"1"` — would otherwise encode as a
JSON **array** and break the client's keyed lookup. This is the precedent `FieldOptionsController`
set with its own `(object)` cast. And eager-loading `flowVersion.flow` is correct here even though
`CheckNodeTypesResolver` deliberately avoids it: that path runs in a console context with no ambient
tenant, where re-applying `Flow`'s scope throws, whereas a run-view request has a resolved tenant —
so the scope resolves normally and is a welcome second check that the version's flow is in this
tenant.

### 3.6 Subject drill-down

```php
$run->subjects()
    ->where('current_node_id', $node)
    ->where('status', 'active')
    ->orderBy('id')
    ->cursorPaginate(config('nodeflow.limits.subject_page', 50));
```

`{node}` is validated against the pinned graph's node ids first; an unknown id is a 404, mirroring
`FieldOptionsController`'s treatment of an unknown node type. It is a **graph** key, not a record
key, and it is never used as a foreign key.

The `status = 'active'` predicate is both the ratified semantics and what turns the extended
`['run_id','current_node_id','status','id']` index into an ordered range scan. The response carries
`node`, a `data` array of `{ id, subject_type, subject_id, status, current_node_id, last_error,
exited_at }`, and `next_cursor`.

`subject_page` is declared in `config/nodeflow.php`'s existing `limits` array beside
`subject_chunk` and `audience_chunk`, rather than living only as a `config()` call's second
argument — a limit a host cannot find in the published config is a limit a host cannot change.

---

## 4. The client

### 4.1 Layout

New files, in the `run/` folder §5.5 reserved:

```
run/FlowRun.tsx          the assembled view
run/useOverlayPolling.ts the interval hook
run/overlay.ts           pure: normalize snapshot, derive decorations   ← the Vitest target
run/SubjectPanel.tsx     the drill-down
```

Touched: `canvas/context.ts`, `canvas/Canvas.tsx` and `canvas/NodeCard.tsx` gain E16's optional
seam; `http.ts` gains a generic sentinel substitutor; `graph/types.ts` gains the run wire types;
`index.ts` exports `FlowRun` and drops its "belongs to Plan 4" comment.

### 4.2 `run/overlay.ts`

`normalizeOverlay()` rebuilds the server's `nodes` and `byOutput` maps as `Object.create(null)`
objects — the idiom `graph/toGraph.ts:defsByType` already uses — and every read goes through
`Object.prototype.hasOwnProperty.call`, as `NodeCard` and `rendererFor` already do. Node ids come
from a stored graph, so `constructor`, `toString` and `__proto__` are attacker-influenceable record
keys and must not resolve inherited entries. It validates the payload before anything reaches state,
as `validatedOptions` does: a non-object `nodes` or a non-boolean `terminal` is a named error, not a
silently wrong render.

`decorationsFor()` is the single place the two visual states are decided:

- `reached: false` → `{ dimmed: true, badges: [] }`. Dimmed, **no badge**.
- `reached: true` → `dimmed: false`; one badge per `byOutput` entry, plus `waiting` and `failed` when
  non-zero; and if that list would be empty, one explicit `0` badge, so a reached node always shows
  a number and never renders inert.

Pure functions over plain data, so the never-reached/reached-zero distinction is asserted at the
data level and at the DOM level, and neither assertion can pass while the other's bug is live.

### 4.3 `run/useOverlayPolling.ts`

Returns `{ snapshot, error }`. Three specifics, each a trap this codebase has already paid for:

- **Strict Mode replays effect setup.** Timer and liveness handles live on refs that setup
  re-establishes, following `useFieldOptions`'s `inFlightRequest` pattern, so a replay neither leaves
  a dead ref nor doubles the polling rate.
- **The stop condition is read inside the functional updater**, not from the render closure.
  `setSnapshot(prev => …)` decides terminality and clears the interval from updater-observed state; a
  same-render transition validated against a stale render ref is exactly the defect Plan 3 paid for.
- **It never starts when the initial snapshot is already terminal**, so a completed run costs zero
  requests.

Failure policy, stated because silence is a bug either way: stop on 401/403/404/419 — the run is
gone or the gate was revoked, and retrying forever is noise — and keep polling on 5xx and network
failures while surfacing the last error. The error is visible; polling never silently freezes a
stale overlay.

### 4.4 `run/FlowRun.tsx`

Props: `{ run, graph, palette, overlay, urls, nodeRenderers?, pollIntervalMs?, className? }`.

It imports only `canvas/Canvas`, `graph/toCanvas`, `graph/toGraph`'s `defsByType`, `http`, and its
own `run/` siblings. **Nothing from `editor/`.** There is no `controls` prop and no
`FieldOptionsContext`: the absence of a config-editing surface is structural, not a runtime decision.
That is the guarantee behind E7, and §7 enforces it with a test rather than a convention.

`interactive={false}` routes through the `canvasBehavior()` / `readOnlyNodes()` / `readOnlyEdges()`
machinery Plan 3 shipped for this view, which is what keeps stripping transient `selected` and
`dragging` state; it is asserted, not assumed. Per-node overlay `error` strings are handed to
`Canvas` as `nodeErrors`, so they render through the mandatory error list `NodeCard` already owns
instead of a second error mechanism. The component remounts on a `sessionKey` of
`[run.id, urls.overlay]`, mirroring `FlowEditor`'s session identity, so switching runs cannot leak a
previous run's polling or panel state.

### 4.5 `run/SubjectPanel.tsx`

Opens on `onNodeClick`, substitutes and URI-encodes the node id into `urls.subjects`, and pages with
`next_cursor` behind a "Load more". It distinguishes three states in words, not only visually:
loading, error, and *"no subjects are here now"* — a different sentence from a never-reached node,
because a node can be reached and now empty.

### 4.6 `http.ts`

The sentinel logic becomes `substituteSentinels(template, replacements)`, throwing by name when any
sentinel is absent. `optionsUrl` delegates to it, so its existing tests pass unchanged, and
`subjectsUrl` becomes the second caller rather than a second copy.

---

## 5. Security

**G-1 is resolved (E18), not carried.** `RequestContextScanner` gains comment stripping via
`token_get_all()` dropping `T_COMMENT` and `T_DOC_COMMENT`, and its table rule becomes the table
name appearing anywhere in code. That catches `DB::table('nodeflow_run_subjects as rs')`,
`->join(...)`, `->from(...)`, raw `DB::select` SQL and subquery closures in one rule. The cost is two
legitimate code sites — `src/Models/RunSubject.php` and `src/Models/NodeExecution.php` declaring
`protected $table` — which get path allowlist entries. That is not a hole: a scope method added to
either model still has to be *called* somewhere, and the `Model::` rule catches the call site.

**G-3 is honoured by navigation and tested.** Every read goes through the authorized, scoped `Run`.
No foreign key is accepted from input. The sharp test: request a node id that is valid in **another
run's** graph and assert 404 — a raw-key-equivalence bug returns 200 with that other node's
subjects.

**Not touched, restated so the plan cannot absorb them.** D-1's tenancy-inference diagnostic and
D-2's `RunNodeActivity` tenant assertion remain in the post-Plan-3 security-hardening plan; Plan 4
adds nothing to the durable execution path, which is also why E13 chose the reader-side derivation.
G-4's Vite `dedupe` verification remains Plan 5's installer work; Plan 4 relies on the setting the
demo already carries. C-1 is preserved exactly: E17 derives `terminal` from `['completed']` and no
failure state is invented.

---

## 6. Error handling

| Condition | Response |
|---|---|
| Cross-tenant `{run}` id | `404`, not `403`, on all three routes |
| Undefined authorization gate | `403` on all three routes |
| `{node}` absent from the pinned graph | `404` |
| `{node}` valid in another run's graph | `404` |
| Node reached, released nobody, now empty | `reached: false`. A documented limitation of E13, recorded beside C-1 |
| Overlay poll returns 401/403/404/419 | Stop polling, surface the error |
| Overlay poll returns 5xx or fails | Keep polling, surface the last error |
| Malformed overlay payload | Named client error; never a silently wrong render |
| Missing URL sentinel | Throw by name, as E4 established |
| Pinned node type no longer registered | `NodeCard`'s existing loud unregistered-type message |

---

## 7. Testing

**The discipline, unchanged from the editor spec §9:** for every test, name the production change
that would make it fail.

**PHP — 30 new Pest tests, `325 → 355`.**

| File | Tests | The sharp ones |
|---|---|---|
| `tests/Feature/RunViewTest.php` | 6 | The pinned version, twice, because two different wrong implementations exist: a **draft-only** node is absent (reads `draft_graph`) and a **newer published version's** node is absent (reads `flow->currentVersion`). Both fixtures make the graphs genuinely differ; a same-graph fixture passes vacuously. Plus 403-no-gates, 404-cross-tenant, sentinel-intact urls, one overlay entry per graph node |
| `tests/Feature/RunOverlayTest.php` | 11 | **One fixture containing a never-reached node and a node reached with zero subjects**, asserting they differ in `reached` rather than both rendering 0; the test is named for the counterfactual it kills. Plus per-output summing across two visits, waiting counted only for `active`, failures and representative error from the `output IS NULL` rows, stale node ids ignored, numeric node ids and output names encoding as JSON objects, the endpoint returning the envelope alone, `terminal` true only for `completed`, and **exactly two queries** for the aggregation via `DB::listen` |
| `tests/Feature/RunSubjectsTest.php` | 7 | Cursor paging across two pages; non-active subjects excluded; unknown node 404; **a node id valid in another run's graph → 404**; 403-no-gates; 404-cross-tenant |
| `tests/Unit/ArchitectureTest.php` | +1 | Scans `src/Http/` and `src/Runs/` with an **empty** allowlist, so neither tree can be exempted the way the global case's allowlist could grow |
| `tests/Unit/RequestContextScannerTest.php` | +5 | The four G-1 forms — alias, join, from, raw SQL — plus the over-matching guard: a comment or docblock naming the model or table is not a violation |

**Vitest — 25 new tests, `123 → 148`.**

- `run/overlay.test.ts` (7) — prototype-key node ids not resolving inherited entries, malformed
  payload named, and the dim/badge rules at data level.
- `run/useOverlayPolling.test.tsx` (7) — interval polling; terminal stop; zero requests when the
  initial snapshot is terminal; Strict Mode replay not doubling the rate; terminality decided from
  functional-updater state; stop on 403/404; keep-and-surface on 500.
- `run/FlowRun.test.tsx` (7) — read-only mount; transient state stripped; **never-reached versus
  reached-zero at DOM level**; overlay errors through `NodeCard`'s list; drill-down paging;
  "no subjects here now" distinguished from never reached; a host `nodeRenderers` override keeping
  handles and errors.
- `run/boundary.test.ts` (1) — no file under `run/` imports from `editor/`. Mutation proof: add
  `import { useAutosave } from '../editor/useAutosave'` to `FlowRun.tsx` and it fails, naming the file.
- `canvas/canvas.test.tsx` (+2) — decorations applied; the prop's absence leaves the editor path
  unchanged.
- `index.test.ts` (+1) — `FlowRun` and the run types are exported.

**Demo — 5 new Pest tests, `44 → 49`**, in `tests/Feature/NodeflowRunViewTest.php`: resolved urls
and pinned version; a diverging draft's node absent; overlay from a really executed run; drill-down
listing; cross-tenant 404 through the session tenant resolver.

**Mutation proofs required by the plan, named now so weaker ones cannot be substituted:**

| Mutation | Must fail |
|---|---|
| `DB::table('nodeflow_run_subjects as rs')` in a scanned file | scanner unit test |
| `->join('nodeflow_run_subjects', …)` | scanner unit test |
| `->from('nodeflow_run_subjects')` | scanner unit test |
| `DB::select('… from nodeflow_node_executions …')` | scanner unit test |
| A comment or docblock naming the model or table | must **not** be a violation |
| `RunOverlay` rewritten to `NodeExecution::where('run_id', $run->id)` | `ArchitectureTest` |
| `RunSubjects` rewritten to `->from('nodeflow_run_subjects as rs')` | `ArchitectureTest` |
| `reached` implemented as `total > 0` | `RunOverlayTest`'s two-kind fixture |
| The run view reading `draft_graph` or `flow->currentVersion` | the two `RunViewTest` pinning tests |
| `FlowRun` importing from `editor/` | `run/boundary.test.ts` |

**One honesty note on arithmetic.** Test counts above are contractual, per task. Assertion totals
are not predictable before the assertions exist, so the plan records the measured assertion count at
each task boundary and requires it to increase strictly, rather than inventing a number now and
later bending tests to hit it.

---

## 8. Demo integration

Two additions the browser acceptance criteria make necessary rather than optional.

**A partitioning audience node.** The demo cannot currently produce a zero-count execution row:
`core.condition` is per-subject so it only writes outputs someone took, `SendMessage` returns
`$context->all('sent')`, and `core.exit` writes nothing. A genuine reached-zero needs a host
**audience** node that partitions with an empty branch, so the demo gains a small `demo.segment`
node with `matched` / `unmatched` outputs, seeded so one organisation's users all match. That
produces a real `unmatched` row with `subject_count = 0` beside genuinely never-reached
`failed`-branch nodes — and exercises the host-audience-node path while it is there.

**A small page size.** The demo sets `nodeflow.limits.subject_page` to 2 so "Load more" is exercised
for real against its four seeded users. The six-figure claim itself rests on cursor pagination and
the extended index, proven by the query-shape test rather than by browser scale.

Plus the thin page `resources/js/pages/nodeflow/run.tsx` and a link to it from the demo page.

**The worktree trap, recorded because it fails silently.**
`~/Sites/test-workflow/vendor/atram/laravel-nodeflow` is an **absolute** symlink to
`~/Projects/laravel-nodeflow`. A demo worktree would therefore compile and test the *old* package
while appearing to pass. The plan repoints the demo worktree's symlink at the package worktree,
asserts the resolved path before running any demo gate, and restores and re-verifies it after both
branches merge to `main`.

---

## 9. Browser acceptance

At `http://test-workflow.test/`, after both merges, against the real demo:

1. A pinned-version-versus-draft difference visible on the run view.
2. The overlay refreshing without a manual reload, with repeated `runs/{id}/overlay` requests in the
   network panel.
3. Reached-zero rendering an explicit `0` beside a dimmed, badgeless never-reached node.
4. Subject drill-down paging through "Load more".
5. Polling ceasing once the run reaches `completed`.
6. No "Invalid hook call" — this mounts a second package component in the host for the first time,
   so it is also a live check that the `dedupe` wiring still holds.

Keyboard activation where the browser's password-manager overlay intercepts clicks, as the previous
acceptance run required.

---

## 10. Scope

### In scope

The three routes, the two readers, the overlay contract, the subject drill-down, `FlowRun` and its
three supporting modules, E16's canvas seam, E18's scanner fix, the demo integration of §8, and the
documentation of §11.

### Out of scope

- Any write path on the run view. It renders; it changes nothing.
- Broadcasting for live overlays (E8).
- A durable failure state for `runs.status` (C-1).
- A per-subject visit-history table, which is what listing per-node failures or "who passed through"
  would require. Schema and write-path change on the six-figure tables; Plan 5 or later.
- A run-wide failures endpoint. Beyond the specified route surface.
- Run listing or filtering UI. The host owns navigation, as the demo already does.
- D-1, D-2 (security-hardening plan) and G-4 (Plan 5).

---

## 11. Documentation

- `docs/08-editor-client.md` loses its "Not here yet" section and gains the run page wiring,
  `FlowRun` props, the overlay contract, the polling and failure behaviour, and E13's `reached`
  semantics including its limitation.
- `docs/05-execution-model.md` records "ran, released nobody, wrote no row" beside C-1.
- `README.md` updates its status paragraph and its counts.
- The editor spec gets an **"as built"** block on §6 and marks Plan 4 delivered in its §3 table,
  following R-1's lesson that a plan's author reads the section, not the changelog.
- `open-issues.md` records G-1 resolved with its mutation proofs, E13's new limitation, and what
  Plan 4 did and did not do to C-1.

---

## 12. Requirements traceability

| Requirement | Where met |
|---|---|
| Run view reads the run's pinned `flow_version` | §3.5, §7 (two pinning tests) |
| Overlay from `node_executions` and `run_subjects` (foundation §11) | §3.3 |
| Never-reached and reached-zero remain distinct | E13, §3.4, §4.2, §7 |
| Two grouped queries through the scoped `Run` | §3.3, §7 (query-count test) |
| Controllers never query `RunSubject` or `NodeExecution` | §3.2, §5, §7 |
| Paginated drill-down at six-figure scale | E15, §3.6 |
| Polling stops at a terminal run | E14, E17, §4.3 |
| `FlowRun` reuses `Canvas`, `NodeCard`, `layout` | E16, §4.4 |
| `FlowRun` imports nothing from `editor/` | §4.4, §7 (`boundary.test.ts`) |
| Read-only normalization strips transient state | §4.4 |
| 404/default-deny preserved | §3.1, §6 |
| Untrusted identifiers use own-key-safe lookups | §3.5, §4.2 |
| React singleton and Vite `dedupe` preserved | §4.1 (no new dependency), §9.6 |
| G-1 resolved before an unscoped query form is written | E18, §5, §7 |
| G-3 honoured by navigation, not raw keys | §3.6, §5, §7 |
| C-1 preserved rather than redesigned | E17, §5, §10 |
