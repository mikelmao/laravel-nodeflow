# Next session: the editor's React client (Plan 3b), then Plans 4–6

## What you are picking up

`laravel-nodeflow` (`~/Projects/laravel-nodeflow`, branch `main`, at `0776909`) is a Laravel package
that lets a host application's *customers* build multi-day automated journeys visually, backed by a
durable execution engine. One event fans out to a run per tenant over a six-figure audience; journeys
wait days and cancel when a customer converts.

**Three of six plans are merged. The suite is at 319 tests, 737 assertions, all green.**

| Plan | State |
|---|---|
| 1 — `nodeflow:make-node` generator | ✅ merged |
| 2 — security floor (gates, tenancy, `FlowVersion` scoping) | ✅ merged |
| 3a — the editor's **server surface** | ✅ merged |
| **3b — the editor's React client** | **next** |
| 4 — run view | after 3b |
| 5 — remaining tooling (`install`, `make-trigger`, `make-subject-attribute`) | after 3b |
| 6 — node packaging (`make-node-package`, `extract-node`) | after 1 + 5 |

## Read these first

- `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — **the binding authority.**
  Decisions are numbered E1–E12 (plus E2a). **§5.5–5.9 is Plan 3b.** §3's table tracks plan status;
  §4 and §7.2 carry "as built" blocks describing what actually shipped versus what was proposed —
  read those blocks before the prose they sit above.
- `docs/superpowers/open-issues.md` — thirteen known-but-unfixed items with provenance, plus a list
  of things deliberately accepted so they are not re-litigated. **Two entries need a human decision;
  see below.**
- `docs/02-integration.md` — the integrator-facing guide, accurate against the code as of `0776909`.
  It documents the routes, the draft contract, publish's two 422 shapes, and the option-source
  contract. This is what a client author reads.
- The foundation spec `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` — D1–D14, the
  node contract (§5), the engine-API corrections (§18).

## Two decisions waiting on the human — raise these early

**D-1 · the `nodeflow.tenancy` inference mechanism.** As shipped, `auto` (the default) infers what a
`null` current tenant means from whether the container holds the package's own `NoTenancyResolver` or
a host-bound one. A whole-branch reviewer showed that answers "is our resolver bound *right now*",
not "does this application have tenancy" — so a host binding its resolver in middleware, a normal
Laravel pattern, gets the fallback in queue and console contexts and reads across tenants. The docs
now warn about it; the mechanism was not changed because doing so alters approved decision **E2a**.
The stronger fix is for the provider to record a boolean when the host's binding wins. **Free now,
breaking once hosts exist.**

**D-2 · foundation spec §9 layer 2 does not exist.** It claims runs denormalise `tenant_id` and that
`RunNodeActivity` asserts it matches before executing. There is no such assertion. Either implement
it or correct the spec — a documented defence layer that isn't there is worse than two honest ones.
It also matters because Plan 2's relation-unscoping reasoning leans on the same edge (see G-3).

## What Plan 3b builds

The React client, compiled by the **host's** Vite against the **host's** React and Tailwind tokens —
decision **D2**, the entire point being that it renders inside the host's design system rather than
looking like an iframe that isn't one.

Approved shape, from the spec:

- **E4** — the package exports **components, not pages**. The host writes a three-line Inertia page
  that wraps `<FlowEditor>` in its own layout. Inertia's resolver globs the host's own pages, so a
  page inside `vendor/` is never found; the thin page is resolver entry, layout seam and theming seam
  at once.
- **E5** — field controls are a **prop merged over package defaults**, never a global registry.
  Symmetric with the package's explicit node registration, and a global populated by import
  side-effects does not survive Inertia SSR.
- **§5.5** the `resources/js` layout, **§5.6** host wiring, **§5.7** the six field controls,
  **§5.8** node appearance overrides via the same prop-merge shape, **§5.9** autosave.
- **E12** — a **private, dev-only** `package.json`: devDependencies for Vitest and `tsc --noEmit`,
  peerDependencies for documentation. Never an npm publish target; publishing would reopen the
  two-sources-of-truth problem D2 closed.

### Four host-wiring requirements that fail silently if missed

The spec calls these out and `nodeflow:install` (Plan 5) is meant to verify them. 3b must at least
document them:

1. The **Vite alias** mapping `@nodeflow/editor` into `vendor/atram/laravel-nodeflow/resources/js`.
2. A **tsconfig path mapping** for the same — without it the build succeeds but the host's `tsc` and
   their editor's IntelliSense both fail on the import.
3. A **Tailwind `@source`** line pointing into `vendor/`. Tailwind v4's automatic source detection
   deliberately skips gitignored paths, and `vendor/` is gitignored. Miss this and the build
   succeeds, the editor renders, and every class is missing.
4. **`@xyflow/react` as a host dependency.** The host's Vite compiles our source, so npm never
   installs anything on our behalf; an alias into `vendor/` does not pull that package's deps.

## The API the client is written against

All four routes are **opt-in** — the host calls `Nodeflow::routes()` inside its own `Route::group`, so
prefix and middleware are its choice. A host that never calls it loads no controller and needs no
Inertia (`inertiajs/inertia-laravel` is `require-dev` + `suggest`, never `require`, because the
foundation spec §4 promises an engine-only host works).

| Method | URI | Name | Gate |
|---|---|---|---|
| `GET` | `flows/{flow}/edit` | `nodeflow.flows.edit` | `nodeflow.update` |
| `PUT` | `flows/{flow}/draft` | `nodeflow.flows.draft` | `nodeflow.update` |
| `POST` | `flows/{flow}/publish` | `nodeflow.flows.publish` | `nodeflow.publish` |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `nodeflow.fields.options` | `nodeflow.update` |

A cross-tenant id is a **404**, never a 403 — a 403 would confirm the row exists. It comes from route
model binding through the tenant-scoped model, before any controller code runs.

**`edit()` props** — this is the contract, verified against the shipped controller:

```
flow:   { id, name, trigger_type, status, version, draft_revision, draft_updated_at }
graph:  draft_graph ?? currentVersion.graph ?? { start: '', nodes: [], edges: [] }
palette: NodeRegistry::palette()
triggers: TriggerRegistry::palette()
```

The draft wins over the published version when one exists — that precedence is a real rule with a
test pinning it.

**Draft autosave.** `PUT .../draft` takes `{graph, draft_revision}`, returns `{draft_revision}`.
`draft_revision` is an **integer**, nullable/omittable on a flow's first save. Echo it back on the
next save; a mismatch is **409** with `{message, graph, draft_revision}` — the newer graph, so the
editor can say "someone else edited this" rather than silently discarding a colleague's work.

**`draft_updated_at` is not the token.** It exists, and is worth showing as "last saved 3 minutes
ago", but Laravel stores timestamps at second precision and a debounced autosave saves several times
per second — a timestamp token silently stops detecting. Do not build against it.

**Draft saves are not validated semantically.** A half-connected graph is the normal state of a canvas
someone is working on; refusing to store it would make autosave useless exactly when it matters. The
endpoint does validate the graph's *structure* (`start` a string, `nodes.*.id` and `.type` required
strings, etc.) so a malformed payload is a 422 rather than a 500.

**Publish** takes `{graph}`, returns `{version, draft_revision}`. It has **two distinct 422 shapes**
and the client must tell them apart — this bit people during 3a and is documented in
`docs/02-integration.md`:

- **structural** — Laravel's `ValidationException` body. `errors` is a **keyed object**
  (`{"graph.nodes.0.id": ["..."]}`), and `node_errors` is **absent**.
- **semantic** — `errors` is a **flat string array**, plus `node_errors` as
  `[{node, field, message}]` so each message renders on its own node card.

In `node_errors`, `node` is `null` for a graph-level failure such as a cycle. And for a
"start node missing from the graph" failure, `node` is an id that **has no node in the graph** — so
the client must not assume every entry maps to a rendered card.

**Field options** resolve lazily, per field, when the editor renders it — never eagerly into the
palette, or every option source of every registered node would run on every page load. The endpoint is
keyed by `(node type, field key)` and **never accepts a class name from the client**; the source comes
from the node's own `definition()`. It returns `{"options": {...}}`, always a JSON object. A field's
palette entry carries `dynamic_options: true` and deliberately **not** the class name.

`Field::custom('destination', 'town')` declares a type the package does not know, with an optional
third argument for its validation rule. The matching React control is registered via the `controls`
prop; an unregistered type must render a **visible named error**, never a text input — a silent
fallback turns a town picker into free text that passes `string` validation and reaches a node as
garbage.

## Known-open defects you may trip over

From `docs/superpowers/open-issues.md`, the ones in 3b's path:

- **F-1** `nodeflow:make-node --group='{{ outputs }}'` renders an unparseable file and exits 0, and
  `paletteGroup()`'s docblock claims a backslash and a quote are the only dangerous characters, which
  is false. Fix the comment first. Two lines.
- **F-2** Nothing but `php -l` watches `stubs/node.both.stub`. Renaming `->help(` in that file alone
  leaves the whole suite green while the stub fatals in every host. ~15 lines.
- **G-1** The request-context scanner misses `DB::table('nodeflow_run_subjects as rs')`, `join` and
  `from` forms. It is the *only* defence for the two deliberately untenanted tables, and Plan 4's
  subject drill-down is exactly where an aliased query would get written.
- **G-3** The FK invariant behind three unscoped relations is documented, not enforced. **Plan 4's
  controllers must never accept `flow_version_id` from request input**, the same rule 3a follows.

## How to work

Use the superpowers skills. `superpowers:writing-plans` for a plan (§5.5–5.9 is already designed, so
no brainstorming needed), then `superpowers:subagent-driven-development` to execute it — one
implementer per task, an independent review after each, a whole-branch review at the end. That process
caught two Criticals on 3a that sixteen per-task reviews had not, and it is worth the cost.

### Traps this project has already paid for

**Plans specify a mechanism without enumerating what already touches it.** This is the single
recurring defect across all three plans. Plan 2 scoped a model without listing the relation call sites
— five broke, three on the durable execution path. Plan 3a specified a concurrency token without
checking its precision, then its type, then what `PublishFlow` did to it: three rounds on one
mechanism, each defect real. **Before specifying a mechanism, grep for everything that touches it.**

**Tests that read as covering a property while unable to detect its failure.** Recorded eight times in
the foundation work and repeatedly since. For every test, name the production change that would make it
fail — and where a finding was proven by experiment, close it by experiment: revert the fix, watch the
new test fail, restore it. On 3a a test I asked for could not have detected its target, because PHP
coerces a numeric string to `?int` at a non-strict call site.

**"Not built yet" doc lines surviving behind the thing the same branch built.** Three occurrences:
Plan 1 shipped a line denying the scaffolding generator it added, Plan 2 one claiming a cross-tenant id
is a 404 when the default made it a 200, Plan 3a a "There is no UI" section two sections below the
routes that render one. Grep the docs for denials of whatever you just built.

**Scripted edits that silently match nothing.** Assert the anchor exists and is unique before
replacing, then verify downstream — including in built frontend output.

**The engine's published docs describe an API the installed release does not ship.** If you touch
engine code, verify against `vendor/durable-workflow/workflow/src/V2/`, never the published docs. Spec
§18 has the file:line citations. Only `src/Engine/` and `src/Workflows/` may import `Workflow\`, and an
architecture test enforces it.

**Verify the merged result, not just the branch.** 3a's suite passed in the worktree and failed on
`main`: `composer.lock` merged, `vendor/` is gitignored, so main's install predated a new dev
dependency. `composer install` fixed it, but a "green" merge would have shipped.

## The demo app — the original ask, still pending

`~/Sites/test-workflow` is a demo application that integrates the package and is meant to showcase the
real Atram scenario (a Rada flood alert fanning out to Yaya messaging per FSP). It **symlinks** the
package at `vendor/atram/laravel-nodeflow`, so it sees package changes instantly.

It still contains a **throwaway prototype editor** at `resources/js/pages/nodeflow/editor.tsx` (~330
lines, React Flow via `@xyflow/react`). The spec says: read it, learn from it, throw it away. Its
findings are already folded into the spec — the `multiselect` gap, the undefined `options_source`
convention, and one real latent bug worth knowing: its payload builder does
`output: e.sourceHandle ?? 'default'`, so a dropped handle publishes an edge naming an output the node
never declared, surfacing server-side as an error about an output the author never chose.

**The migration is Plan 3b's natural finish:** delete `editor.tsx`, replace it with the three-line page
wrapping `<FlowEditor>`, and drive the real endpoints. Its provider also calls `Nodeflow::register([])`
directly rather than holding the `protected array $nodes = [` anchor `nodeflow:make-node` looks for, so
the generator there takes its snippet-printing fallback — worth reconciling when Plan 5 builds
`nodeflow:install`.

Business context for the demo's journey content is available via the `gbrain` MCP if the scenario needs
shaping beyond what `docs/07-worked-example-rada-yaya.md` records.
