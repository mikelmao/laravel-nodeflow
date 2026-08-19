# Next session: ship the editor inside the package, plus node authoring tooling

> ## ⚠️ SUPERSEDED — this brief has been actioned. Do not execute it again.
>
> Kept for the Voodflow research brief, the traps list, and the questions it posed — the answers are
> attached below. **The live documents are:**
>
> - **Design:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — decisions
>   E1–E12, six sequenced plans, and the four code-level gaps found while writing it (no
>   authorization layer existed, no draft storage existed, three models were unscoped with no
>   `tenant_id` to scope on, and a null current tenant was overloaded between "no tenancy" and
>   "unresolved").
> - **Plan 1 — delivered:** `docs/superpowers/plans/2026-08-19-nodeflow-make-node.md`,
>   merged as `4cadfb7..e22bd89`. `nodeflow:make-node` ships. Read that plan's own header before
>   copying code out of it; several of its snippets are known-defective.
> - **Next up:** Plan 2, the security floor — authorization gates, `FlowVersion` scoping, and
>   `nodeflow.tenancy`. It must land before the editor's first HTTP route exists, which is exactly
>   the hazard this brief flagged as "directly in your path".
>
> ### The one thing this brief asked to verify, answered
>
> It suspected Voodflow has a build command turning a custom node into a shareable package, and noted
> that a search of the plugin-integration page found none. **The suspicion was right; the search was
> on the wrong page.** Both commands are documented under `advanced/custom-nodes`:
> `voodflow:make-node` (an interactive wizard collecting type, tier FREE/PRO, author, licence,
> repository — emitting a PHP class, a `.jsx` component and a `manifest.json` into three files) and
> `voodflow:build-node` (esbuild, React externalised, output copied to `public/js/voodflow/nodes/`,
> self-registering as `window.VoodflowNode_{Class}`).
>
> We borrowed the manifest-as-declaration idea and the scaffold-then-transform shape. We rejected the
> artifact: a prebuilt bundle cannot participate in the host's Tailwind content scan, which is the
> same reasoning as D2. See spec §1 "Prior art: Voodflow, verified".
>
> ### What this brief got wrong, worth knowing
>
> Its proposed fail-closed fix for a null current tenant was not the flip it describes. The package's
> own default `TenantResolver` returns `null`, so failing closed on null would break the package out
> of the box. `null` had to be disambiguated first — hence `nodeflow.tenancy` = `disabled` |
> `resolver` (spec E2).

## What you are picking up

`laravel-nodeflow` (`~/Projects/laravel-nodeflow`, branch `main`) is a Laravel package that lets a
host application's *customers* build their own multi-day automated journeys — visually — backed by a
durable execution engine (`durable-workflow/workflow` v2). One event can fan out to a run per tenant
over a six-figure audience; journeys wait days and cancel when a customer converts.

**The engine half is done and tested (166 tests).** Storage with immutable graph versioning, the node
contract, the durable interpreter, triggers, three-layer tenancy, test mode, retention pruning.

**The editor half does not exist in the package at all.** The package is pure PHP — no `resources/js`,
no `package.json`.

There is a **throwaway prototype** editor in a separate demo app at
`~/Sites/test-workflow` (`resources/js/pages/nodeflow/editor.tsx`, ~330 lines, React Flow via
`@xyflow/react`, plus `app/Http/Controllers/NodeflowEditorController.php`). It works: it builds its
palette and config panel from the package's own `NodeRegistry::palette()`, and publishes through
`PublishFlow` with server-side validation. **Read it, learn from it, then throw it away.** It was
built explicitly to surface ergonomic problems before the real one is designed.

## What this session should produce

**A design and an implementation plan** — not the finished code, unless the plan is executed after
approval. Three things need designing together, because they constrain each other:

### 1. The editor, shipped inside the package

Per the spec's decision **D2**, the editor ships as **source** under the package's `resources/js`,
consumed by the host's own Vite build through an alias — *not* a prebuilt bundle. A bundle would
carry its own React and its own styling and would look like an iframe that isn't one. Compiling
against the host's React and Tailwind tokens is the entire point: it must render inside the host
app's layout and design system.

Design questions worth settling: how the host opts in (routes, controllers, a publishable page),
how a host overrides or themes a node's appearance, and how the run-overlay view (live subject
counts painted onto the canvas, from `node_executions`) fits alongside authoring.

### 2. Artisan commands for authoring custom nodes

The host application writes the domain nodes; the package should make that a one-command start.
Think `php artisan nodeflow:make-node`, and the trigger equivalent. Consider what else deserves a
generator — subject attributes, a whole starter flow, a test scaffold.

The spec's primary ergonomic goal is that **a domain node is about an hour's work: one class plus
one declarative definition.** Any generator must serve that, not bury it.

### 3. Packaging a custom node for sharing

The ask: a way to take a node a host wrote in their own project and turn it into a distributable,
shareable package that another application can install. Design what that artifact is, what it must
declare, and how the package discovers and registers it on install.

## Research Voodflow first

Voodflow is a Filament workflow plugin with a genuinely good custom-node story. It was **evaluated
and rejected** for this project on runtime grounds (blocking `usleep()` delays, no paused/waiting
execution state, sequential For Each, Livewire-only editor, and a licence barring SaaS to third
parties) — so do **not** copy its architecture. But its *authoring ergonomics* are the best prior art
available, and the original brief called them out as worth borrowing.

Docs: **https://docs.voodflow.com/**

Specifically useful entry points:

- https://docs.voodflow.com/guide/introduction.html
- https://docs.voodflow.com/guide/architecture
- https://docs.voodflow.com/concepts/workflows.html
- https://docs.voodflow.com/advanced/plugin-integration — documents `Voodflow::registerEvent()`, a
  `HasVoodflow` interface for exposing model fields, `PayloadProvider` for controlling event
  payloads, and `IdentifiableEvent` for trigger identity
- The **"Creating Custom Nodes"** page in the Advanced section of the sidebar

**One thing to verify rather than assume:** the request that prompted this work included a belief
that Voodflow has a *build command* which turns a custom node into a shareable package. A search of
the plugin-integration page found registration interfaces but **no CLI command**. Confirm whether
such a command exists before designing around it. If it does not, that is fine — design what *should*
exist for us, and say so.

Borrow: the shape of the node contract (`type()`, `defaultConfig()`, `validate()`, `definition()`
returning a plain config-field array, `execute()` with multi-output branching), how the config schema
drives the editor, and how registration and discovery work. Our node contract already resembles this
because it was borrowed once; the question now is what the *tooling* around it should look like.

## Read these before designing

- `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` — the architecture spec and its 14
  numbered decisions. **§5** is the node contract, **§10** the config-schema-once principle, **§11**
  the editor design, **§18** the engine API corrections. The spec is the binding authority.
- `docs/01-overview.md` through `docs/07-worked-example-rada-yaya.md` — the integrator-facing guides,
  all verified against the code.
- `src/Nodes/NodeRegistry.php` — `palette()` is what an editor consumes.
- `src/Schema/{Field,NodeDefinition,FieldType}.php` — the config-field system.
- `src/Graph/{Graph,GraphValidator}.php` — the graph JSON contract and every publish-time rule.
- `src/Publishing/PublishFlow.php` — `GraphInvalidException::errors()` returns a per-error array so an
  editor can render them beside the offending node.

## Gaps the prototype surfaced — fix these in the design

**`multiselect` has no real control.** The field type exists and the prototype degrades it to a
single select, because nothing in the package tells an editor how a field type should render. The
field-type → component mapping needs to be a defined, extensible part of the package, including a way
for a host to register a custom control for a custom field type.

**The `options_source` convention is undefined.** A field declaring `optionsFrom(SomeClass::class)`
is asking the host to resolve options server-side at edit time (so each tenant sees only their own
templates). The prototype invented "resolve the class, call `options()`" in its controller. The
package specifies nothing. Define this properly.

**Canvas positions already round-trip.** A node may carry a `position` key; the package stores and
returns it untouched. Do not redesign that.

## Traps this project has already paid for

**The engine's published documentation describes an API the installed release no longer ships.**
`durable-workflow/workflow` 2.0.0-rc.32 has two parallel APIs. Workflows are non-generator `handle()`
methods running in a Fiber; signals use a class-level `#[Workflow\V2\Attributes\Signal('name')]`;
`awaitWithTimeout` is static, taking `(timeout, condition)`. The v1 forms **resolve silently** and
misbehave at runtime. All of this is recorded in spec §18 with vendor file:line citations. If you
touch engine code, verify against `vendor/durable-workflow/workflow/src/V2/`, never the published docs.

**Only `src/Engine/` and `src/Workflows/` may import `Workflow\`.** An architecture test enforces it.
That boundary is what made two wrong-API discoveries survivable — each correction touched five files.

**Tests that read as covering a property while being unable to detect its failure.** This happened
eight times during the foundation work: a tautological `expect(true)->toBeTrue()`, an output key named
to look like a failure, a single-chunk fixture for a multi-chunk property, an empty-table fixture, a
graph shaped to trigger a warning that never asserted the warning fired. For every test you write,
answer: *what production change would make this fail?* If you cannot name one, the test is not
finished.

**Scripted edits that silently match nothing.** Two edits in this project applied cleanly and changed
nothing, because a `str.replace` pattern did not match and nothing asserted it had. Assert the anchor
exists and is unique before replacing, then verify the result downstream — including in built
frontend output.

## Known follow-ups from the foundation work

Not this session's job, but be aware — some may interact with the editor:

- `FlowVersion`, `RunSubject` and `NodeExecution` carry **no tenant scope**. They are reached through
  their parents today. **The moment the editor adds HTTP routes, `FlowVersion::find($request->version)`
  is a cross-tenant read.** Add the scope or an explicit ownership join before any route exists.
  This one is directly in your path.
- A null current tenant yields an unscoped read; the final review argued for fail-closed instead.
- `runs.status` never reaches a failure state; only `running` and `completed` are written.
- `ownsSubject()` is called once per subject; a set-shaped contract is wanted before six-figure use.
- The suite is SQLite-only and the interpreter has never run against a real queue worker in CI.

## How to work

Use the superpowers skills. This is **architectural** — a new subsystem with interfaces others depend
on — so: `superpowers:brainstorming` (questions one at a time, 2–3 approaches with a recommendation,
a sectioned design approved section by section, then a written spec), and only then
`superpowers:writing-plans`.

Do not start implementing before the human approves the design. The foundation work was executed with
`superpowers:subagent-driven-development` — one implementer per task, an independent review after
each, a whole-branch review at the end — and that process caught four Critical defects that sixteen
per-task reviews had missed. It is worth repeating.
