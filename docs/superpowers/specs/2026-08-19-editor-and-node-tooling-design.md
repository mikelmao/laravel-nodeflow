# Editor and node tooling — Design

**Date:** 2026-08-19
**Status:** approved, section by section, pending implementation plans
**Extends:** `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` (the foundation spec)

This document covers three things the foundation spec promised but did not build: the editor
shipped inside the package, artisan commands for authoring nodes, and a way to package a node
for sharing. They are specified together because they constrain each other — the editor's
field-control registry is the interface a shared node depends on, and the generators emit what
both define.

The foundation spec remains the binding authority on the engine, the node contract, storage and
tenancy. Where this document decides something new it says so. Its decisions are numbered **E1**
onward so the foundation spec's **D1–D14** need no renumbering; an E decision never contradicts a
D decision without saying which one and why.

---

## 1. Context

The engine half of `laravel-nodeflow` is done and tested. The editor half does not exist in the
package at all: it is pure PHP, with no `resources/js` and no `package.json`.

A throwaway prototype editor exists in a separate demo app at `~/Sites/test-workflow`
(`resources/js/pages/nodeflow/editor.tsx`, ~330 lines, React Flow via `@xyflow/react`, plus
`app/Http/Controllers/NodeflowEditorController.php`). It works — it builds its palette and config
panel from `NodeRegistry::palette()` and publishes through `PublishFlow` with server-side
validation. It was built to surface ergonomic problems before the real editor was designed, and it
did. It is not a starting point for the implementation; its findings are recorded in §2 and its
one real latent bug in §6.

### Prior art: Voodflow, verified

The handoff for this work carried a belief that Voodflow has a build command turning a custom
node into a shareable package, and noted that a search of its plugin-integration page found no
such command. **The belief was correct and the search was looking at the wrong page.** Both
commands are documented under `advanced/custom-nodes`:

- `php artisan voodflow:make-node {name}` — an interactive wizard collecting node type, **tier
  (FREE/PRO)**, author name and URL, description, repository, licence and optional logo. Emits
  three files into `packages/voodflow/voodflow/src/Nodes/Custom/{NodeClass}/`: the PHP class,
  `components/{NodeClass}.jsx`, and `manifest.json`.
- `php artisan voodflow:build-node {name}` — compiles the JSX with esbuild (React and ReactDOM
  externalised), writes `dist/{node-name}.js`, and copies it to
  `public/js/voodflow/nodes/{node-name}.js`. The bundle self-registers as
  `window.VoodflowNode_{NodeClass}` and is auto-discovered on page refresh.

Its node contract is close to ours by descent: `NodeInterface` with static `type()`,
`defaultConfig()`, `definition()` returning a plain array of config fields, `validate()`, and
`execute(ExecutionContext): ExecutionResult`, with `ExecutionResult::success($data)->toOutput('true')`
for branching.

**Borrowed:** the manifest as the artifact that declares a shareable node's identity; the wizard's
metadata set; the recognition that a shareable node must declare *both* halves in one place; and
the two-command shape, scaffold then transform.

**Rejected, with reasons:** the prebuilt bundle, because a node shipping compiled JSX cannot
participate in the host's Tailwind content scan and so renders unstyled or hardcodes its own
styling — the same reasoning as D2. The `window.VoodflowNode_*` global, because import-order
side-effects do not survive Inertia SSR cleanly. The public-directory scan, because the foundation
spec §5 already rejected auto-discovery for PHP nodes as "magic, slow to boot", and the JS half
should not obey opposite rules. And generating into the vendor directory, which puts host-authored code somewhere
`composer update` can destroy it.

Voodflow was evaluated and rejected as a runtime for this project on grounds recorded in the
foundation spec §16. Nothing here changes that.

### What the prototype surfaced

Three findings, all addressed in this document:

1. **`multiselect` had no real control.** The field type exists in PHP and the prototype degraded
   it to a single `<select>`, because nothing in the package tells an editor how a field type
   should render. Addressed by E5.
2. **The `options_source` convention was undefined.** A field declaring
   `optionsFrom(SomeClass::class)` asks the host to resolve options server-side at edit time. The
   prototype invented "resolve the class and call `options()`" in its own controller. The package
   specified nothing. Addressed by E6.
3. **Canvas positions already round-trip.** A node may carry a `position` key; the package stores
   and returns it untouched. Not redesigned here.

### What the code review found

Four gaps verified against the source during this design, each of which blocks routes:

- **There is no authorization layer in the package.** The foundation spec §4 promises four host
  gates — `nodeflow.viewAny`, `nodeflow.update`, `nodeflow.publish`, `nodeflow.runManually` —
  defaulting to deny when no gate exists. `grep -rn "Gate\|authorize\|Policy\|policies" src/ tests/ config/`
  returns nothing.
- **There is nowhere for a draft to live.** §11 of the foundation spec says drafts autosave on a
  debounce. No table holds one. `nodeflow_flow_versions` cannot absorb one: `version` is
  `unsignedInteger` under `unique(flow_id, version)`, `PublishFlow` assigns `max(version) + 1`, and
  `content_hash` is `NOT NULL`.
- **`FlowVersion`, `RunSubject` and `NodeExecution` are unscoped**, and none of the three carries a
  `tenant_id` column to scope on. `Flow`, `Run` and `Template` use `BelongsToTenant`.
- **`null` from `currentTenantId()` is overloaded.** It means both "this application has no
  tenancy" and "tenancy is unresolved right now", and today both yield an unscoped read. At least
  four test resolvers return `null` (`EventTriggerTest:109,261`, `StartRunTest:80`,
  `SubFlowStarterTest:16`) and — decisively — the package's own default binding at
  `NodeflowServiceProvider:33` returns `null`. A naive fail-closed flip would break the package
  out of the box.

---

## 2. Decisions

| # | Decision | Rationale |
|---|---|---|
| E1 | The security floor lands **before any route exists**: authorization gates, `FlowVersion` scoping, and disambiguating a null tenant | Routes turn `FlowVersion::find($id)` into a cross-tenant read. No installed hosts exist, so this is free now and expensive later |
| E2 | `null` is disambiguated by explicit mode: `nodeflow.tenancy` is `disabled` or `resolver`. Under `resolver`, a null tenant **throws**; `disabled` means unscoped and is a stated choice. Default `disabled` | Fail-closed is only correct for "unresolved", not for "single-tenant host". The package's own default binding returns null and must keep working |
| E3 | A draft is **not a version**. `nodeflow_flows.draft_graph` + `draft_updated_at`, one draft per flow, last-write-wins with stale detection | Keeps D8's immutability invariant undisturbed. Making a version row mean two things is the same overloading E2 exists to undo |
| E4 | The package owns **routes and controllers and exports components, not pages**. The host writes a three-line page and chooses prefix and middleware | Inertia's resolver globs the host's own pages; a page in `vendor/` is not found. The thin page is resolver entry, layout seam and theming seam at once |
| E5 | Field controls are passed as a **prop merged over package defaults**, never a global registry | Symmetric with the foundation spec's explicit node registration; a global populated by import side-effects is order-dependent and does not survive Inertia SSR |
| E6 | The options endpoint is keyed by **`(node type, field key)`** and resolves the source from the node's own `definition()`. A class name is never accepted from the client, and `options_source` stops appearing in the palette JSON | Accepting a class name is "instantiate any class in this application and call `options()` on it" |
| E7 | The run view is a **separate component and route** reading the run's pinned `flow_version.graph`, sharing canvas primitives with the editor and importing nothing from it | A run executed a frozen graph; the editor renders a draft that may have diverged. One component for both invites painting a run's counts onto nodes that were never in it |
| E8 | Live overlay is **interval polling**, not broadcasting | The foundation spec §4 says the package does not own queue or messaging infrastructure; requiring Echo/Reverb would dictate a websocket stack to the host |
| E9 | A shareable node is an **ordinary Composer package**. No manifest, no build step, no bundle, no discovery mechanism | Everything a manifest would declare is already declared where it works: `require` for compatibility, `extra.laravel.providers` for loading, `type()` plus explicit registration for identity |
| E10 | `nodeflow:extract-node` **refuses** a `type()` that is not a plain string literal, and asserts `type()` is byte-identical after the move | `type()` is what immutable versions and live mid-wait runs resolve through. A `type()` derived from `static::class` silently changes identity when the namespace moves, orphaning every published version referencing it. This is the one unrecoverable failure the command could cause |
| E11 | Every generated or modified file is written **anchor-asserted and then re-verified**; `nodeflow:install` exits non-zero when it could not wire something | Two edits in this project's history applied cleanly and changed nothing because a pattern did not match and nothing asserted it had |
| E12 | The package carries a **private, dev-only `package.json`** — devDependencies and peerDependencies only, never an npm publish target | Vitest and `tsc --noEmit` need it. Publishing to npm would reopen the two-sources-of-truth problem D2 closed |

---

## 3. Decomposition and sequencing

Five implementation plans, sequenced by dependency. One spec, because the field-control registry
(E5) is the interface node packaging depends on and the generators emit what both define.

| Plan | Contents | Depends on |
|---|---|---|
| **0 — Security floor** | Authorization gates; `FlowVersion` scoping; `nodeflow.tenancy`; the structural invariant for `RunSubject`/`NodeExecution` | — |
| **1 — Editor** | `draft_graph`; `Nodeflow::routes()` and controllers; options endpoint; `Field::custom()`; `resources/js`; six field controls; dev `package.json` + Vitest | 0 |
| **2 — Run view** | `FlowRun` component and routes; overlay queries; subject drill-down; polling | 1 |
| **3 — Tooling** | `nodeflow:install`; `make-node` with `--test`; `make-trigger`; `make-subject-attribute` | 1 |
| **4 — Packaging** | `make-node-package`; `extract-node` | 1, 3 |

Plan 0 gates Plan 1 because routes are the leak. Plan 2 needs Plan 1's canvas primitives. Plan 4
needs Plan 1's control registry and Plan 3's scaffolding, since extraction is scaffolding plus a
move. Plan 3's install command has nothing to verify until Plan 1's wiring requirements exist.

**Ordering note.** The foundation spec §5's "Known weakness" requires the node contract to be
validated by writing three real nodes early in implementation. `nodeflow:make-node` is cheap and
independent of everything in Plan 1, so it can be pulled forward at no cost if real domain nodes
are wanted while the editor is being built. It sits in Plan 3 because the editor does not need it.

---

## 4. Plan 0 — The security floor

### 4.1 Authorization

A `FlowPolicy` and a `RunPolicy` whose every method defers to a host-registered gate, denying when
the gate is undefined. The four gates are the ones the foundation spec §4 named:
`nodeflow.viewAny`, `nodeflow.update`, `nodeflow.publish`, `nodeflow.runManually`.

Default-deny means a host who installs and wires nothing receives a blanket 403. That is correct
and must be **diagnosable**: `nodeflow:install` reports which of the four gates are undefined
(§7.1), so the host is told rather than left to guess why the editor 403s.

### 4.2 Tenancy

`nodeflow.tenancy` takes `disabled` or `resolver`.

- **`disabled`** — reads are unscoped. This is a stated choice for a single-tenant host, and it is
  the package's default so the engine-only host of the foundation spec §4 keeps working untouched
  and stays tested.
- **`resolver`** — a `null` return from `currentTenantId()` throws a typed exception. A queue
  worker, console command or unauthenticated request that reaches a scoped read fails loudly
  instead of reading every tenant's rows.

The three unscoped models are treated differently, because they differ in cost and in exposure:

- **`FlowVersion` gains a `tenant_id` column** and `BelongsToTenant`. It is the model the handoff
  names explicitly, it is what the run view loads a graph from, and it is one row per publish, so
  the denormalisation is free.
- **`RunSubject` and `NodeExecution` gain no column.** At six figures these are the high-volume
  tables and a per-row tenant string is real cost for no gain: both are only reachable through a
  `Run`, which is already scoped. The invariant becomes structural — never bind them in a route,
  always query through `$run->subjects()` / `$run->nodeExecutions()` on a `Run` obtained from the
  scoped model — and is enforced by an architecture test asserting that no route or controller
  queries either model directly, in the same spirit as the existing `Workflow\` import boundary
  test.

---

## 5. Plan 1 — The editor

### 5.1 Storage

Three columns, folded into the existing migration
`2026_08_18_000001_create_nodeflow_tables.php` rather than added in a new one, since nothing is
installed anywhere:

- `nodeflow_flows.draft_graph` — JSON, nullable
- `nodeflow_flows.draft_updated_at` — timestamp, nullable
- `nodeflow_flow_versions.tenant_id` — string, indexed (from §4.2)

Publish reads `draft_graph`, validates, freezes a version, and clears the draft.

### 5.2 Routes

`Nodeflow::routes()`, called by the host inside its own group so prefix and middleware are the
host's choice. The table is the complete route surface across both plans; the three `runs/*` routes
land in Plan 2 (§6), the rest in Plan 1.

| Method | URI | Gate |
|---|---|---|
| `GET` | `flows/{flow}/edit` | `update` |
| `PUT` | `flows/{flow}/draft` | `update` |
| `POST` | `flows/{flow}/publish` | `publish` |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `update` |
| `GET` | `runs/{run}` | `viewAny` |
| `GET` | `runs/{run}/overlay` | `viewAny` |
| `GET` | `runs/{run}/nodes/{node}/subjects` | `viewAny` |

`{flow}` and `{run}` bind through the already-scoped models, so a cross-tenant id yields **404,
not 403** — a 403 would confirm the row exists.

### 5.3 Controller behaviour

**Draft save** accepts the graph plus the `draft_updated_at` the client last saw. A mismatch
returns `409` carrying the newer graph and its timestamp, so the editor can report "someone else
edited this flow" instead of silently discarding one side. A match writes and returns the new
timestamp. The endpoint **does not validate the graph** — a draft is allowed to be broken
mid-edit, which is precisely why it is not a version.

**Publish** submits the graph to `PublishFlow` and, on `GraphInvalidException`, returns
`errors()`. Those errors are currently flat strings that embed the node id
(`"Node [send1] field [template]: ..."`), so rendering an error beside its node would require the
editor to parse prose. `GraphValidationResult` therefore gains a structured node id alongside each
message. Without that change the foundation spec §11's promise of errors rendered beside the
offending node is not reachable. Warnings — such as the existing concurrent-waits warning — are
returned too, and are non-blocking.

**Options** resolves the source from the node's own `definition()` by `(type, field)`, asserts
`is_a($source, OptionSource::class)`, and returns `{options: {...}}`. An unknown node type or
field key is a 404. A class that does not implement the interface is a named error, never an empty
select — the prototype's `method_exists` check degraded to "no options", which is
indistinguishable from "this tenant has no templates".

### 5.4 The option-source contract

`Nodeflow\Schema\OptionSource` with `options(): array`, checked with `is_a()` rather than duck-typed.

Resolution is **lazy**, over the endpoint, not baked into the palette. Eager resolution would run
every option source of every registered node on every editor page load, including nodes the author
never places — a host with a dozen domain nodes would pay a dozen tenant-scoped template lookups
to render a palette.

`options_source` no longer appears in the palette JSON as a class name. The browser needs to know
only *that* a field is dynamic; leaking `App\Yaya\YayaTemplates` to the client buys nothing and
invites the endpoint to accept it.

**Dependent options are out of scope.** A template list that narrows by the `channel` chosen on the
same node is a real want, but it requires the endpoint to accept the node's partial config, which
reopens the question of what the client may be trusted to send. In v1 `optionsFrom()` receives
ambient tenancy and nothing else.

### 5.5 Client layout

`resources/js`, with a single `index.ts` as the public surface; everything else is internal and
free to move.

```
index.ts            FlowEditor, FlowRun, defaultControls, defaultNodeRenderer, types
graph/              toCanvas.ts, toGraph.ts, types.ts        ← pure, the Vitest target
canvas/             Canvas.tsx, NodeCard.tsx, layout.ts      ← shared by editor and run
editor/             FlowEditor.tsx, Palette.tsx, ConfigPanel.tsx, useAutosave.ts
run/                FlowRun.tsx, useOverlayPolling.ts
controls/           index.ts + six built-ins, Unregistered.tsx, useFieldOptions.ts
```

### 5.6 Host wiring

Four things, all verified by `nodeflow:install` (§7.1):

1. The **Vite alias** mapping `@nodeflow/editor` into `vendor/atram/laravel-nodeflow/resources/js`.
2. A **tsconfig path mapping** for the same. Without it the build succeeds but the host's `tsc` and
   their editor's IntelliSense both fail on the import.
3. A **Tailwind `@source` line**:
   `@source '../../vendor/atram/laravel-nodeflow/resources/js';`
   Tailwind v4's automatic source detection deliberately skips gitignored paths, and `vendor/` is
   gitignored. Miss this and the build succeeds, the editor renders, and every class is missing.
4. **`@xyflow/react` in the host's `package.json`.** Under D2 the host's Vite compiles our source,
   so npm never installs anything on our behalf; an alias into `vendor/` does not pull that
   package's dependencies.

Then the thin page, which is the Inertia resolver entry, the layout wrap, and the theming seam:

```tsx
import { FlowEditor } from '@nodeflow/editor'
import AppLayout from '@/layouts/app-layout'

export default function Page(props) {
  return <AppLayout><FlowEditor {...props} /></AppLayout>
}
```

### 5.7 Field controls

One contract, deliberately narrow:

```ts
type FieldControlProps = {
  field: Field                          // the compiled PHP definition
  value: unknown
  onChange: (next: unknown) => void
  errors: string[]                      // server messages for this field
  options: Record<string, string>       // resolved, static or dynamic
  optionsLoading: boolean
}
```

The **package** performs option fetching, in `useFieldOptions`, keyed by `(node type, field key)`.
A custom control receives options as data and never learns the URL, so E6's invariant cannot be
broken by a host's control.

`controls` merges over `defaultControls`. An unmatched type renders `Unregistered`, which **names
the missing type** — never a text input. Falling back to text would silently turn a town picker
into free text that passes `string` validation and reaches a node as garbage.

Two built-ins exceed what the prototype had:

- **`multiselect`** becomes a real multi-value control, closing the gap in §1.
- **`duration`** becomes an amount-plus-unit pair emitting `"5 minutes"`, because `ValidDuration`
  is strict; a free-text box makes the author discover that at publish time rather than at type
  time.

The built-in set stays closed at the current six. Custom types are the extension path, via
`Field::custom('destination', 'town')`, which also carries the server-side base rule — publish-time
validation must work for a type the package has never heard of, and `FieldType` is an enum a host
cannot add a case to.

### 5.8 Node appearance overrides

The same prop-merge shape as controls: `nodeRenderers={{ 'yaya.send_message': MyCard }}` over a
default renderer reading `icon`, `label`, `group` and `description` from the definition. One
mechanism, learned once, rather than a second theming concept.

### 5.9 Autosave

Debounce, `PUT` the graph with the last-seen `draft_updated_at`, and on `409` surface the conflict
with the newer graph rather than discarding either side.

---

## 6. Plan 2 — The run view

`GET runs/{run}` renders a page carrying the run, **the graph from the run's pinned
`flow_version`** — never `draft_graph` — and an overlay snapshot. `GET runs/{run}/overlay` returns
the snapshot alone for polling. `GET runs/{run}/nodes/{node}/subjects` is the drill-down the
foundation spec §15 puts in v1, paginated because at six figures it must be.

Overlay shape, one entry per node id:

```ts
{ reached: boolean, byOutput: Record<string, number>, waiting: number, failed: number, error: string | null }
```

**`reached` is load-bearing and is derived from row existence, not from a count.** A node with no
`node_executions` row was never touched; a node with a row summing to zero was touched and released
nobody. Rendering both as "0" is the same class of misreading as painting a run's counts on a draft
graph. Never-reached renders visually inert — dimmed, no badge; reached-with-zero renders an
explicit `0`.

Two grouped queries, both through the scoped `Run`: `$run->nodeExecutions()` grouped by
`(node_id, output)` summing `subject_count`, and `$run->subjects()` grouped by
`(current_node_id, status)` for live waiting counts. Both are covered by existing indexes —
`['run_id', 'node_id']` and `['run_id', 'current_node_id', 'status']`. This is the D4/D11 payoff
the foundation spec §11 describes.

Polling is an interval partial reload of the overlay prop alone, stopping when `run.status` is
terminal. **Caveat recorded rather than discovered:** `runs.status` never reaches a failure state
today (a known follow-up), so "terminal" in v1 means `completed`, and a run that dies leaves the
client polling until the page is closed. Acceptable, and cheap to fix once failure states exist.

**Reuse boundary.** `FlowRun` imports `Canvas`, `NodeCard` and `layout` from `canvas/`, and imports
nothing from `editor/`. That is the structural guarantee behind E7: no autosave, dirty state, draft
or publish path can reach this view.

---

## 7. Plan 3 — Artisan commands

### 7.1 `nodeflow:install`

Publishes config and migrations, creates `app/Providers/NodeflowServiceProvider.php` with an
explicit `$nodes = []` array as a registration home, and wires the four client requirements of
§5.6.

Then it **verifies**: re-reads each file and asserts the wiring is present; reports which of the
four authorization gates are undefined; reports the resolved `nodeflow.tenancy` mode; and **exits
non-zero if it could not wire something**, so CI catches a half-installed host. Idempotent — a
second run reports "already wired" and never duplicates a line. Every edit asserts its anchor
exists and is unique before writing (E11).

### 7.2 `nodeflow:make-node {name}`

One file: `app/Nodeflow/Nodes/{Name}.php`. Flags with prompt fallback, Laravel-style: `--type=`,
`--outputs=sent,failed`, `--cardinality=subject|audience|both`, `--group=`, `--test`.

The emitted class declares the outputs named and the cardinality interface chosen — the two things
that, per the foundation spec §5, produce an unexecutable node when they disagree. `--test` emits a
Pest test whose assertions already reference those real outputs, a structural answer to the eight
tautological tests recorded during the foundation work.

**Registration** appends to the `$nodes` array `install` created, anchor-asserted. If the provider
is absent or edited past recognition, the command prints the exact `Nodeflow::register([...])` line
and says why it did not write. It never guesses.

One file per node is non-negotiable: the foundation spec's primary ergonomic goal is that a domain
node is one class plus one declarative definition, about an hour's work. Voodflow's three-file
directory per node is the shape to avoid.

### 7.3 `nodeflow:make-trigger` and `nodeflow:make-subject-attribute`

`make-trigger` (`--event=`, `--type=`) emits the four abstract methods plus `idempotencyKey()` and
`matchesConfig()` as commented overrides. It earns its place because `event()` returning a host
event class is the most confusable part of the trigger contract.

`make-subject-attribute` appends a `SubjectAttribute::make()` through the same anchor mechanism.
It is thin, but conditions are the non-technical author's main tool under D13 and the attribute
registry is the least discoverable part of the package — it has no docs page of its own.

**Not built:** `make-flow`, because a starter graph is a template and the foundation spec §13
already owns fork-on-install; a bespoke generator would be a second mechanism doing the same job.
`make-field-control`, because generating a React file is a different kind of generator from the PHP
ones and the control's shape should be proven in use first.

---

## 8. Plan 4 — Packaging a node for sharing

### 8.1 `nodeflow:make-node-package {vendor/name}`

Scaffolds an ordinary Composer package (E9): `composer.json` requiring
`atram/laravel-nodeflow`, a service provider calling `Nodeflow::register()`,
`extra.laravel.providers`, an optional `resources/js/index.ts` exporting a `controls` object, a
README documenting both the provider and the controls spread, and a test directory.

There is no manifest, because everything one would declare is already declared where it works:
compatibility by `require`, provider loading by `extra.laravel.providers`, node identity by
`type()` plus explicit registration. The one failure a manifest appears to guard against — host
installs the package, registers the PHP nodes, forgets to spread the controls — is already caught
loudly by §5.7's `Unregistered` control naming the exact missing field type. A manifest cannot
detect it better, because PHP cannot see whether a JSX spread happened.

### 8.2 `nodeflow:extract-node {FQCN} --package=vendor/name`

Scaffolds, then moves: the class and its test, the namespace rewrite, the provider's register
array, and a path repository plus `require` in the host's `composer.json` so the host keeps working
immediately from the new location.

**Its most important check has nothing to do with files** (E10). `type()` is the stable identifier
that immutable graph versions and live mid-wait runs resolve through, and the foundation spec §5 is
explicit that it must never derive from the class name. So extraction must guarantee `type()` is
byte-identical afterwards, and must **refuse outright** if `type()` does not return a plain string
literal — a `type()` computed from `static::class` silently changes identity the moment the
namespace moves, orphaning every published version that references it. That is the one failure this
command could cause that re-running it cannot repair.

Verification after the move: `composer dump-autoload`, assert the new FQCN resolves, assert
`NodeRegistry::register()` accepts it, assert `type()` unchanged. Any failure aborts and restores.
The command never leaves a half-moved state.

---

## 9. Testing

**The discipline, stated as a rule:** for every test, name the production change that would make it
fail. If none can be named, the test is not finished. This is a recorded local failure mode — eight
tests written during the foundation work read as covering a property while being unable to detect
its failure.

Three tests in this design are especially easy to fake, and each needs its counterfactual written
into the test itself:

1. **Never-reached vs reached-with-zero.** A test asserting both render "0" passes while the bug is
   present. It must assert the two produce *different* payload fields, from a fixture containing one
   node of each kind.
2. **Run view loads the pinned version, not the draft.** Only meaningful with a fixture where
   `draft_graph` genuinely differs from the run's version, asserting the draft's distinguishing node
   is *absent*. A same-graph fixture passes vacuously.
3. **The options endpoint does not trust the client.** Must send a request carrying a hostile class
   name and assert it is ignored — not merely assert the happy path resolves.

**PHP (Pest, existing suite):** gate-by-gate default deny; all three `nodeflow.tenancy` states
including `resolver` + null throwing; a cross-tenant `FlowVersion` read returning nothing; an
architecture test that no controller queries `RunSubject` or `NodeExecution` directly; draft save,
stale-409, and draft-accepts-invalid-graph; per-node structured publish errors; the options
endpoint's 404s and its `is_a` failure; install idempotency and its non-zero exit; and for extract —
refuses a computed `type()`, preserves a literal one, aborts atomically.

**Vitest:** `toCanvas`/`toGraph` round-trip preserving start, ids, config, position and edge outputs;
control selection per field type; the unregistered type rendering a named error; the duration control
emitting only strings `ValidDuration` accepts; and autosave's 409 path.

**One Vitest case deserves naming, because it is a real latent bug in the prototype.** Its payload
builder does `output: e.sourceHandle ?? 'default'`. A dropped handle therefore publishes an edge
naming an output the node never declared, and `GraphValidator` rejects it server-side with a message
about an output the author never chose — a real bug with a confusing symptom, trivially unit-testable
as a pure function, and undetectable by any PHP test in this plan.

---

## 10. Error handling

| Condition | Response |
|---|---|
| Draft save, invalid graph | Accepted. A draft is permitted to be broken mid-edit |
| Draft save, stale timestamp | `409` with the newer graph and timestamp |
| Publish, invalid graph | Per-node structured errors, plus non-blocking warnings |
| Options, unknown type or field | `404` |
| Options, class is not an `OptionSource` | Named error. Never an empty select |
| Field type with no registered control | Loud in the panel, naming the type. Never a text input |
| Undefined authorization gate | `403`, with `nodeflow:install` explaining which gate is missing |
| Cross-tenant `{flow}` or `{run}` id | `404`, not `403` |
| `resolver` mode, null tenant | Typed exception |
| `extract-node`, any verification failure | Abort and restore |

---

## 11. Scope

### In scope — the five plans of §3

Security floor; editor with draft autosave, palette, config panel, publish validation and the field
control registry; run view with overlays and subject drill-down; four authoring commands; two
packaging commands.

### Explicitly out of scope

So the plans cannot drift into them:

- A prebuilt JS bundle, and any npm publish target
- An expression language for conditions (foundation spec D13, phase 3)
- The curated template library and its browsing UX (foundation spec §13, phase 2)
- Dependent option sources (§5.4)
- Playwright or any browser E2E — the v1 acceptance criterion is already a real-app check
- Broadcasting for live overlays (E8)
- Draft history. If it is ever wanted, an append-only `nodeflow_flow_drafts` table is a cleaner
  answer than overloading the version table
- Node auto-discovery, in either language
- `make-flow` and `make-field-control` (§7.3)

### Carried-forward follow-ups, not addressed here

From the foundation work, and none of them in the editor's path once Plan 0 lands:

- `runs.status` never reaching a failure state — noted in §6 as a polling caveat
- A set-shaped `ownsSubject()` contract before six-figure use
- The suite being SQLite-only
- The interpreter never having run against a real queue worker in CI

---

## 12. Requirements traceability

| Requirement | Where met |
|---|---|
| Editor ships as source inside the package (D2) | §5.5, §5.6 |
| Editor renders inside the host's layout and design system | E4, §5.6 |
| Host can override or theme a node's appearance | §5.8 |
| Run overlay from `node_executions` (foundation §11) | §6 |
| Config schema declared once in PHP (foundation §10) | §5.3, §5.7 |
| A host can register a control for a custom field type | E5, §5.7 |
| `multiselect` has a real control | §5.7 |
| `options_source` convention defined | E6, §5.4 |
| Canvas positions round-trip untouched | §1, unchanged |
| A domain node is one class plus one definition | §7.2 |
| Artisan command to start a node | §7.2 |
| Artisan command to start a trigger | §7.3 |
| Artisan command for a subject attribute | §7.3 |
| One command to a wired host install | §7.1 |
| A node can be packaged and shared | E9, §8 |
| An existing node can be extracted into a package | E10, §8.2 |
| Drafts autosave (foundation §11) | E3, §5.1, §5.9 |
| Publish errors render beside the offending node (foundation §11) | §5.3 |
| Tenancy is enforced once routes exist | E1, E2, §4 |
