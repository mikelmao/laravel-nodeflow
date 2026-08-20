# Editor Client Implementation Plan (Plan 3b of 6)

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents are available) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the editor's React client as TypeScript source inside the package — canvas, palette, config panel, six field controls, lazy field options, debounced autosave with conflict handling, and per-node publish errors — compiled by the *host's* Vite against the *host's* React and Tailwind tokens, and prove it by replacing the demo app's throwaway prototype with it.

**Architecture:** `resources/js` with one `index.ts` public surface. Pure graph transforms (`graph/`) are the Vitest target and know nothing about React. Canvas primitives (`canvas/`) are shared with Plan 4's run view and import React Flow. The editor (`editor/`) owns all state and passes it down. Field controls and node renderers are **props merged over package defaults** (E5, §5.8), never global registries. The package's JS imports neither `@inertiajs/react` nor any host alias: props arrive as props from the host's three-line Inertia page, and every request goes through one small `fetch` helper.

**Tech Stack:** TypeScript 5 strict, React 19 (peer, `^18 || ^19`), `@xyflow/react` v12 (peer), Vitest + jsdom + `@testing-library/react` (dev only, E12), Tailwind v4 utility classes only — no package CSS beyond React Flow's own stylesheet. PHP side: Laravel 12/13, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — **§5.5–5.9** and **E4**, **E5**, **E12** are this plan; **§5.2–5.4** shipped in Plan 3a and are the API this client is written against; **§9**'s Vitest list and **§10**'s error table are its acceptance criteria. Read §4's and §7.2's "as built" blocks before the prose above them. Foundation spec `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` §11 is where "drafts autosave" and "errors render beside the offending node" come from.

**Open issues this plan must respect:** `docs/superpowers/open-issues.md` — **G-3** (controllers never accept `flow_version_id` or `current_version_id` from request input; Task 2 touches a controller). **F-1**, **F-2**, **G-1**, **G-2** are not in this plan's path and stay open; Task 8 does not close them.

---

## Global Constraints

Every task's requirements implicitly include this section.

- **The 319 existing PHP tests must pass with no edits to any of them, with one carve-out named in Task 2.** Adding cases or assertions to an existing test *file* is fine. If you find yourself changing an existing assertion outside the carve-out, stop and report: it means the change is wrong.
- **The package never publishes to npm.** `package.json` is `"private": true`, devDependencies and peerDependencies only (E12). No `files`, no `exports`, no `main`, no build script that emits JS.
- **The package's production JS imports nothing from the host.** No `@/` alias, no `@inertiajs/react`, no host component library. Every internal import is relative. In non-test production modules under `resources/js`, the only bare imports permitted are `react`, `react-dom`, `@xyflow/react`, and `@xyflow/react/dist/style.css`. `*.test.*` and `test-setup.ts` may additionally import Vitest and Testing Library; none is reachable from `index.ts`.
- **Field controls and node renderers are props merged over package defaults (E5, §5.8).** No module-level mutable registry, no import side-effects. A global populated by imports is order-dependent and does not survive Inertia SSR.
- **An unregistered field type renders a visible named error, never a text input** (§5.7, §10). Falling back to text silently turns a town picker into free text that passes `string` validation and reaches a node as garbage.
- **An option-load failure is a named error, never an empty select** (§5.3, §10). An empty dropdown is indistinguishable from "this tenant has no templates yet".
- **The client never constructs an endpoint URL from a hardcoded path.** Routes are registered under the host's own prefix (E4), so URLs come from the server in the `urls` prop. `/nodeflow/flows/${id}/publish` — what the prototype did — is a defect, not a shortcut.
- **The client never sends `output: 'default'` for an edge whose handle it does not know.** This is the prototype's one real latent bug (spec §1, §9): a dropped handle publishes an edge naming an output the node never declared, and the server rejects it with a message about an output the author never chose. See Task 1's `resolveOutput`.
- **The client never sends `flow_version_id`, `current_version_id`, or `tenant_id` in any payload.** Open issue G-3: three unscoped relations rest on those FKs staying inside the parent's tenant, and nothing enforces it.
- **The client tells publish's two 422 shapes apart by the presence of the `node_errors` key**, never by inspecting the type of `errors` (`docs/02-integration.md`, "Telling the two apart").
- **A `node_errors` entry's `node` may be `null` (a graph-level failure such as a cycle) or an id with no node in the graph (a missing start node).** Render what you can find; everything else goes to the banner. Never index into the node map without a fallback.
- **`draft_updated_at` is never used for staleness.** The concurrency token is `draft_revision`, an integer. Laravel stores timestamps at second precision and a debounced autosave saves several times per second.
- **For every test, name the production change that would make it fail.** Write that counterfactual into the test as a comment. If you cannot name one, the test is not finished. This is a recorded local failure mode.
- **Where a finding was proven by experiment, close it by experiment.** Revert the fix, watch the new test fail, restore it.
- **Test commands:** PHP `vendor/bin/pest` (filter: `vendor/bin/pest --filter='<pattern>'`). JS `npm test` (= `vitest run`) and `npm run types:check` (= `tsc --noEmit`), both from the package root. All three must be green at every package-repository commit from Task 1 onward. Task 10's separate demo-repository commit instead requires its 44 PHP tests, host `tsc`, host Vite build, CSS scan and browser acceptance.
- **Do not import `Workflow\` outside `src/Engine/` and `src/Workflows/`.** An architecture test enforces it.

---

## Six decisions this plan makes that the spec left open

Each is a place where §5.5–5.9 specifies a mechanism without saying how it reaches the browser. They are stated here so a reviewer can reject the decision rather than discover it in code.

**1. The server hands the client its endpoint URLs, in a new `urls` prop.** E4 makes prefix and middleware the host's choice, so the client cannot construct a URL and the prototype's hardcoded `/nodeflow/flows/${id}/publish` cannot be carried forward. The alternative — the host's thin page passing URLs — breaks the three-line-page promise and pushes route knowledge onto the host. So `edit()` gains `urls: {draft, publish, options}`, resolved through the route *names*. This is an addition to Plan 3a's shipped prop contract and Task 2 updates the docs and the contract test accordingly.

**2. Those URLs are resolved through the host's own route-name prefix, not the bare names.** `route('nodeflow.flows.draft')` throws `RouteNotFoundException` for a host that wrote `Route::name('admin.')->group(fn () => Nodeflow::routes())` — an ordinary Laravel pattern, and the very pattern the demo app already uses for its own routes. The controller therefore derives the prefix from the *current* route's name and prepends it. Ten lines, one test, and one entire class of loud-but-baffling failure removed.

**3. The options URL travels as a template with two sentinel placeholders**, `__NODEFLOW_TYPE__` and `__NODEFLOW_FIELD__`, which the client replaces with `encodeURIComponent`'d values. Both sentinels are made of unreserved characters, so `route()` cannot re-encode them out from under the client. The alternative — a second endpoint that takes type and field as a query string — changes a shipped route for no gain.

**4. The package's JS never imports `@inertiajs/react`, and every request goes through one `fetch` helper.** Inertia's `router` performs Inertia visits, which is the wrong verb for an autosave that wants JSON back. Keeping Inertia out of the package's JS also means `FlowEditor` is a pure props component: the host's page receives props from Inertia and spreads them, and Vitest can render the editor with no Inertia runtime at all. CSRF is handled by reading the `XSRF-TOKEN` cookie Laravel's `web` group sets and sending it back as `X-XSRF-TOKEN` — which `VerifyCsrfToken` decrypts — falling back to a `<meta name="csrf-token">` tag.

**5. An edge whose output cannot be resolved is carried in the draft as `output: null` and blocks publish client-side.** The draft endpoint accepts a nullable `output` precisely so a half-drawn graph is savable, so the author's connection is never silently discarded. Publish refuses before sending, naming the edge. This is the prototype's `?? 'default'` bug fixed at the only layer that can fix it, and it is a pure function, so it is unit-testable.

**6. An option-load failure is surfaced through the existing `errors: string[]` prop rather than a seventh key.** §5.7 fixes `FieldControlProps` at six keys and every control already renders `errors` in the right place. The config panel appends the load failure to that array, so a host's custom control gets the named error for free without implementing anything.

---

## File Structure

### Created — package JS

| Path | Responsibility |
|---|---|
| `package.json` | Private, dev-only toolchain (E12). devDependencies + peerDependencies, no publish target |
| `package-lock.json` | Exact private toolchain resolution; committed with package.json |
| `tsconfig.json` | Strict, `noEmit`, `jsx: react-jsx`. What `npm run types:check` reads |
| `vitest.config.ts` | jsdom environment, explicit imports (no globals) |
| `resources/js/index.ts` | The public surface: `FlowEditor`, `defaultControls`, `defaultNodeRenderer`, `Unregistered`, types |
| `resources/js/types/css.d.ts` | `declare module '*.css'` so `tsc` accepts React Flow's stylesheet import |
| `resources/js/graph/types.ts` | The wire types: the five `edit()` props, the graph, the palette payload |
| `resources/js/graph/toCanvas.ts` | `Graph` → canvas nodes and edges. Pure |
| `resources/js/graph/toGraph.ts` | Canvas → `Graph`, resolving each edge's output. Pure. Reports what it could not resolve |
| `resources/js/canvas/layout.ts` | `gridPosition`, node width, output-handle offsets. Pure, no React |
| `resources/js/canvas/context.ts` | `CanvasContext` and the node-renderer types. Per-node data that `toCanvas` deliberately does not carry |
| `resources/js/canvas/NodeCard.tsx` | `defaultNodeRenderer`, `rendererFor`, and the card that owns the handles a host override must not be able to remove |
| `resources/js/canvas/Canvas.tsx` | The React Flow wrapper. Shared read-only with Plan 4's run view |
| `resources/js/controls/types.ts` | `FieldControlProps`, `FieldControl`, `ControlMap` |
| `resources/js/controls/Field.tsx` | `FieldShell` and `inputClass` — label, help and errors, once, in the host's own theme tokens |
| `resources/js/controls/index.ts` | `defaultControls`, `mergeControls`, `controlFor` |
| `resources/js/controls/Text.tsx` | `text` |
| `resources/js/controls/Number.tsx` | `number` — emits `number \| null`, never `''` |
| `resources/js/controls/Boolean.tsx` | `boolean` |
| `resources/js/controls/Select.tsx` | `select` — emits `null` for the empty choice, never `''` |
| `resources/js/controls/Multiselect.tsx` | `multiselect` — a real multi-value control (§5.7), always emits an array |
| `resources/js/controls/Duration.tsx` | `duration` — amount plus unit, emits only strings `ValidDuration` accepts |
| `resources/js/controls/Unregistered.tsx` | The named error for an unknown type. Renders no input of any kind |
| `resources/js/controls/useFieldOptions.ts` | Lazy per-`(type, field)` option fetching and its context |
| `resources/js/http.ts` | The one `fetch` helper: CSRF, headers, status-not-exception, and the options-URL template |
| `resources/js/test-setup.ts` | The DOM matchers and the three jsdom shims `@xyflow/react` needs to mount at all |
| `resources/js/editor/useAutosave.ts` | Debounce, coalesce, echo `draft_revision`, 409 conflict |
| `resources/js/editor/publish.ts` | `interpretPublish` — the two 422 shapes, and the errors that name no renderable node |
| `resources/js/editor/ids.ts` | `nextNodeId` and `canConnect` — the two silent-if-wrong decisions, kept pure |
| `resources/js/editor/FlowEditor.tsx` | All editor state; publish; error routing |
| `resources/js/editor/Palette.tsx` | Grouped node list, click to add |
| `resources/js/editor/ConfigPanel.tsx` | The selected node's fields, through `controlFor` |

Plan 4 adds `run/FlowRun.tsx` and `run/useOverlayPolling.ts` and exports `FlowRun` from `index.ts`. This plan creates neither; `canvas/` is built to be consumed read-only by them.

### Created — package PHP, tests, docs

| Path | Responsibility |
|---|---|
| `tests/Unit/DurationControlUnitsTest.php` | Reads the unit list out of `Duration.tsx` and asserts every string it can emit passes `ValidDuration` |
| `docs/08-editor-client.md` | The client's own integrator doc: five wiring steps, the thin page, both prop-merge seams |

### Modified

| Path | Change |
|---|---|
| `src/Http/Controllers/FlowEditorController.php` | `edit()` gains `urls`; prefix-aware route-name resolution |
| `tests/Feature/EditorRoutesTest.php` | `urls` in the prop-contract test; a new name-prefix test |
| `docs/02-integration.md` | `urls` in the edit-page props; rewrite "What you have not wired yet"; link to `08-editor-client.md` |
| `README.md` | Replace the stale foundation/no-UI status with the shipped editor status and verified PHP/JS counts; add the editor-client guide |
| `tests/Unit/FieldTest.php` | Characterise Laravel's array-aware `in:` validation for multiselect arrays |
| `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` | An "as built" block on §5; §3's table marks Plan 3 delivered |
| `docs/superpowers/open-issues.md` | "Last updated"; anything this plan found |

### Modified — demo app (isolated worktree at the exact path in Task 10, a separate repo and commit)

| Path | Change |
|---|---|
| `database/migrations/2026_08_18_000001_create_nodeflow_tables.php` | Align the demo's unreleased copied migration with Plan 3a's draft/revision/tenant columns |
| `tests/Feature/NodeflowEditorTest.php` | Replace prototype redirect/session expectations with authenticated Inertia/JSON graph-contract tests |
| `vite.config.ts` | The `@nodeflow/editor` alias and `resolve.dedupe` |
| `tsconfig.json` | The matching path mapping |
| `resources/css/app.css` | The Tailwind `@source` line into `vendor/` |
| `resources/js/pages/nodeflow/demo.tsx` | Narrow the small `router.post` helper payload from `unknown` to Inertia-convertible primitives |
| `routes/web.php` | `Nodeflow::routes()` replaces the two hand-rolled editor routes |
| `app/Providers/NodeflowServiceProvider.php` | Define the four gates — the demo defines none today, so every editor route 403s |
| `resources/js/pages/nodeflow/editor.tsx` | 333 lines become the thin page |
| `app/Http/Controllers/NodeflowEditorController.php` | Deleted |

---

## Chunk 1: Toolchain and graph transforms

### Task 1: The dev toolchain and the pure graph transforms

**Files:**
- Create: `package.json`, `package-lock.json`, `tsconfig.json`, `vitest.config.ts`
- Create: `resources/js/types/css.d.ts`
- Create: `resources/js/graph/types.ts`
- Create: `resources/js/canvas/layout.ts`
- Create: `resources/js/graph/toCanvas.ts`, `resources/js/graph/toGraph.ts`
- Test: `resources/js/graph/toCanvas.test.ts`, `resources/js/graph/toGraph.test.ts`

**Interfaces:**
- Consumes: nothing from earlier tasks. The wire shapes are copied from
  `src/Schema/Field.php::toWireArray()`, `src/Schema/NodeDefinition.php::toArray()`,
  `src/Schema/TriggerDefinition.php::toArray()`, `src/Nodes/NodeRegistry.php::palette()`
  and `src/Http/Controllers/FlowEditorController.php::edit()` — all verified against the source, not guessed.
- Produces:
  - Types `FieldPayload`, `NodeTypePayload`, `TriggerPayload`, `GraphNode`, `GraphEdge`, `Graph`, `FlowSummary`, `EditorUrls`, `NodeCardData`, `CanvasNode`, `CanvasEdge`, `NodeErrorEntry`, `PublishErrorBody`.
  - `gridPosition(index: number): {x: number; y: number}` and the constants `NODE_WIDTH`, `HANDLE_ROW_HEIGHT`, `outputHandleTop(index: number): number` from `canvas/layout.ts`.
  - `toCanvas(graph: Graph): {nodes: CanvasNode[]; edges: CanvasEdge[]}`.
  - `defsByType(palette: NodeTypePayload[]): Record<string, NodeTypePayload>`.
  - `resolveOutput(sourceHandle: string | null | undefined, def: NodeTypePayload | undefined): string | null`.
  - `toGraph(canvas: {nodes: CanvasNode[]; edges: CanvasEdge[]}, start: string, defs: Record<string, NodeTypePayload>): {graph: Graph; unresolved: CanvasEdge[]}`.
- Later tasks rely on: every name above. `canvas/layout.ts` is created here, not in Task 5, because `toCanvas` needs `gridPosition` and a task must not depend on a file a later task creates.

**Why `canvas/layout.ts` holds `gridPosition` even though `graph/` is meant to be the pure module:** §5.5 lists `layout.ts` under `canvas/`, and `layout.ts` is pure — no React, no `@xyflow/react`. `graph/toCanvas.ts` importing it introduces no cycle, because nothing in `canvas/layout.ts` imports from `graph/`. Keeping a second grid function inside `graph/` to avoid the import would be two sources of truth for a node's default position.

- [ ] **Step 1: Create the private, dev-only `package.json` (E12)**

Create the stable part of `package.json` first, so `npm install` can add the
machine-chosen exact `devDependencies` without leaving a placeholder in the
plan:

```json
{
    "name": "@nodeflow/editor",
    "version": "0.0.0",
    "private": true,
    "type": "module",
    "description": "Dev-only toolchain for the editor's TypeScript source. Never published; the host's Vite compiles resources/js directly (decision D2).",
    "scripts": {
        "test": "vitest run",
        "test:watch": "vitest",
        "types:check": "tsc --noEmit"
    },
    "peerDependencies": {
        "@xyflow/react": "^12.0.0",
        "react": "^18.0.0 || ^19.0.0",
        "react-dom": "^18.0.0 || ^19.0.0"
    }
}
```

There is no `main`, no `module`, no `exports` and no `files` key, and there
never will be: E12 forbids an npm publish target because publishing would reopen
the two-sources-of-truth problem D2 closed. `"private": true` is the mechanical
guard.

- [ ] **Step 2: Install the toolchain**

Run, from the package root:

```bash
npm install --save-dev --save-exact typescript vitest jsdom @vitejs/plugin-react \
  @testing-library/react @testing-library/dom @testing-library/user-event \
  react react-dom @types/react @types/react-dom @xyflow/react
```

Let npm write the versions — do not hand-pick them. `react`, `react-dom` and `@xyflow/react` are devDependencies **and** peerDependencies: dev so `tsc` and Vitest can resolve them, peer so the contract with the host is documented (E12 says peerDependencies exist for documentation).

`npm install` adds exact versions under `devDependencies` and creates
`node_modules/` and `package-lock.json`. It must leave the keys from Step 1
unchanged. `node_modules/` is already in `.gitignore` — confirm with
`rg -n 'node_modules' .gitignore` rather than adding a second line. **Commit
`package-lock.json`.**

- [ ] **Step 3: Create `tsconfig.json`**

```json
{
    "compilerOptions": {
        "target": "ES2022",
        "lib": ["ES2022", "DOM", "DOM.Iterable"],
        "module": "ESNext",
        "moduleResolution": "bundler",
        "jsx": "react-jsx",
        "strict": true,
        "noUncheckedIndexedAccess": true,
        "noImplicitOverride": true,
        "noUnusedLocals": true,
        "noUnusedParameters": true,
        "isolatedModules": true,
        "esModuleInterop": true,
        "forceConsistentCasingInFileNames": true,
        "skipLibCheck": true,
        "noEmit": true,
        "types": ["vitest/globals"]
    },
    "include": ["resources/js/**/*.ts", "resources/js/**/*.tsx", "vitest.config.ts"]
}
```

`noUncheckedIndexedAccess` is on deliberately: this code indexes into palette maps and `node_errors` arrays constantly, and the whole point of the "a `node_errors` entry may name a node that does not exist" rule is that an index can miss. The compiler should say so.

`"types": ["vitest/globals"]` is present but `globals` stays **off** in `vitest.config.ts`; tests import `describe`/`it`/`expect` explicitly. The types entry is what lets `tsc` accept `expect.extend`-style augmentation if a later task needs it. If `tsc --noEmit` complains about it, delete the line rather than turning globals on.

- [ ] **Step 4: Create `vitest.config.ts`**

```ts
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        globals: false,
        include: ['resources/js/**/*.test.{ts,tsx}'],
        restoreMocks: true,
        clearMocks: true,
    },
})
```

`restoreMocks` and `clearMocks` matter: Tasks 4, 6 and 7 stub `globalThis.fetch`, and a leaked stub between files produces a green suite that tests the previous file's fake.

- [ ] **Step 5: Create `resources/js/types/css.d.ts`**

```ts
/**
 * `canvas/Canvas.tsx` imports React Flow's own stylesheet, so the host does not
 * have to remember a sixth wiring step to make the canvas visible. Vite handles
 * the import; `tsc` needs to be told the module exists.
 */
declare module '*.css'
```

- [ ] **Step 6: Create `resources/js/graph/types.ts`**

Every shape here is transcribed from PHP, with the source noted. Do not add fields the server does not send.

```ts
/**
 * The wire shapes. Each is transcribed from the PHP that produces it, named in
 * the comment above it, and nothing here is inferred. When a shape looks odd —
 * `options` always an object, `output` nullable on a draft — the oddness is the
 * contract and the reason is recorded in `docs/02-integration.md`.
 */

/** src/Schema/Field.php::toWireArray(). `options` is cast to an object there, so it is never `[]`. */
export type FieldPayload = {
    key: string
    /**
     * The enum value for a built-in (`text`, `number`, `boolean`, `select`,
     * `multiselect`, `duration`) or the host's own string for a
     * `Field::custom()` type. A custom type reaches us here and nowhere else,
     * which is why an unmatched type must render a named error rather than a
     * text input: `Field::custom()` sets the PHP enum to Text internally, so a
     * text fallback would look correct and be wrong.
     */
    type: string
    label: string
    help: string | null
    default: unknown
    required: boolean
    options: Record<string, string>
    /** True when the field declares optionsFrom(). The class name is deliberately not sent (E6). */
    dynamic_options: boolean
}

/**
 * PHP uses one array type for both maps and lists. An empty node config or
 * defaultConfig() therefore reaches JSON as `[]`, while a keyed config reaches
 * it as an object. The canvas normalises the list form to an empty object before
 * controls receive it.
 */
export type GraphConfig = Record<string, unknown> | unknown[]

/** src/Nodes/NodeRegistry.php::palette() over src/Schema/NodeDefinition.php::toArray(). */
export type NodeTypePayload = {
    type: string
    label: string
    group: string
    icon: string | null
    description: string | null
    outputs: string[]
    fields: FieldPayload[]
    default_config: GraphConfig
    cardinality: ('subject' | 'audience')[]
}

/** src/Triggers/TriggerRegistry.php::palette() over src/Schema/TriggerDefinition.php::toArray(). Narrower than a node: no group, icon or outputs. */
export type TriggerPayload = {
    type: string
    label: string
    description: string | null
    fields: FieldPayload[]
}

export type GraphNode = {
    id: string
    type: string
    /** Optional/nullable on a structurally valid draft; toCanvas normalises it to {}. */
    config?: GraphConfig | null
    /** The package round-trips this untouched and ignores it. Absent for a graph published without a canvas. */
    position?: { x: number; y: number }
}

export type GraphEdge = {
    from: string
    to: string
    /**
     * Null only ever means "the author drew this connection and the editor could
     * not tell which output it leaves from". The draft endpoint accepts null so
     * the connection is not silently discarded; publish refuses to send one.
     * Never substitute a default — see resolveOutput().
     */
    output?: string | null
}

/**
 * The graph exactly as the draft endpoint may store and edit() may return it.
 * Laravel's structural rules permit these container keys to be absent or null;
 * toCanvas() is the normalisation boundary and toGraph() always emits the full
 * non-null shape.
 */
export type Graph = {
    start?: string | null
    nodes?: GraphNode[] | null
    edges?: GraphEdge[] | null
}

/** src/Http/Controllers/FlowEditorController.php::edit(). */
export type FlowSummary = {
    id: number
    name: string
    trigger_type: string
    status: string
    /** The published version number. Keeps reporting the published number while a draft is shown. */
    version: number | null
    /** The concurrency token. 0 for a flow that has never had a draft saved. */
    draft_revision: number
    /** Display only — "last saved 3 minutes ago". Never a staleness token. */
    draft_updated_at: string | null
}

/** Added by Task 2. `options` is a template carrying two sentinel placeholders. */
export type EditorUrls = { draft: string; publish: string; options: string }

/** One entry of publish's semantic-422 `node_errors`. */
export type NodeErrorEntry = {
    /** Null for a graph-level failure such as a cycle, and possibly an id with no node in the graph. */
    node: string | null
    field: string | null
    message: string
}

/**
 * Publish's 422 body, in either of its two shapes. `node_errors` present means
 * semantic and `errors` is a flat array; absent means Laravel's own structural
 * validation and `errors` is a field-keyed object. Tell them apart by the key,
 * never by the type of `errors`. Exported intentionally for a host wrapper that
 * wants to type its own publish diagnostics; the internal interpreter also
 * remains free to change because it consumes HttpResult rather than this alias.
 */
export type PublishErrorBody = {
    message?: string
    errors?: string[] | Record<string, string[]>
    node_errors?: NodeErrorEntry[]
}

/** What a node carries on the canvas. Deliberately minimal: the definition, the errors and the renderer overrides come from CanvasContext, so toCanvas() stays pure and independent of the palette. */
export type NodeCardData = {
    id: string
    type: string
    config: Record<string, unknown>
    isStart: boolean
}

/**
 * Structurally assignable to @xyflow/react's Node and Edge, without importing
 * them: `graph/` is the Vitest target and stays free of React Flow entirely.
 * Canvas.tsx does the (checked) hand-off.
 */
export type CanvasNode = {
    id: string
    type: 'nodeflowNode'
    position: { x: number; y: number }
    data: NodeCardData
}

export type CanvasEdge = {
    id: string
    source: string
    sourceHandle: string | null
    target: string
    label?: string
}
```

- [ ] **Step 7: Write the failing tests for `toCanvas`**

Create `resources/js/graph/toCanvas.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { toCanvas } from './toCanvas'
import type { Graph } from './types'

const graph = {
    start: 'n1',
    nodes: [
        { id: 'n1', type: 'app.send', config: { template: 'welcome' }, position: { x: 40, y: 80 } },
        { id: 'n2', type: 'core.exit', config: {} },
    ],
    edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
} satisfies Graph

describe('toCanvas', () => {
    // Counterfactual: drop `position: n.position ?? gridPosition(i)` and n1 lands
    // on the grid instead of where the author left it.
    it('keeps a position the author already set', () => {
        expect(toCanvas(graph).nodes[0]?.position).toEqual({ x: 40, y: 80 })
    })

    // Counterfactual: return `position: n.position` and React Flow stacks every
    // positionless node at the origin, which looks like one node.
    it('invents a deterministic position for a node that has none', () => {
        const first = toCanvas(graph).nodes[1]?.position
        const again = toCanvas(graph).nodes[1]?.position

        expect(first).toEqual({ x: 300, y: 60 })
        expect(first).toEqual(again)
    })

    // Counterfactual: set isStart on every node, or on none, and the START badge
    // stops meaning anything.
    it('marks exactly the start node', () => {
        expect(toCanvas(graph).nodes.map((n) => n.data.isStart)).toEqual([true, false])
    })

    // The draft endpoint accepts absent/null containers, config and output.
    // Counterfactual: map any of them without `??` normalisation and this throws,
    // produces undefined card config, or gives React Flow an undefined handle.
    it('normalises every nullable or omitted draft shape at the canvas boundary', () => {
        const bare: Graph = {
            start: null,
            nodes: [
                { id: 'x', type: 't', config: [] },
                { id: 'y', type: 't', config: null },
                { id: 'z', type: 't' },
            ],
            edges: [{ from: 'x', to: 'y' }],
        }

        expect(toCanvas(bare)).toMatchObject({
            nodes: [
                { data: { config: {}, isStart: false } },
                { data: { config: {}, isStart: false } },
                { data: { config: {}, isStart: false } },
            ],
            edges: [{ sourceHandle: null }],
        })
        expect(toCanvas({ start: null, nodes: null, edges: null })).toEqual({ nodes: [], edges: [] })
    })

    // Counterfactual: map `output` to anything but sourceHandle and a reloaded
    // graph's edges attach to the wrong handle, or to none.
    it('maps an edge output onto the source handle', () => {
        const edge = toCanvas(graph).edges[0]

        expect(edge?.source).toBe('n1')
        expect(edge?.sourceHandle).toBe('sent')
        expect(edge?.target).toBe('n2')
        expect(edge?.label).toBe('sent')
    })

    // Counterfactual: drop the index from the id and two byte-identical draft
    // edges collide, so React Flow renders one. (A broken draft may contain this
    // duplicate even though publish will reject it semantically.)
    it('gives two edges between the same pair distinct ids', () => {
        const twoOutputs: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 't', config: {} }, { id: 'b', type: 't', config: {} }],
            edges: [{ from: 'a', to: 'b', output: 'yes' }, { from: 'a', to: 'b', output: 'yes' }],
        }

        const ids = toCanvas(twoOutputs).edges.map((e) => e.id)

        expect(new Set(ids).size).toBe(2)
    })

    // Counterfactual: emit sourceHandle: '' for a null output and React Flow
    // silently attaches the edge to the node's first handle, which is a different
    // output from the one the author drew.
    it('carries a null output through as a null handle', () => {
        const unresolved: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 't', config: {} }, { id: 'b', type: 't', config: {} }],
            edges: [{ from: 'a', to: 'b', output: null }],
        }

        expect(toCanvas(unresolved).edges[0]?.sourceHandle).toBeNull()
    })
})
```

- [ ] **Step 8: Run the tests and confirm they fail for the right reason**

```bash
npm test -- resources/js/graph/toCanvas.test.ts
```

Expected: the test file fails before collection on `Failed to resolve import
"./toCanvas"`. No case can run until the production module exists.

- [ ] **Step 9: Create `resources/js/canvas/layout.ts`**

```ts
/**
 * Canvas geometry, kept pure so both `graph/toCanvas.ts` and `NodeCard.tsx` can
 * import it and Vitest can test it without a DOM.
 */

export const NODE_WIDTH = 208

/** One row per declared output, so a handle's label has somewhere to sit. */
export const HANDLE_ROW_HEIGHT = 20

const COLUMNS = 4
const COLUMN_GAP = 240
const ROW_GAP = 160
const ORIGIN = { x: 60, y: 60 }

/**
 * Where a node with no stored position goes.
 *
 * Deterministic on the index alone: two renders of the same graph must place a
 * positionless node identically, or an autosave triggered by nothing but a
 * re-render would write a different graph and mint a new draft revision.
 */
export function gridPosition(index: number): { x: number; y: number } {
    return {
        x: ORIGIN.x + (index % COLUMNS) * COLUMN_GAP,
        y: ORIGIN.y + Math.floor(index / COLUMNS) * ROW_GAP,
    }
}

/** The vertical offset of the nth output handle, measured from the card's top. */
export function outputHandleTop(index: number): number {
    return 56 + index * HANDLE_ROW_HEIGHT
}
```

- [ ] **Step 10: Create `resources/js/graph/toCanvas.ts`**

```ts
import { gridPosition } from '../canvas/layout'
import type { CanvasEdge, CanvasNode, Graph, GraphNode } from './types'

/** PHP's empty config array serialises as []; controls need a keyed object. */
function toConfig(config: GraphNode['config']): Record<string, unknown> {
    return config !== null && config !== undefined && !Array.isArray(config) ? config : {}
}


/**
 * A stored graph as React Flow wants it.
 *
 * Pure, and deliberately ignorant of the palette: a node's definition, its
 * errors and any host renderer override are supplied by CanvasContext at render
 * time, so this function has one input and one output and the round-trip test
 * means what it says.
 */
export function toCanvas(graph: Graph): { nodes: CanvasNode[]; edges: CanvasEdge[] } {
    return {
        nodes: (graph.nodes ?? []).map((node, index) => ({
            id: node.id,
            type: 'nodeflowNode' as const,
            position: node.position ?? gridPosition(index),
            data: {
                id: node.id,
                type: node.type,
                config: toConfig(node.config),
                isStart: (graph.start ?? '') === node.id,
            },
        })),
        // The index is in the id because a node with two outputs wired to the
        // same target produces two edges that are otherwise identical, and React
        // Flow drops duplicate ids.
        edges: (graph.edges ?? []).map((edge, index) => ({
            id: `nf${index}-${edge.from}-${edge.output ?? ''}-${edge.to}`,
            source: edge.from,
            sourceHandle: edge.output ?? null,
            target: edge.to,
            label: edge.output ?? undefined,
        })),
    }
}
```

- [ ] **Step 11: Run the tests, type check, and PHP regression suite**

```bash
npm test -- resources/js/graph/toCanvas.test.ts && npm run types:check && vendor/bin/pest
```

Expected: 7 JS tests pass, `tsc` is silent, and all 319 PHP tests pass.

- [ ] **Step 12: Commit**

```bash
git add package.json package-lock.json tsconfig.json vitest.config.ts resources/js
git commit -m "feat: add the editor's dev-only JS toolchain and the graph-to-canvas transform"
```

- [ ] **Step 13: Write the failing tests for `toGraph` — including the prototype's latent bug**

Create `resources/js/graph/toGraph.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import { toCanvas } from './toCanvas'
import { defsByType, resolveOutput, toGraph } from './toGraph'
import type { Graph, NodeTypePayload } from './types'

function def(type: string, outputs: string[]): NodeTypePayload {
    return {
        type,
        label: type,
        group: 'General',
        icon: null,
        description: null,
        outputs,
        fields: [],
        default_config: {},
        cardinality: ['subject'],
    }
}

const defs = defsByType([def('app.send', ['sent', 'failed']), def('core.exit', []), def('one.out', ['default'])])

const graph = {
    start: 'n1',
    nodes: [
        { id: 'n1', type: 'app.send', config: { template: 'welcome', count: 3 }, position: { x: 40, y: 80 } },
        { id: 'n2', type: 'core.exit', config: {}, position: { x: 300, y: 80 } },
    ],
    edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
}

describe('toGraph', () => {
    // The round-trip case §9 asks for, with positions present on every node so
    // the assertion is identity rather than "close enough".
    // Counterfactual: drop `position` from the emitted node, or drop `config`, or
    // emit `start` from anywhere but the argument, and this fails.
    it('round-trips start, ids, config, position and edge outputs', () => {
        const { graph: out, unresolved } = toGraph(toCanvas(graph), graph.start ?? '', defs)

        expect(out).toEqual(graph)
        expect(unresolved).toEqual([])
    })

    // The binding contract says canvas positions round-trip untouched.
    // Counterfactual: round here and a fractional position is silently changed
    // just by loading and serialising the graph.
    it('preserves fractional positions exactly', () => {
        const canvas = toCanvas(graph)
        canvas.nodes[0]!.position = { x: 40.4, y: 80.6 }

        expect(toGraph(canvas, 'n1', defs).graph.nodes?.[0]?.position).toEqual({ x: 40.4, y: 80.6 })
    })

    // THE PROTOTYPE'S BUG, pinned. `~/Sites/test-workflow`'s editor.tsx did
    // `output: e.sourceHandle ?? 'default'`, so a dropped handle published an
    // edge naming an output app.send never declared, and the server rejected it
    // with a message about an output the author never chose.
    // Counterfactual: restore `?? 'default'` and this fails on both assertions.
    it('never invents an output for a handle it cannot resolve', () => {
        const canvas = toCanvas(graph)
        canvas.edges[0]!.sourceHandle = null

        const { graph: out, unresolved } = toGraph(canvas, 'n1', defs)

        expect(out.edges?.[0]?.output).toBeNull()
        expect(unresolved).toHaveLength(1)
        expect(unresolved[0]?.id).toBe(canvas.edges[0]!.id)
    })

    // Counterfactual: return null for every unhandled edge and a node with a
    // single output — the common case — needs a handle click it has no reason to
    // need, and every such draft blocks publish.
    it('resolves a missing handle when the node declares exactly one output', () => {
        const single: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 'one.out', config: {}, position: { x: 0, y: 0 } },
                    { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } }],
            edges: [{ from: 'a', to: 'b', output: null }],
        }

        const { graph: out, unresolved } = toGraph(toCanvas(single), 'a', defs)

        expect(out.edges?.[0]?.output).toBe('default')
        expect(unresolved).toEqual([])
    })

    // A draft may reference a type the host has not registered — that is legal,
    // and publish is where it is caught. Counterfactual: substitute a known
    // definition (or its first output) for a missing lookup and this emits a
    // plausible output instead of preserving a savable unresolved edge.
    it('leaves an edge unresolved when the source node type is not in the palette', () => {
        const unknown: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 'not.registered', config: {}, position: { x: 0, y: 0 } },
                    { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } }],
            edges: [{ from: 'a', to: 'b', output: null }],
        }

        const { graph: out, unresolved } = toGraph(toCanvas(unknown), 'a', defs)

        expect(out.edges?.[0]?.output).toBeNull()
        expect(unresolved).toHaveLength(1)
    })

    // The draft endpoint accepts an omitted output as well as null. toCanvas is
    // the normalisation boundary. Counterfactual: assign `sourceHandle:
    // edge.output` without `?? null` and the first assertion sees undefined;
    // the canvas no longer has the one documented representation for an
    // unresolved handle.
    it('normalises an omitted output to null and reports it unresolved', () => {
        const omitted: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 'app.send', config: {} }, { id: 'b', type: 'core.exit', config: {} }],
            edges: [{ from: 'a', to: 'b' }],
        }

        const canvas = toCanvas(omitted)
        expect(canvas.edges[0]?.sourceHandle).toBeNull()

        const { graph: out, unresolved } = toGraph(canvas, 'a', defs)

        expect(out.edges?.[0]?.output).toBeNull()
        expect(unresolved).toHaveLength(1)
    })
})

describe('resolveOutput', () => {
    // Counterfactual: treat '' as a handle name and the edge publishes with an
    // empty output, which GraphValidator reports as an unknown output ''.
    it('treats an empty handle as no handle', () => {
        expect(resolveOutput('', def('t', ['a', 'b']))).toBeNull()
    })

    // Counterfactual: ignore the actual handle and choose outputs[0], and a
    // connection drawn from `failed` silently becomes `sent`.
    it('prefers the handle the author actually used', () => {
        expect(resolveOutput('failed', def('t', ['sent', 'failed']))).toBe('failed')
    })

    // Counterfactual: fall through to outputs[0] and a terminal node's stray
    // edge publishes an output that does not exist.
    it('resolves nothing for a node that declares no outputs', () => {
        expect(resolveOutput(null, def('t', []))).toBeNull()
    })
})
```

- [ ] **Step 14: Run the tests and confirm they fail**

```bash
npm test -- resources/js/graph/toGraph.test.ts
```

Expected: the test file fails before collection on `Failed to resolve import
"./toGraph"`.

- [ ] **Step 15: Create `resources/js/graph/toGraph.ts`**

```ts
import type { CanvasEdge, CanvasNode, Graph, NodeTypePayload } from './types'

/** The palette as a lookup. One place builds it, so one place decides what a missing type means. */
export function defsByType(palette: NodeTypePayload[]): Record<string, NodeTypePayload> {
    return Object.fromEntries(palette.map((entry) => [entry.type, entry]))
}

/**
 * Which declared output an edge leaves from.
 *
 * The handle the author dragged from wins. When there is no handle — a graph
 * loaded with a null output, or a connection React Flow could not attribute —
 * a node declaring exactly one output has only one possible answer and gets it.
 * Anything else returns null, and null propagates: the draft stores it, publish
 * refuses to send it, and the author is told which connection is ambiguous.
 *
 * The one thing this must never do is substitute a plausible default. The
 * throwaway prototype did `sourceHandle ?? 'default'`, which published an edge
 * naming an output the node never declared; the server then rejected the graph
 * with a message about an output the author had never chosen. A confusing
 * symptom for a trivial cause, which is why it is pinned by a unit test.
 */
export function resolveOutput(sourceHandle: string | null | undefined, def: NodeTypePayload | undefined): string | null {
    if (sourceHandle !== null && sourceHandle !== undefined && sourceHandle !== '') {
        return sourceHandle
    }

    const outputs = def?.outputs ?? []

    return outputs.length === 1 ? outputs[0]! : null
}

/**
 * The canvas as a stored graph.
 *
 * `unresolved` is not an error list — it is the set of edges whose output could
 * not be determined. A draft saves with them (the endpoint accepts a null
 * output for exactly this reason, so an author's connection is never discarded);
 * publish must refuse while it is non-empty.
 */
export function toGraph(
    canvas: { nodes: CanvasNode[]; edges: CanvasEdge[] },
    start: string,
    defs: Record<string, NodeTypePayload>,
): { graph: Graph; unresolved: CanvasEdge[] } {
    const typeOf = new Map(canvas.nodes.map((node) => [node.id, node.data.type]))
    const unresolved: CanvasEdge[] = []

    const edges = canvas.edges.map((edge) => {
        const output = resolveOutput(edge.sourceHandle, defs[typeOf.get(edge.source) ?? ''])

        if (output === null) {
            unresolved.push(edge)
        }

        return { from: edge.source, to: edge.target, output }
    })

    return {
        graph: {
            start,
            nodes: canvas.nodes.map((node) => ({
                id: node.id,
                type: node.data.type,
                config: node.data.config,
                // Positions are a stored client concern that the package promises
                // to round-trip untouched. A fractional coordinate is data, not
                // noise to normalise away.
                position: { x: node.position.x, y: node.position.y },
            })),
            edges,
        },
        unresolved,
    }
}
```

- [ ] **Step 16: Run the whole JS suite, type check, and PHP regression suite**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 16 passing across two files, `tsc` silent, and all 319 PHP tests pass.

- [ ] **Step 17: Close the prototype bug by experiment**

Change `resolveOutput`'s last line to `return outputs.length === 1 ? outputs[0]! : 'default'`, run `npm test`, and confirm **`never invents an output for a handle it cannot resolve` fails**. Restore the line and confirm green. Record both results in the task report — the discipline is that a finding proven by experiment is closed by experiment.

- [ ] **Step 18: Re-run all three gates after restoring the mutation**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 16 Vitest tests, silent `tsc`, and all 319 PHP tests pass on the exact tree being committed.

- [ ] **Step 19: Commit**

```bash
git add resources/js/graph
git commit -m "feat: convert the canvas back to a graph without inventing edge outputs"
```

---

## Chunk 2: Server URL delivery

### Task 2: The server hands the client its endpoint URLs

**Files:**
- Modify: `src/Http/Controllers/FlowEditorController.php` (`edit()`, two sentinel constants, and one private route-name helper)
- Modify: `tests/Feature/EditorRoutesTest.php`
- Modify: `docs/02-integration.md` ("The edit page" props block)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: an `urls` prop on `GET flows/{flow}/edit` — `{draft: string, publish: string, options: string}`. Task 4's `useFieldOptions` consumes `options`, a URL template containing the literal substrings `__NODEFLOW_TYPE__` and `__NODEFLOW_FIELD__`; Task 6's `useAutosave` consumes `draft`; Task 8's `FlowEditor` consumes `publish`. The two sentinel constants are also referenced by `docs/08-editor-client.md` in Task 9.

**Carve-out from the "no test edits" constraint:** `tests/Feature/EditorRoutesTest.php`'s `it('renders the editor page with the props the client is written against', ...)` gains assertions for the new prop. That test *is* the prop contract, and this task changes the contract deliberately — it is not a test catching a mistake. No assertion in it is removed or weakened.

**Why not `route('nodeflow.flows.draft', $flow)` directly:** a host writing
`Route::name('admin.')->group(fn () => Nodeflow::routes())` — the same shape the demo app already uses for its own routes, `Route::prefix('nodeflow')->name('nodeflow.')->group(...)` — makes the registered name `admin.nodeflow.flows.draft`, and the bare lookup throws `RouteNotFoundException` from inside a package the host cannot see into. Deriving the prefix from the current route's name costs ten lines and removes the whole failure mode.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/EditorRoutesTest.php`:

```php
it('hands the client the urls for its own endpoints', function () {
    // The client cannot build these itself: Nodeflow::routes() is called inside
    // the host's own group, so prefix and middleware are the host's choice (E4).
    // Counterfactual: drop the `urls` prop and every assertion here fails; the
    // throwaway prototype hardcoded '/nodeflow/flows/{id}/publish' instead, which
    // is exactly what this prop exists to prevent.
    allowEverything();

    $response = editPage($this, $this->flow->id);

    $response->assertJsonPath('props.urls.draft', "http://localhost/nodeflow/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.publish', "http://localhost/nodeflow/flows/{$this->flow->id}/publish");

    // A template, not a URL: the client substitutes the node type and field key
    // when it renders a dynamic field. The sentinels are made of unreserved
    // characters so route() cannot re-encode them out from under the client.
    expect($response->json('props.urls.options'))->toBe(
        "http://localhost/nodeflow/flows/{$this->flow->id}/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options"
    );
});

it('resolves its urls through the hosts own route name prefix', function () {
    // Route::name('admin.')->group(fn () => Nodeflow::routes()) is an ordinary
    // Laravel pattern — the demo app uses it for its own routes — and it renames
    // every route in this package. Counterfactual: call route('nodeflow.flows.draft')
    // directly and this test dies on RouteNotFoundException instead of asserting.
    allowEverything();

    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Nodeflow::routes());

    $response = $this->actingAs($this->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->get("/admin/flows/{$this->flow->id}/edit");

    $response->assertOk()
        ->assertJsonPath('props.urls.draft', "http://localhost/admin/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.publish', "http://localhost/admin/flows/{$this->flow->id}/publish")
        ->assertJsonPath(
            'props.urls.options',
            "http://localhost/admin/flows/{$this->flow->id}/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options"
        );
});
```

Then extend the existing prop-contract test. Find

```php
        ->assertJsonPath('props.flow.draft_updated_at', null)
```

and add immediately after it:

```php
        // The client's endpoints travel with the props, because it cannot build
        // them: the host chose the prefix.
        ->assertJsonPath('props.urls.draft', "http://localhost/nodeflow/flows/{$this->flow->id}/draft")
```

Assert the anchor is present and unique before editing:

```bash
rg -c "assertJsonPath\('props.flow.draft_updated_at', null\)" tests/Feature/EditorRoutesTest.php
```

Expected: `1`. Any other number means stop and re-read the file.

- [ ] **Step 2: Run the tests and confirm they fail**

```bash
vendor/bin/pest --filter='urls'
```

Expected: two failures (the two test names containing `urls`), each reporting a
missing `props.urls` path — not an error about a missing route or method. The
existing prop-contract test also fails on a full run, but this filter does not
select its name.

- [ ] **Step 3: Add the `urls` prop and its helpers**

In `src/Http/Controllers/FlowEditorController.php`, change `edit()`'s signature to take the request:

```php
    public function edit(Request $request, Flow $flow): \Inertia\Response
```

and add the prop after `triggers`:

```php
            'triggers' => app(TriggerRegistry::class)->palette(),
            // The client cannot build these. Nodeflow::routes() is called inside
            // the host's own group (E4), so the prefix is the host's and the
            // route names may carry the host's own name prefix too — see
            // routeName(). Handing them over is what stops a client hardcoding
            // '/nodeflow/flows/{id}/publish', which is what the throwaway
            // prototype did and what broke the moment a prefix changed.
            'urls' => [
                'draft' => route($this->routeName($request, 'nodeflow.flows.draft'), ['flow' => $flow]),
                'publish' => route($this->routeName($request, 'nodeflow.flows.publish'), ['flow' => $flow]),
                'options' => route($this->routeName($request, 'nodeflow.fields.options'), [
                    'flow' => $flow,
                    'type' => self::TYPE_PLACEHOLDER,
                    'field' => self::FIELD_PLACEHOLDER,
                ]),
            ],
```

Add the constants and the helper to the class:

```php
    /**
     * Placeholders the client substitutes into the options URL.
     *
     * The options endpoint is keyed by (node type, field key) and resolves
     * lazily, per field, as the editor renders it — so there is one URL per
     * field, not one URL. Rather than teach the client the path, it gets the
     * path with two holes in it.
     *
     * Both sentinels are made only of unreserved URI characters, so route()'s
     * own encoding cannot alter them and the client's str_replace equivalent
     * cannot miss. A percent-encoded placeholder would have arrived as
     * %7Btype%7D and silently failed to match.
     */
    private const TYPE_PLACEHOLDER = '__NODEFLOW_TYPE__';

    private const FIELD_PLACEHOLDER = '__NODEFLOW_FIELD__';

    /**
     * A package route's name as it is actually registered in this host.
     *
     * Nodeflow::routes() runs inside the host's group, and a group may carry a
     * name prefix: Route::name('admin.')->group(fn () => Nodeflow::routes())
     * registers 'admin.nodeflow.flows.draft'. A bare route('nodeflow.flows.draft')
     * would then throw RouteNotFoundException from inside a package the host
     * cannot see into, on an ordinary Laravel pattern — the demo application
     * already groups its own nodeflow routes with ->name('nodeflow.').
     *
     * The prefix is whatever the currently-matched route's name carries in front
     * of its own suffix. This request reached this method through
     * nodeflow.flows.edit, so anything before that is the host's prefix and
     * applies equally to its siblings, which were registered in the same group.
     */
    private function routeName(Request $request, string $name): string
    {
        $current = $request->route()?->getName();
        $own = 'nodeflow.flows.edit';

        if ($current !== null && $current !== $own && str_ends_with($current, $own)) {
            return substr($current, 0, -strlen($own)).$name;
        }

        return $name;
    }
```

- [ ] **Step 4: Run the tests and confirm they pass**

```bash
vendor/bin/pest --filter='urls' && vendor/bin/pest
```

Expected: the two filtered tests pass, and the full suite is 321 passing (319 +
the two new tests + no losses). If any pre-existing test now fails, stop:
`edit()`'s signature changed, and something else calls it.

- [ ] **Step 5: Close the name-prefix finding by experiment**

Replace `route($this->routeName($request, 'nodeflow.flows.draft'), ...)` with `route('nodeflow.flows.draft', ...)`, run `vendor/bin/pest --filter='route name prefix'`, and confirm it fails with `RouteNotFoundException`. Restore and confirm green. Report both.

- [ ] **Step 6: Update `docs/02-integration.md`**

In the "The edit page" props block, after the `"triggers"` line, add:

```jsonc
  "urls": {
    "draft":   "https://app.test/admin/flows/12/draft",
    "publish": "https://app.test/admin/flows/12/publish",
    // A template. Substitute the node type and field key, URL-encoded.
    "options": "https://app.test/admin/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options"
  }
```

and below the block, add:

> **`urls` is where the client's endpoints come from.** You chose the prefix and
> the middleware, so the client cannot construct them — and it must not try. The
> URLs are resolved through the route *names*, so they are correct even if you
> registered `Nodeflow::routes()` inside a group carrying its own `->name()`
> prefix. `urls.options` is a template: replace `__NODEFLOW_TYPE__` and
> `__NODEFLOW_FIELD__` with the URL-encoded node type and field key. Both
> sentinels are made of characters no URL encoder will touch.

Verify the anchor before editing:

```bash
rg -n '"triggers": \[' docs/02-integration.md
```

Expected: exactly one hit, inside the edit-page props block.

- [ ] **Step 7: Run all three suites after the documentation edit**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 16 Vitest tests pass, `tsc` is silent, and all 321 PHP tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/FlowEditorController.php tests/Feature/EditorRoutesTest.php docs/02-integration.md
git commit -m "feat: hand the editor client its endpoint urls, prefix-aware"
```

---

## Chunk 3: Field controls and duration boundary

### Task 3: The six field controls, the unregistered-type error, and the merge

**Files:**
- Create: `resources/js/controls/types.ts`, `resources/js/controls/Field.tsx`, `resources/js/controls/index.ts`
- Create: `resources/js/controls/Text.tsx`, `Number.tsx`, `Boolean.tsx`, `Select.tsx`, `Multiselect.tsx`, `Duration.tsx`, `Unregistered.tsx`
- Create: `resources/js/test-setup.ts`
- Modify: `vitest.config.ts` (add `setupFiles`)
- Modify: `package.json`, `package-lock.json` (install the DOM matchers)
- Modify/Test: `tests/Unit/FieldTest.php` (characterise Laravel's array-aware `in:` rule for multiselect)
- Test: `resources/js/controls/controls.test.tsx`
- Test: `tests/Unit/DurationControlUnitsTest.php`

**Interfaces:**
- Consumes: `FieldPayload` from `graph/types.ts` (Task 1).
- Produces:
  - `FieldControlProps` - exactly the six keys 5.7 specifies: `field`, `value`, `onChange`, `errors`, `options`, `optionsLoading`.
  - `type FieldControl = (props: FieldControlProps) => ReactElement | null`, `type ControlMap = Record<string, FieldControl>`.
  - `defaultControls: ControlMap` keyed `text`, `number`, `boolean`, `select`, `multiselect`, `duration`.
  - `mergeControls(overrides?: ControlMap): ControlMap`.
  - `controlFor(type: string, controls: ControlMap): FieldControl`.
  - `Unregistered: FieldControl`.
  - `DURATION_UNITS`, `MAX_DURATION_AMOUNT`, `parseAmount(raw)`, `formatDuration(amount, unit)`, `parseDuration(value)` from `Duration.tsx`.
  - `FieldShell` and `inputClass` from `Field.tsx`.
- Later tasks rely on: `ConfigPanel.tsx` (Task 8) calls `controlFor`; `index.ts` (Task 8) re-exports `defaultControls` and `Unregistered`.

**The two hard rules, both from 10's error table:**

1. An unmatched field type renders a **named error and no input of any kind**. `Field::custom('destination', 'town')` sets the PHP enum to `Text` internally while sending `type: 'town'` on the wire, so a text fallback would look right and be wrong: a town picker degraded to free text passes `string` validation and reaches a node as garbage.
2. A control never emits `''` where the server's rules will read it as a value. `Field::rules()` adds `in:<keys>` to a select with static options, and `nullable` does not exempt `''` from `in:` - so an empty select must emit `null`. The same reasoning makes `number` emit `null` rather than `''` for an empty box.

**The duration unit list and amount range are verified, not assumed.** The
control emits positive integers from 1 through 999, and the PHP boundary test
reads both that maximum and every candidate unit from `Duration.tsx`, then runs
every combination through `ValidDuration::seconds()`. `seconds`, `minutes`,
`hours`, `days` and `weeks` all resolve positively. `months` also parses - but
`CarbonInterval::fromString('1 months')` resolves to **28 days**, which would
silently mislead an author writing a monthly follow-up, so `months` is excluded.
`'0 days'` resolves to 0, which `ValidDuration` rejects, and exponent/decimal
syntax is refused before formatting.

- [ ] **Step 1: Add the DOM matchers and the setup file**

```bash
npm install --save-dev --save-exact @testing-library/jest-dom
```

Create `resources/js/test-setup.ts`:

```ts
import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach } from 'vitest'

// globals:false means Testing Library cannot discover a global afterEach and
// therefore cannot auto-register cleanup. Without this, mounted controls,
// canvases, effects and autosave timers leak into the next case.
afterEach(cleanup)
```

Add to `vitest.config.ts`'s `test` block, beside `environment`:

```ts
        setupFiles: ['resources/js/test-setup.ts'],
```

- [ ] **Step 2: Write the failing tests**

Create `resources/js/controls/controls.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { DURATION_UNITS, formatDuration, parseAmount, parseDuration } from './Duration'
import { controlFor, defaultControls, mergeControls } from './index'
import { Unregistered } from './Unregistered'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return {
        key: 'template',
        type: 'text',
        label: 'Template',
        help: null,
        default: null,
        required: false,
        options: {},
        dynamic_options: false,
        ...overrides,
    }
}

type Extra = { errors: string[]; options: Record<string, string>; optionsLoading: boolean }

function renderControl(f: FieldPayload, value: unknown, onChange = vi.fn(), extra: Partial<Extra> = {}) {
    const Control = controlFor(f.type, mergeControls())

    render(
        <Control
            field={f}
            value={value}
            onChange={onChange}
            errors={extra.errors ?? []}
            options={extra.options ?? f.options}
            optionsLoading={extra.optionsLoading ?? false}
        />,
    )

    return onChange
}

describe('control selection', () => {
    // Counterfactual: key defaultControls on anything but the wire `type` string
    // and every field falls through to Unregistered.
    it('has a built-in for each of the six field types and no more', () => {
        expect(Object.keys(defaultControls).sort()).toEqual(['boolean', 'duration', 'multiselect', 'number', 'select', 'text'])
    })

    // Counterfactual: return a text control as the fallback and a town picker
    // becomes a free-text box that passes `string` validation.
    it('falls back to Unregistered for a type nothing registered', () => {
        expect(controlFor('town', mergeControls())).toBe(Unregistered)
    })

    // Counterfactual: spread the overrides before the defaults in mergeControls
    // and a host can never replace a built-in.
    it('lets a host override a built-in as well as add a custom type', () => {
        const Mine = () => null

        expect(controlFor('town', mergeControls({ town: Mine }))).toBe(Mine)
        expect(controlFor('text', mergeControls({ text: Mine }))).toBe(Mine)
    })
})

describe('Unregistered', () => {
    // The rule from 10, asserted both ways: the type must be named, and there
    // must be nothing an author could type into.
    // Counterfactual: render an <input> alongside the message and the second
    // assertion fails - which is the whole point of the control.
    it('names the missing type and renders no input at all', () => {
        const { container } = render(
            <Unregistered field={field({ type: 'town' })} value={null} onChange={vi.fn()} errors={[]} options={{}} optionsLoading={false} />,
        )

        expect(screen.getByRole('alert').textContent).toContain('town')
        expect(container.querySelectorAll('input, select, textarea')).toHaveLength(0)
    })
})

describe('select', () => {
    // Counterfactual: emit '' for the placeholder option. Field::rules() adds
    // `in:a,b` for a field with static options and `nullable` does not exempt ''
    // from `in:`, so publishing would fail validation on a field the author
    // deliberately left blank.
    it('emits null for the empty choice, never an empty string', async () => {
        const onChange = renderControl(field({ type: 'select', options: { a: 'A', b: 'B' } }), 'a')

        await userEvent.selectOptions(screen.getByRole('combobox'), '')

        expect(onChange).toHaveBeenCalledWith(null)
    })

    // Counterfactual: render the options without the loading guard and an author
    // sees an empty dropdown while the fetch is in flight, which is
    // indistinguishable from "this tenant has no templates".
    it('says it is loading rather than showing an empty list', () => {
        renderControl(field({ type: 'select', dynamic_options: true }), null, vi.fn(), { optionsLoading: true })

        expect(screen.getByRole('combobox')).toBeDisabled()
        expect(screen.getByText(/loading/i)).toBeTruthy()
    })

    // Counterfactual: swallow `errors` and an option-source failure renders as an
    // empty select - 10's "named error, never an empty select".
    it('renders the errors it was given', () => {
        renderControl(field({ type: 'select', dynamic_options: true }), null, vi.fn(), {
            errors: ['Could not load the choices for this field (HTTP 500).'],
        })

        expect(screen.getByRole('alert').textContent).toContain('HTTP 500')
    })
})

describe('multiselect', () => {
    // The 1 gap: the field type existed in PHP and the prototype degraded it to
    // a single <select>. Counterfactual: emit a string and the server's `array`
    // base rule rejects the publish.
    it('always emits an array', async () => {
        const onChange = renderControl(field({ key: 'towns', type: 'multiselect', options: { a: 'Ada', b: 'Bek' } }), [])

        await userEvent.click(screen.getByRole('checkbox', { name: 'Ada' }))

        expect(onChange).toHaveBeenCalledWith(['a'])
    })

    // Counterfactual: always append the clicked key and an author cannot clear a
    // selection once made; the array contains duplicate `a` instead of only b.
    it('removes a value that was already selected', async () => {
        const onChange = renderControl(field({ key: 'towns', type: 'multiselect', options: { a: 'Ada', b: 'Bek' } }), ['a', 'b'])

        await userEvent.click(screen.getByRole('checkbox', { name: 'Ada' }))

        expect(onChange).toHaveBeenCalledWith(['b'])
    })

    // A config saved when the field was a `select` holds a scalar. Counterfactual:
    // call .includes() on it and the panel crashes on the node the author most
    // wants to fix.
    it('survives a scalar left behind by a field that used to be a select', () => {
        renderControl(field({ key: 'towns', type: 'multiselect', options: { a: 'Ada' } }), 'a')

        expect(screen.getByRole('checkbox', { name: 'Ada' })).toBeChecked()
    })
})

describe('number', () => {
    // Counterfactual: emit '' and the `numeric` rule rejects it, so clearing an
    // optional number field makes the flow unpublishable.
    it('emits null for an empty box and a number otherwise', async () => {
        const onChange = renderControl(field({ key: 'count', type: 'number' }), 3)

        await userEvent.clear(screen.getByRole('spinbutton'))
        expect(onChange).toHaveBeenLastCalledWith(null)

        await userEvent.type(screen.getByRole('spinbutton'), '12')
        expect(onChange).toHaveBeenLastCalledWith(12)
    })
})

describe('boolean', () => {
    // Counterfactual: emit event.target.value ("on") and the server's boolean
    // rule rejects what looks like a checked checkbox.
    it('emits a boolean', async () => {
        const onChange = renderControl(field({ key: 'urgent', type: 'boolean' }), false)

        await userEvent.click(screen.getByRole('checkbox'))

        expect(onChange).toHaveBeenCalledWith(true)
    })
})

describe('duration', () => {
    // 9 names this case. The unit list is pinned against ValidDuration by
    // tests/Unit/DurationControlUnitsTest.php, which reads DURATION_UNITS out of
    // this file - so a unit renamed here fails a PHP test.
    // Counterfactual: add 'months' to DURATION_UNITS and the PHP test still
    // passes (Carbon accepts it) but this assertion fails, which is the reminder
    // that Carbon reads a month as 28 days.
    it('offers only units Carbon resolves unambiguously', () => {
        expect(DURATION_UNITS).toEqual(['seconds', 'minutes', 'hours', 'days', 'weeks'])
    })

    // Counterfactual: omit the separating space and Carbon receives
    // `5minutes`, which is outside the grammar the PHP boundary test verifies.
    it('formats an amount and a unit into the string the engine parses', () => {
        expect(formatDuration(5, 'minutes')).toBe('5 minutes')
    })

    // Number inputs accept exponent, sign and decimal syntax when typed
    // manually. Counterfactual: call Number(raw) before validating its spelling
    // and `1e2` silently becomes the otherwise-valid integer 100.
    it('accepts only decimal digits inside the exhaustively verified range', () => {
        expect(parseAmount('1')).toBe(1)
        expect(parseAmount('999')).toBe(999)
        expect(parseAmount('1e2')).toBeNull()
        expect(parseAmount('1.5')).toBeNull()
        expect(parseAmount('0')).toBeNull()
        expect(parseAmount('-1')).toBeNull()
        expect(parseAmount('1000')).toBeNull()
        expect(formatDuration(1e21, 'minutes')).toBeNull()
        expect(formatDuration(1.5, 'minutes')).toBeNull()
        expect(formatDuration(0, 'minutes')).toBeNull()
        expect(formatDuration(-1, 'minutes')).toBeNull()
        expect(formatDuration(1000, 'minutes')).toBeNull()
    })

    // Counterfactual: parse with a loose regex that accepts anything and the
    // amount box renders NaN.
    it('parses a stored duration back into its parts, and refuses nonsense', () => {
        expect(parseDuration('2 days')).toEqual({ amount: 2, unit: 'days' })
        expect(parseDuration('1 fortnight')).toEqual({ amount: null, unit: 'minutes' })
        expect(parseDuration(null)).toEqual({ amount: null, unit: 'minutes' })
    })

    // The dangerous emission. ValidDuration rejects a value resolving to <= 0,
    // and '0 days' resolves to 0. Counterfactual: pass the raw input straight to
    // formatDuration and clearing the box publishes '0 minutes', which fails at
    // publish time with a message about seconds rather than about a blank field.
    it('emits null for an empty amount rather than a zero-second duration', async () => {
        const onChange = renderControl(field({ key: 'duration', type: 'duration' }), '5 minutes')

        await userEvent.clear(screen.getByRole('spinbutton'))

        expect(onChange).toHaveBeenLastCalledWith(null)
    })

    // Counterfactual: emit the unit alone or preserve the old unit and this does
    // not produce the server-accepted `5 days` value.
    it('emits a duration string when both parts are present', async () => {
        const onChange = renderControl(field({ key: 'duration', type: 'duration' }), '5 minutes')

        await userEvent.selectOptions(screen.getByRole('combobox'), 'days')

        expect(onChange).toHaveBeenLastCalledWith('5 days')
    })

    // Counterfactual: drop min/max and the browser advertises values outside the
    // same finite range the PHP test exhausts.
    it('advertises the exhaustively verified amount range', () => {
        renderControl(field({ key: 'duration', type: 'duration' }), '5 minutes')

        expect(screen.getByRole('spinbutton')).toHaveAttribute('min', '1')
        expect(screen.getByRole('spinbutton')).toHaveAttribute('max', '999')
    })
})
```

- [ ] **Step 3: Run the tests and confirm they fail**

```bash
npm test -- resources/js/controls
```

Expected: failures on unresolved imports of `./index`, `./Duration` and `./Unregistered`. Not a single pass.

- [ ] **Step 4: Create `resources/js/controls/types.ts`**

```ts
import type { ReactElement } from 'react'
import type { FieldPayload } from '../graph/types'

/**
 * The whole contract between the package and a host's field control (5.7),
 * deliberately narrow and deliberately six keys.
 *
 * Option fetching is the package's job, in useFieldOptions, keyed by (node type,
 * field key). A custom control receives resolved options as data and never
 * learns the URL, so E6's invariant - the options endpoint never accepts a class
 * name from the client - cannot be broken by a host's control.
 *
 * `errors` carries anything that should render beside this field, which includes
 * the server's own validation messages and an option-load failure. Folding the
 * load failure in here rather than adding a seventh key means a host's custom
 * control gets 10's "named error, never an empty select" for free.
 */
export type FieldControlProps = {
    field: FieldPayload
    value: unknown
    onChange: (next: unknown) => void
    errors: string[]
    options: Record<string, string>
    optionsLoading: boolean
}

export type FieldControl = (props: FieldControlProps) => ReactElement | null

export type ControlMap = Record<string, FieldControl>
```

- [ ] **Step 5: Create `resources/js/controls/Field.tsx`**

The label, the required marker, the help text and the error list, so seven controls do not each reinvent them:

```tsx
import type { ReactNode } from 'react'
import type { FieldPayload } from '../graph/types'

/**
 * Label, help and errors, once.
 *
 * Only Tailwind utility classes, and only tokens a host's theme defines -
 * text-foreground, text-muted-foreground, text-destructive, border-input,
 * bg-background, ring-ring - because D2's entire point is that this renders
 * inside the host's design system rather than looking like an iframe that isn't
 * one. No colour is hardcoded and no CSS file is shipped.
 */
export function FieldShell({ field, errors, children }: { field: FieldPayload; errors: string[]; children: ReactNode }) {
    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-foreground" htmlFor={`nf-${field.key}`}>
                {field.label}
                {field.required && <span className="text-destructive"> *</span>}
            </label>

            {children}

            {field.help && <p className="text-[11px] text-muted-foreground">{field.help}</p>}

            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 text-[11px] text-destructive">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
        </div>
    )
}

export const inputClass =
    'w-full rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50'
```

- [ ] **Step 6: Create `resources/js/controls/Text.tsx`**

```tsx
import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

export function Text({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <input
                id={`nf-${field.key}`}
                type="text"
                className={inputClass}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => onChange(event.target.value)}
            />
        </FieldShell>
    )
}
```

- [ ] **Step 7: Create `resources/js/controls/Number.tsx`**

```tsx
import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * Emits a number, or null for an empty box - never ''. The server's base rule
 * for a number field is `numeric`, and `nullable` does not exempt '' from it, so
 * an emptied optional number field would make the flow unpublishable.
 */
export function NumberControl({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <input
                id={`nf-${field.key}`}
                type="number"
                className={inputClass}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => {
                    const raw = event.target.value

                    onChange(raw === '' ? null : Number(raw))
                }}
            />
        </FieldShell>
    )
}
```

- [ ] **Step 8: Create `resources/js/controls/Boolean.tsx`**

```tsx
import { FieldShell } from './Field'
import type { FieldControlProps } from './types'

export function BooleanControl({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <input
                id={`nf-${field.key}`}
                type="checkbox"
                className="size-4 rounded border-input"
                checked={Boolean(value)}
                onChange={(event) => onChange(event.target.checked)}
            />
        </FieldShell>
    )
}
```

- [ ] **Step 9: Create `resources/js/controls/Select.tsx`**

```tsx
import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * The empty choice emits null, not ''.
 *
 * Field::rules() adds `in:<option keys>` to any field with static options, and
 * Laravel's `nullable` exempts null from the following rules but not ''. So a
 * field the author deliberately left blank would fail `in:` at publish time with
 * a message about an invalid selection rather than about a blank field.
 */
export function Select({ field, value, onChange, errors, options, optionsLoading }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <select
                id={`nf-${field.key}`}
                className={inputClass}
                disabled={optionsLoading}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
            >
                <option value="">{optionsLoading ? 'Loading...' : '-'}</option>
                {Object.entries(options).map(([key, label]) => (
                    <option key={key} value={key}>
                        {label}
                    </option>
                ))}
            </select>
        </FieldShell>
    )
}
```

- [ ] **Step 10: Create `resources/js/controls/Multiselect.tsx`**

```tsx
import { FieldShell } from './Field'
import type { FieldControlProps } from './types'

/**
 * The gap 1 recorded: `multiselect` existed in PHP and the prototype degraded
 * it to a single <select>, so an author could only ever pick one.
 *
 * A checkbox list rather than a multiple <select>, because a multiple select is
 * an accessibility and discoverability problem - an author has to know to
 * ctrl-click - and because the server's base rule is `array`, which a checkbox
 * list satisfies structurally.
 */
export function Multiselect({ field, value, onChange, errors, options, optionsLoading }: FieldControlProps) {
    // A config written while this field was a `select` holds a scalar. Coerce
    // rather than crash: the author is most likely opening this node precisely
    // because it needs fixing.
    const selected: string[] = Array.isArray(value)
        ? value.map(String)
        : value === null || value === undefined || value === ''
          ? []
          : [String(value)]

    const toggle = (key: string) =>
        onChange(selected.includes(key) ? selected.filter((existing) => existing !== key) : [...selected, key])

    if (optionsLoading) {
        return (
            <FieldShell field={field} errors={errors}>
                <p className="text-[11px] text-muted-foreground">Loading...</p>
            </FieldShell>
        )
    }

    return (
        <FieldShell field={field} errors={errors}>
            <div className="space-y-1 rounded-md border border-input bg-background p-2">
                {Object.keys(options).length === 0 && <p className="text-[11px] text-muted-foreground">No choices available.</p>}
                {Object.entries(options).map(([key, label]) => (
                    <label key={key} className="flex items-center gap-2 text-xs text-foreground">
                        <input
                            type="checkbox"
                            className="size-3.5 rounded border-input"
                            checked={selected.includes(key)}
                            onChange={() => toggle(key)}
                        />
                        {label}
                    </label>
                ))}
            </div>
        </FieldShell>
    )
}
```

- [ ] **Step 11: Create `resources/js/controls/Duration.tsx`**

```tsx
import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * An amount and a unit, because ValidDuration is strict and a free-text box
 * makes the author discover that at publish time rather than at type time.
 *
 * The unit list is closed, and each entry was probed against
 * Nodeflow\Schema\Rules\ValidDuration::seconds() before being included.
 * `months` is deliberately absent: Carbon accepts it, but resolves one month to
 * 28 days, which would silently mislead an author writing a monthly follow-up.
 *
 * tests/Unit/DurationControlUnitsTest.php reads DURATION_UNITS out of this file
 * and asserts every string this control can emit passes ValidDuration, so a unit
 * added or renamed here fails a PHP test rather than a host's publish.
 */
export const DURATION_UNITS = ['seconds', 'minutes', 'hours', 'days', 'weeks'] as const

/** Finite so the PHP boundary test can prove every amount this control emits. */
export const MAX_DURATION_AMOUNT = 999

export type DurationUnit = (typeof DURATION_UNITS)[number]

const DEFAULT_UNIT: DurationUnit = 'minutes'

export function formatDuration(amount: number, unit: DurationUnit): string | null {
    return Number.isInteger(amount) && amount >= 1 && amount <= MAX_DURATION_AMOUNT ? `${amount} ${unit}` : null
}

/** Validate the spelling before Number() can turn exponent syntax into an integer. */
export function parseAmount(raw: string): number | null {
    if (!/^\d+$/.test(raw)) {
        return null
    }

    const amount = Number(raw)

    return Number.isSafeInteger(amount) && amount >= 1 && amount <= MAX_DURATION_AMOUNT ? amount : null
}

/** Strict on purpose: anything this does not recognise becomes an empty amount, so the author retypes it rather than publishing it. */
export function parseDuration(value: unknown): { amount: number | null; unit: DurationUnit } {
    const match = typeof value === 'string' ? /^(\d+)\s+(\w+)$/.exec(value.trim()) : null
    const rawAmount = match?.[1]
    const unit = match?.[2] as DurationUnit | undefined

    if (!rawAmount || !unit || !(DURATION_UNITS as readonly string[]).includes(unit)) {
        return { amount: null, unit: DEFAULT_UNIT }
    }

    const amount = parseAmount(rawAmount)

    return amount === null ? { amount: null, unit: DEFAULT_UNIT } : { amount, unit }
}

export function Duration({ field, value, onChange, errors }: FieldControlProps) {
    const { amount, unit } = parseDuration(value)

    // Null, not '0 minutes' and not '': ValidDuration rejects anything resolving
    // to zero or fewer seconds, and '0 days' resolves to 0. Emitting null lets
    // required() produce "this field is required" and lets nullable() pass,
    // which are both the message the author needs.
    const emit = (nextAmount: number | null, nextUnit: DurationUnit) =>
        onChange(nextAmount === null ? null : formatDuration(nextAmount, nextUnit))

    return (
        <FieldShell field={field} errors={errors}>
            <div className="flex gap-1">
                <input
                    id={`nf-${field.key}`}
                    type="number"
                    min="1"
                    max={MAX_DURATION_AMOUNT}
                    step="1"
                    className={inputClass}
                    value={amount === null ? '' : String(amount)}
                    onChange={(event) => emit(parseAmount(event.target.value), unit)}
                />
                <select className={inputClass} value={unit} onChange={(event) => emit(amount, event.target.value as DurationUnit)}>
                    {DURATION_UNITS.map((candidate) => (
                        <option key={candidate} value={candidate}>
                            {candidate}
                        </option>
                    ))}
                </select>
            </div>
        </FieldShell>
    )
}
```

- [ ] **Step 12: Create `resources/js/controls/Unregistered.tsx`**

```tsx
import type { FieldControlProps } from './types'

/**
 * What renders when nothing is registered for a field's type.
 *
 * Loud, and containing no input of any kind. 5.7 and 10 both insist on this:
 * falling back to a text box would silently turn a town picker into free text
 * that passes the server's `string` base rule and reaches a node as garbage. The
 * author would see a working-looking field, the host would see a green publish,
 * and the failure would surface days later inside a run.
 *
 * The type is named because the fix is one line in the host's `controls` prop
 * and they need to know which key to write.
 */
export function Unregistered({ field }: FieldControlProps) {
    return (
        <div role="alert" className="space-y-1 rounded-md border border-destructive/50 bg-destructive/5 p-2">
            <p className="text-xs font-medium text-destructive">
                {field.label} - no control for field type "{field.type}"
            </p>
            <p className="text-[11px] text-muted-foreground">
                Register one on the editor's <code>controls</code> prop: <code>{`controls={{ '${field.type}': MyControl }}`}</code>
            </p>
        </div>
    )
}
```

- [ ] **Step 13: Create `resources/js/controls/index.ts`**

```ts
import { BooleanControl } from './Boolean'
import { Duration } from './Duration'
import { Multiselect } from './Multiselect'
import { NumberControl } from './Number'
import { Select } from './Select'
import { Text } from './Text'
import type { ControlMap, FieldControl } from './types'
import { Unregistered } from './Unregistered'

/**
 * The built-in set, closed at the six field types FieldType declares. Custom
 * types are the extension path - Field::custom() plus an entry on the `controls`
 * prop - because FieldType is a PHP enum a host cannot add a case to.
 *
 * A plain object, not a registry: E5. A module-level registry populated by
 * import side-effects is order-dependent and does not survive Inertia SSR.
 */
export const defaultControls: ControlMap = {
    text: Text,
    number: NumberControl,
    boolean: BooleanControl,
    select: Select,
    multiselect: Multiselect,
    duration: Duration,
}

/** Host overrides last, so a host may replace a built-in as well as add a type. */
export function mergeControls(overrides?: ControlMap): ControlMap {
    return { ...defaultControls, ...(overrides ?? {}) }
}

export function controlFor(type: string, controls: ControlMap): FieldControl {
    return controls[type] ?? Unregistered
}

export { Unregistered }
export type { ControlMap, FieldControl, FieldControlProps } from './types'
```

- [ ] **Step 14: Run the JS tests and the type check**

```bash
npm test && npm run types:check
```

Expected: 35 Vitest tests pass and `tsc` is silent.

- [ ] **Step 15: Pin multiselect's server boundary, then pin duration units across languages**

Laravel 13's top-level `in:` validator is array-aware when the same field also has `array`: a direct control probe showed `['a']` passes `['array', 'in:a,b']` and `['z']` fails. That means `Field::rules()` is already compatible with the array the client emits; changing it to a second `towns.*` rule would be unnecessary production churn. Pin the surprising framework behavior so an upgrade cannot silently invalidate every multiselect.

Add `use Illuminate\Support\Facades\Validator;` to `tests/Unit/FieldTest.php`, then append:

```php
it('validates every multiselect choice against its declared options', function () {
    // Counterfactual: change Multiselect's base rule away from array, drop the
    // `in:` rule, or upgrade to a validator where top-level in is not array-aware;
    // then either a valid emitted array fails or an undeclared member passes.
    $rules = Field::multiselect('towns')
        ->options(['a' => 'Ada', 'b' => 'Bek'])
        ->required()
        ->rules();

    expect(Validator::make(['towns' => ['a', 'b']], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['towns' => ['a', 'z']], $rules)->passes())->toBeFalse()
        ->and(Validator::make(['towns' => 'a'], $rules)->passes())->toBeFalse();
});
```

Run it before moving on:

```bash
vendor/bin/pest --filter='validates every multiselect choice'
```

Expected: one characterization test passes against the existing PHP implementation and closes the JS-array/PHP-rule boundary with a real validator, not a restated rule array.

Then create `tests/Unit/DurationControlUnitsTest.php`:

```php
<?php

use Nodeflow\Schema\Rules\ValidDuration;

/**
 * The duration control's unit list lives in TypeScript and its validity is
 * decided in PHP, so nothing but this test connects them.
 *
 * It reads DURATION_UNITS out of the .tsx rather than restating it, because a
 * restated list is a second source of truth: renaming a unit in the control
 * would leave a hand-copied PHP array agreeing with itself while every host's
 * publish rejected the value. This is the same failure mode as open issue F-2,
 * where renaming ->help( in one stub left 203 tests green and the stub fatal in
 * every host.
 */
function durationUnitsFromControl(): array
{
    $path = __DIR__.'/../../resources/js/controls/Duration.tsx';

    expect(file_exists($path))->toBeTrue("Duration.tsx is missing at {$path}.");

    // Anchored on the declaration, and asserted to have matched: a regex that
    // silently matched nothing would make this whole test vacuously green,
    // which is the failure mode this project has recorded eight times.
    $matched = preg_match('/export const DURATION_UNITS = \[([^\]]+)\]/', (string) file_get_contents($path), $m);

    expect($matched)->toBe(1, 'DURATION_UNITS was not found in Duration.tsx - the declaration was renamed or reformatted, and this test can no longer see it.');

    preg_match_all("/'([a-z]+)'/", $m[1], $units);

    return $units[1];
}

function maximumDurationAmountFromControl(): int
{
    $source = (string) file_get_contents(__DIR__.'/../../resources/js/controls/Duration.tsx');
    $matched = preg_match('/export const MAX_DURATION_AMOUNT = (\d+)/', $source, $amount);

    expect($matched)->toBe(1, 'MAX_DURATION_AMOUNT was not found in Duration.tsx, so this test can no longer prove every emitted amount.');

    return (int) $amount[1];
}

it('finds the unit list the duration control actually offers', function () {
    // Counterfactual: rename DURATION_UNITS in Duration.tsx and this fails
    // rather than the next test passing on an empty list.
    expect(durationUnitsFromControl())->toHaveCount(5);
});

it('offers only amount and unit combinations the engine resolves to positive seconds', function () {
    // ValidDuration rejects <= 0, and Carbon resolves both '' and 'banana' to
    // zero without complaint - so a unit the control offers and Carbon does not
    // understand would publish a zero-second wait.
    // The range is finite on purpose: this loop proves every string the control
    // can emit, including its upper boundary, rather than sampling three values.
    // Counterfactual: add 'fortnights' to DURATION_UNITS, raise the maximum past
    // Carbon's accepted range, or drop the TypeScript range guard and this pin
    // either fails or no longer matches the production declaration.
    foreach (durationUnitsFromControl() as $unit) {
        foreach (range(1, maximumDurationAmountFromControl()) as $amount) {
            expect(ValidDuration::seconds("{$amount} {$unit}"))
                ->toBeGreaterThan(0, "The duration control can emit '{$amount} {$unit}', which ValidDuration rejects.");
        }
    }
});

it('rejects the zero amount the control refuses to emit', function () {
    // The reason Duration.tsx emits null for an empty amount rather than
    // '0 minutes'. If Carbon ever starts treating '0 minutes' as a real
    // duration, the control's guard becomes unnecessary and this test says so.
    expect(ValidDuration::seconds('0 minutes'))->toBe(0);
});
```

- [ ] **Step 16: Run the PHP tests**

```bash
vendor/bin/pest --filter='DurationControlUnits' && vendor/bin/pest
```

Expected: the multiselect characterization plus three duration tests pass; the full suite is 325 passing.

- [ ] **Step 17: Close both cross-boundary findings by mutation and restore**

First temporarily comment out the `$rules[] = 'in:'.implode(...)` append in `Field::rules()`, run `vendor/bin/pest --filter='validates every multiselect choice'`, and confirm the invalid-member assertion fails because `['a', 'z']` now passes. Restore the line and confirm the filtered test is green.

Then add `'fortnights'` to `DURATION_UNITS` in `Duration.tsx`, run `vendor/bin/pest --filter='positive seconds'`, and confirm it fails naming `1 fortnights`. Remove it and confirm the filtered test is green. Report all four observed outcomes. No mutation enters the commit.

- [ ] **Step 18: Run all three suites after restoring the mutation**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 35 Vitest tests pass, `tsc` is silent, and all 325 PHP tests pass.

- [ ] **Step 19: Commit**

```bash
git add resources/js/controls resources/js/test-setup.ts vitest.config.ts package.json package-lock.json tests/Unit/FieldTest.php tests/Unit/DurationControlUnitsTest.php
git commit -m "feat: add the six field controls and a loud error for an unregistered type"
```

---

## Chunk 4: Lazy field options

### Task 4: The fetch helper and lazy per-field option loading

**Files:**
- Create: `resources/js/http.ts`
- Create: `resources/js/controls/useFieldOptions.ts`
- Test: `resources/js/http.test.ts`
- Test: `resources/js/controls/useFieldOptions.test.tsx`

**Interfaces:**
- Consumes: `FieldPayload` (Task 1), the `urls.options` template (Task 2).
- Produces:
  - `send(method: 'GET' | 'PUT' | 'POST', url: string, body?: unknown): Promise<HttpResult>` where `HttpResult = {ok: boolean; status: number; data: Record<string, unknown> | null}`. It **never throws on an HTTP status** - the caller branches on `status`, because 409 and both 422s are ordinary control flow here.
  - `csrfHeaders(): Record<string, string>`.
  - `optionsUrl(template: string, nodeType: string, fieldKey: string): string`.
  - `FieldOptionsContext` and `type FieldOptionsSource = {template: string; cache: Map<string, Record<string, string>>}`.
  - `fieldOptionsKey(nodeType, fieldKey): string`, a collision-safe tuple encoding.
  - `useFieldOptions(nodeType: string, field: FieldPayload): {options: Record<string, string>; loading: boolean; error: string | null}`.
- Later tasks rely on: `send` (Tasks 6 and 8), `HttpResult` (Task 7), and `useFieldOptions` plus `FieldOptionsContext` (Task 8).

**Why the cache lives in a context value and not in the module:** the cache is keyed by `(node type, field key)` and is per-editor. A module-level `Map` would be shared across every editor mounted in a process - including Inertia SSR, where two requests can render in the same process, so one tenant's template list could be handed to another's. It would also make Vitest files order-dependent: the second test to ask for `app.send/template` would get the first test's answer. E5's argument against global state is the same argument.

**Why `send` resolves rather than throws on a 4xx:** every non-200 this client meets is a documented, renderable outcome - 409 is the conflict path, 422 is one of two publish shapes, 419 is an expired session. Exceptions for expected outcomes would put every interesting branch inside a `catch`.

- [ ] **Step 1: Write the failing tests for `http.ts`**

Create `resources/js/http.test.ts`:

```ts
import { afterEach, describe, expect, it, vi } from 'vitest'
import { csrfHeaders, optionsUrl, send } from './http'

afterEach(() => {
    document.head.innerHTML = ''
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
})

describe('csrfHeaders', () => {
    // Laravel's `web` group sets XSRF-TOKEN on every response and
    // VerifyCsrfToken::tokensMatch() decrypts the X-XSRF-TOKEN header, so the
    // cookie is the primary source. Counterfactual: send it as X-CSRF-TOKEN
    // instead and every write is a 419 that looks like a permissions problem.
    it('prefers the XSRF-TOKEN cookie and sends it back url-decoded', () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc==')}; path=/`

        expect(csrfHeaders()).toEqual({ 'X-XSRF-TOKEN': 'abc==' })
    })

    // Counterfactual: drop the meta fallback and a host with a stateless
    // middleware stack cannot save at all.
    it('falls back to the csrf-token meta tag', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="from-meta">'

        expect(csrfHeaders()).toEqual({ 'X-CSRF-TOKEN': 'from-meta' })
    })

    // Counterfactual: return {'X-XSRF-TOKEN': undefined} and fetch throws on the
    // header value rather than the request failing with a readable 419.
    it('sends no token header when there is no token to send', () => {
        expect(csrfHeaders()).toEqual({})
    })
})

describe('send', () => {
    // Counterfactual: call response.json() unconditionally and a 419 - which
    // Laravel renders as HTML - throws a SyntaxError, so the session-expired
    // path reports a JSON parse failure.
    it('resolves with the status even when the body is not json', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response('<html>Page Expired</html>', { status: 419, headers: { 'Content-Type': 'text/html' } })),
        )

        await expect(send('PUT', '/draft', {})).resolves.toEqual({ ok: false, status: 419, data: null })
    })

    // Counterfactual: throw on !response.ok and the 409 conflict path and both
    // 422 shapes all arrive as exceptions instead of as data.
    it('resolves rather than throws on a 409', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 4 }, { status: 409 })))

        const result = await send('PUT', '/draft', {})

        expect(result.ok).toBe(false)
        expect(result.status).toBe(409)
        expect(result.data).toEqual({ draft_revision: 4 })
    })

    // Accept: application/json is what makes Laravel render a validation failure
    // as a 422 JSON body instead of a redirect to a page that does not exist.
    // Counterfactual: drop the header and publish's 422 arrives as a 302.
    it('asks for json and sends the body as json', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ draft_revision: 1 }))
        vi.stubGlobal('fetch', fetchMock)
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('through-send==')}; path=/`

        await send('PUT', '/draft', { graph: { start: '' } })

        const [url, init] = fetchMock.mock.calls[0]!

        expect(url).toBe('/draft')
        expect(init.method).toBe('PUT')
        expect(init.credentials).toBe('same-origin')
        expect(init.headers.Accept).toBe('application/json')
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest')
        // Counterfactual: remove `...csrfHeaders()` from send() and the helper's
        // isolated tests still pass, but every real write 419s. This pins the
        // integration between the two functions.
        expect(init.headers['X-XSRF-TOKEN']).toBe('through-send==')
        expect(init.body).toBe(JSON.stringify({ graph: { start: '' } }))
    })

    // Counterfactual: send a body on GET and fetch rejects with a TypeError.
    it('sends no body on a GET', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ options: {} }))
        vi.stubGlobal('fetch', fetchMock)

        await send('GET', '/options')

        expect(fetchMock.mock.calls[0]![1].body).toBeUndefined()
    })
})

describe('optionsUrl', () => {
    // Counterfactual: interpolate without encodeURIComponent and a node type
    // containing a slash silently addresses a different route.
    it('substitutes both sentinels, url-encoded', () => {
        const template = 'https://app.test/admin/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'

        expect(optionsUrl(template, 'yaya.send/message', 'template')).toBe(
            'https://app.test/admin/flows/12/nodes/yaya.send%2Fmessage/fields/template/options',
        )
    })

    // A template that no longer contains its sentinels means the server contract
    // changed underneath us, and an un-substituted URL would 404 in a way that
    // looks like a missing node type. Counterfactual: return the template
    // unchanged and the failure surfaces as a mysterious 404.
    it('throws when the template has lost its placeholders', () => {
        expect(() => optionsUrl('/flows/12/nodes/x/fields/y/options', 'a', 'b')).toThrow(/__NODEFLOW_TYPE__/)
    })
})
```

- [ ] **Step 2: Run and confirm failure**

```bash
npm test -- resources/js/http.test.ts
```

Expected: unresolved import of `./http`.

- [ ] **Step 3: Create `resources/js/http.ts`**

```ts
export type HttpResult = { ok: boolean; status: number; data: Record<string, unknown> | null }

export type HttpMethod = 'GET' | 'PUT' | 'POST'

const TYPE_PLACEHOLDER = '__NODEFLOW_TYPE__'
const FIELD_PLACEHOLDER = '__NODEFLOW_FIELD__'

/**
 * The CSRF header, from whichever source this host has.
 *
 * Laravel's `web` middleware group sets an XSRF-TOKEN cookie on every response,
 * and VerifyCsrfToken::tokensMatch() decrypts the X-XSRF-TOKEN header - which is
 * why the cookie value goes back in that header and not in X-CSRF-TOKEN. Axios
 * does this automatically and fetch does not, which is the only reason this
 * function exists. The package deliberately does not depend on axios or on
 * @inertiajs/react.
 *
 * The meta-tag fallback covers a host with a stateless middleware stack. Neither
 * available means no header: a 419 with a readable message beats a fetch that
 * throws on an undefined header value.
 */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length)

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie) }
    }

    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')

    return meta ? { 'X-CSRF-TOKEN': meta } : {}
}

/**
 * One request, and never an exception for an HTTP status.
 *
 * Every non-200 this client meets is a documented, renderable outcome: 409 is
 * the draft conflict, 422 is one of publish's two shapes, 419 is an expired
 * session. Throwing would put all of them in catch blocks. A network failure
 * still rejects, because that is genuinely exceptional.
 *
 * `data` is null when the body is not JSON - Laravel renders a 419 as HTML, and
 * calling json() on it unconditionally turns "your session expired" into a
 * SyntaxError.
 */
export async function send(method: HttpMethod, url: string, body?: unknown): Promise<HttpResult> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            // Without this Laravel may answer a validation failure with a
            // redirect rather than a 422 JSON body.
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
    })

    let data: Record<string, unknown> | null = null

    try {
        data = (await response.json()) as Record<string, unknown>
    } catch {
        data = null
    }

    return { ok: response.ok, status: response.status, data }
}

/**
 * One field's options URL, from the template the server sent.
 *
 * Throws when the sentinels are gone, because a template that silently failed to
 * substitute would produce a 404 indistinguishable from "no such node type" - a
 * server-contract change reported as an author's mistake.
 */
export function optionsUrl(template: string, nodeType: string, fieldKey: string): string {
    if (!template.includes(TYPE_PLACEHOLDER) || !template.includes(FIELD_PLACEHOLDER)) {
        throw new Error(
            `The options URL template is missing ${TYPE_PLACEHOLDER} or ${FIELD_PLACEHOLDER}: received "${template}". The server's urls.options prop has changed shape.`,
        )
    }

    return template.replace(TYPE_PLACEHOLDER, encodeURIComponent(nodeType)).replace(FIELD_PLACEHOLDER, encodeURIComponent(fieldKey))
}
```

- [ ] **Step 4: Run and confirm green**

```bash
npm test -- resources/js/http.test.ts && npm run types:check
```

Expected: all 9 HTTP tests pass and `tsc` is silent.

- [ ] **Step 5: Write the failing tests for `useFieldOptions`**

Create `resources/js/controls/useFieldOptions.test.tsx`:

```tsx
import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { FieldOptionsContext, fieldOptionsKey, useFieldOptions } from './useFieldOptions'

const TEMPLATE = '/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return {
        key: 'template',
        type: 'select',
        label: 'Template',
        help: null,
        default: null,
        required: false,
        options: {},
        dynamic_options: false,
        ...overrides,
    }
}

function wrapper(cache = new Map<string, Record<string, string>>()) {
    return ({ children }: { children: ReactNode }) => (
        <FieldOptionsContext.Provider value={{ template: TEMPLATE, cache }}>{children}</FieldOptionsContext.Provider>
    )
}

describe('useFieldOptions', () => {
    // A string concatenation key is ambiguous: ('a b', 'c') and ('a', 'b c')
    // collide. Counterfactual: use `${nodeType} ${field.key}` and one field can
    // receive another node type's tenant-scoped options.
    it('encodes the node type and field key as an unambiguous tuple', () => {
        expect(fieldOptionsKey('a b', 'c')).not.toBe(fieldOptionsKey('a', 'b c'))
        expect(fieldOptionsKey('app.send', 'template')).not.toBe(fieldOptionsKey('app.send', 'channel'))
    })

    // 5.4: resolution is lazy and per field. Counterfactual: fetch for every
    // field and a node with six static fields makes six 404 requests, because
    // the endpoint 404s a field that declares no dynamic source.
    it('does not fetch for a field whose options are static', () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useFieldOptions('app.send', field({ options: { a: 'A' } })), { wrapper: wrapper() })

        expect(fetchMock).not.toHaveBeenCalled()
        expect(result.current.options).toEqual({ a: 'A' })
        expect(result.current.loading).toBe(false)
    })

    // Counterfactual: read `data` instead of `data.options` and every dynamic
    // field renders an empty list, which looks like "this tenant has none".
    it('fetches once for a dynamic field and unwraps the options key', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ options: { t1: 'Welcome' } })))

        const { result } = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })), { wrapper: wrapper() })

        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.options).toEqual({ t1: 'Welcome' })
        expect(result.current.error).toBeNull()
    })

    // Counterfactual: drop the cache and clicking between two nodes of the same
    // type refetches the same tenant-scoped lookup on every click.
    it('serves a second field of the same type and key from the cache', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ options: { t1: 'Welcome' } }))
        vi.stubGlobal('fetch', fetchMock)
        const cache = new Map<string, Record<string, string>>()

        const first = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })), { wrapper: wrapper(cache) })
        await waitFor(() => expect(first.result.current.loading).toBe(false))

        const second = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })), { wrapper: wrapper(cache) })

        expect(second.result.current.options).toEqual({ t1: 'Welcome' })
        expect(second.result.current.loading).toBe(false)
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // FieldRow is keyed by field key within one selected node, so React can keep
    // this hook mounted when the author selects another node type with the same
    // field key. Counterfactual: initialise state only once and the new field
    // briefly displays the previous pair's options.
    it('does not expose the previous pair when rerendered onto another cached pair', () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        const cache = new Map([
            [fieldOptionsKey('app.first', 'template'), { old: 'Old' }],
            [fieldOptionsKey('app.second', 'template'), { current: 'Current' }],
        ])
        const { result, rerender } = renderHook(
            ({ nodeType }) => useFieldOptions(nodeType, field({ dynamic_options: true })),
            { initialProps: { nodeType: 'app.first' }, wrapper: wrapper(cache) },
        )

        expect(result.current.options).toEqual({ old: 'Old' })

        rerender({ nodeType: 'app.second' })

        expect(result.current.options).toEqual({ current: 'Current' })
        expect(result.current.loading).toBe(false)
        expect(result.current.error).toBeNull()
        expect(fetchMock).not.toHaveBeenCalled()
    })

    // Counterfactual: let an obsolete request update shared state and a slow
    // response for the old selection overwrites the current field's choices.
    it('ignores a stale response after the node type and field pair changes', async () => {
        const pending = new Map<string, (response: Response) => void>()
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string | URL | Request) => new Promise<Response>((resolve) => pending.set(String(url), resolve))),
        )
        const { result, rerender } = renderHook(
            ({ nodeType }) => useFieldOptions(nodeType, field({ dynamic_options: true })),
            { initialProps: { nodeType: 'app.first' }, wrapper: wrapper() },
        )

        rerender({ nodeType: 'app.second' })

        await act(async () => {
            pending.get('/flows/12/nodes/app.second/fields/template/options')!(Response.json({ options: { current: 'Current' } }))
        })
        await waitFor(() => expect(result.current.options).toEqual({ current: 'Current' }))

        await act(async () => {
            pending.get('/flows/12/nodes/app.first/fields/template/options')!(Response.json({ options: { stale: 'Stale' } }))
        })

        expect(result.current.options).toEqual({ current: 'Current' })
    })

    // 10: "Options, class is not an OptionSource -> named error. Never an empty
    // select." Counterfactual: swallow the failure and return {} and the author
    // sees an empty dropdown, which is the harder bug to find.
    it('reports a failure as a named error rather than an empty list', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ message: 'Nope' }, { status: 500 })))

        const { result } = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })), { wrapper: wrapper() })

        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.error).toContain('500')
        expect(result.current.options).toEqual({})
    })

    // optionsUrl throws before send() is called. Counterfactual: construct the
    // URL outside the guarded path and the effect throws instead of naming the
    // server/client contract mismatch beside the field.
    it('reports a malformed URL template as a named field error', async () => {
        const Broken = ({ children }: { children: ReactNode }) => (
            <FieldOptionsContext.Provider value={{ template: '/no/sentinels', cache: new Map() }}>{children}</FieldOptionsContext.Provider>
        )
        const { result } = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })), { wrapper: Broken })

        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.error).toContain('__NODEFLOW_TYPE__')
        expect(result.current.options).toEqual({})
    })

    // A dynamic field without the editor provider is a wiring defect, not an
    // empty tenant result. Counterfactual: silently return {} and the host sees
    // the same UI as a legitimate empty option source.
    it('names a missing options provider rather than pretending the list is empty', () => {
        const { result } = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })))

        expect(result.current.loading).toBe(false)
        expect(result.current.options).toEqual({})
        expect(result.current.error).toContain('FieldOptionsContext')
    })
})
```

- [ ] **Step 6: Run and confirm failure**

```bash
npm test -- resources/js/controls/useFieldOptions.test.tsx
```

Expected: test collection fails on the unresolved `./useFieldOptions` import; none of the nine hook tests runs before the module exists.

- [ ] **Step 7: Create `resources/js/controls/useFieldOptions.ts`**

```ts
import { createContext, useContext, useEffect, useState } from 'react'
import type { FieldPayload } from '../graph/types'
import { optionsUrl, send } from '../http'

/**
 * Where a dynamic field's options come from, and where the answers are kept.
 *
 * The cache is per-editor and lives in the context value rather than in this
 * module. A module-level Map would be shared across every editor mounted in one
 * process - including Inertia SSR, where two requests can render in the same
 * process, so one tenant's template list could be handed to another's. E5's
 * argument against global state is the same argument.
 */
export type FieldOptionsSource = {
    /** urls.options from the edit page's props, sentinels intact. */
    template: string
    cache: Map<string, Record<string, string>>
}

export const FieldOptionsContext = createContext<FieldOptionsSource | null>(null)

const EMPTY: Record<string, string> = {}

type State = { key: string; options: Record<string, string> | null; loading: boolean; error: string | null }

/** An injective cache key for the `(node type, field key)` pair. */
export function fieldOptionsKey(nodeType: string, fieldKey: string): string {
    return JSON.stringify([nodeType, fieldKey])
}

/**
 * One field's options, fetched when and only when the field says it is dynamic.
 *
 * Lazy per field (5.4): eager resolution would run every option source of every
 * registered node on every editor page load, including nodes the author never
 * places. It is also the only correct behaviour, because the endpoint 404s a
 * field that declares no dynamic source.
 *
 * A failure returns an error string and an empty option list, and the config
 * panel renders that string beside the field. 10 requires a named error rather
 * than an empty select: an empty dropdown is indistinguishable from a tenant who
 * genuinely has no templates yet, which is the harder bug to find.
 */
export function useFieldOptions(
    nodeType: string,
    field: FieldPayload,
): { options: Record<string, string>; loading: boolean; error: string | null } {
    const source = useContext(FieldOptionsContext)
    const key = fieldOptionsKey(nodeType, field.key)
    const cached = source?.cache.get(key)

    const [state, setState] = useState<State>(() => ({
        key,
        options: cached ?? null,
        loading: field.dynamic_options && source !== null && cached === undefined,
        error: field.dynamic_options && source === null ? 'Could not load the choices for this field: no FieldOptionsContext provider is mounted.' : null,
    }))

    useEffect(() => {
        if (!field.dynamic_options) {
            return
        }

        let live = true

        if (!source) {
            setState({ key, options: null, loading: false, error: 'Could not load the choices for this field: no FieldOptionsContext provider is mounted.' })

            return
        }

        const existing = source.cache.get(key)

        if (existing !== undefined) {
            setState({ key, options: existing, loading: false, error: null })

            return
        }

        setState({ key, options: null, loading: true, error: null })

        let url: string

        try {
            url = optionsUrl(source.template, nodeType, field.key)
        } catch (reason: unknown) {
            setState({ key, options: null, loading: false, error: `Could not load the choices for this field: ${String(reason)}` })

            return
        }

        send('GET', url)
            .then((result) => {
                if (!live) {
                    return
                }

                if (!result.ok) {
                    setState({
                        key,
                        options: null,
                        loading: false,
                        error: `Could not load the choices for this field (HTTP ${result.status}). The node type or field key may not be registered, or its option source may not implement Nodeflow\\Schema\\OptionSource.`,
                    })

                    return
                }

                const options = (result.data?.options ?? {}) as Record<string, string>

                source.cache.set(key, options)
                setState({ key, options, loading: false, error: null })
            })
            .catch((reason: unknown) => {
                if (live) {
                    setState({ key, options: null, loading: false, error: `Could not load the choices for this field: ${String(reason)}` })
                }
            })

        return () => {
            live = false
        }
    }, [source, key, nodeType, field.key, field.dynamic_options])

    if (!field.dynamic_options) {
        return { options: field.options, loading: false, error: null }
    }

    if (state.key !== key) {
        return {
            options: cached ?? EMPTY,
            loading: source !== null && cached === undefined,
            error: source === null ? 'Could not load the choices for this field: no FieldOptionsContext provider is mounted.' : null,
        }
    }

    return { options: state.options ?? cached ?? EMPTY, loading: state.loading, error: state.error }
}
```

- [ ] **Step 8: Run and confirm green**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 53 Vitest tests pass, `tsc` is silent, and all 325 PHP tests pass.

- [ ] **Step 9: Commit**

```bash
git add resources/js/http.ts resources/js/http.test.ts resources/js/controls/useFieldOptions.ts resources/js/controls/useFieldOptions.test.tsx
git commit -m "feat: fetch a field's options lazily, and name the failure when it fails"
```

---

## Chunk 5: Shared canvas primitives

### Task 5: The canvas primitives, shared with Plan 4's run view

**Files:**
- Create: `resources/js/canvas/context.ts`
- Create: `resources/js/canvas/NodeCard.tsx`
- Create: `resources/js/canvas/Canvas.tsx`
- Modify: `resources/js/test-setup.ts` (the jsdom shims `@xyflow/react` needs)
- Test: `resources/js/canvas/canvas.test.tsx`

**Interfaces:**
- Consumes: `CanvasEdge`, `CanvasNode`, `NodeCardData`, `NodeTypePayload` (Task 1); `NODE_WIDTH`, `outputHandleTop` (Task 1's `canvas/layout.ts`).
- Produces:
  - `type NodeRendererProps = {data: NodeCardData; def: NodeTypePayload | undefined; selected: boolean; errors: string[]}`.
  - `type NodeRenderer = (props: NodeRendererProps) => ReactElement | null`.
  - `type NodeRendererMap = Record<string, NodeRenderer>`.
  - `defaultNodeRenderer: NodeRenderer` and `rendererFor(type: string, renderers: NodeRendererMap): NodeRenderer`.
  - `CanvasContext`, `type CanvasContextValue = {defs: Record<string, NodeTypePayload>; renderers: NodeRendererMap; nodeErrors: Record<string, string[]>}`.
  - `type NodeflowNode = CanvasNode & Node<NodeCardData, 'nodeflowNode'>` and `type NodeflowEdge = CanvasEdge & Edge`, the compiler-checked React Flow boundary consumed by Task 8 without widening away required graph fields.
  - `interactionProps(interactive: boolean)`, the complete edit/read-only policy passed to React Flow.
  - `NodeCard` (the React Flow node component, registered as `nodeflowNode`).
  - `Canvas` and `type CanvasProps` (listed in Step 6).
- Later tasks rely on: `Canvas` and `defaultNodeRenderer` (Task 8, and Plan 4's `FlowRun`).

**The division of labour inside a node, and why:** `NodeCard` owns the **handles**; a renderer owns only the **body**. 5.8 lets a host replace a node's appearance with `nodeRenderers={{'yaya.send_message': MyCard}}`, and if the handles were the renderer's responsibility every host override would silently break the author's ability to connect that node - a card that looks right and cannot be wired. So `NodeCard` draws the target handle, one source handle per declared output, and the per-node error list, and delegates the middle to `rendererFor(type, renderers)`.

**Why `Canvas` takes state as props rather than owning it:** E7 requires the run view to share canvas primitives with the editor while importing nothing from it. A `Canvas` that owned node state could not be rendered read-only from a run. It takes nodes, edges and handlers, and `interactive={false}` turns off dragging and connecting for Plan 4.

**The CSS import lives here.** `Canvas.tsx` imports `@xyflow/react/dist/style.css` so the host does not have to remember a sixth wiring step to make the canvas visible at all. Vite handles the import; `tsc` is satisfied by Task 1's `types/css.d.ts`; Vitest ignores CSS imports by default. This is also the reason `@xyflow/react` must be a host dependency (wiring requirement 4) - and that requirement fails loudly at build time, which is the right way round.

- [ ] **Step 1: Add the jsdom shims `@xyflow/react` needs**

Append to `resources/js/test-setup.ts`:

```ts
/**
 * @xyflow/react measures the DOM. jsdom implements none of what it measures, so
 * without these three shims every test that mounts a canvas dies inside the
 * library rather than in the code under test.
 *
 * These make the canvas *mountable*, not *measurable*: a jsdom node has no size,
 * so nothing here can assert layout, viewport or fitView behaviour. That is why
 * the behaviour worth asserting - which renderer is chosen, which handles exist,
 * whether a connection is allowed - lives in pure functions this file does not
 * touch, and why the real acceptance check is Task 10's click-through in a
 * browser.
 */
class ResizeObserverStub implements ResizeObserver {
    constructor(_callback: ResizeObserverCallback) {}
    observe(_target: Element, _options?: ResizeObserverOptions) {}
    unobserve(_target: Element) {}
    disconnect() {}
}

globalThis.ResizeObserver ??= ResizeObserverStub

if (!('DOMMatrixReadOnly' in globalThis)) {
    class DOMMatrixReadOnlyStub {
        m22 = 1
        constructor(_transform?: string) {}
    }

    Object.defineProperty(globalThis, 'DOMMatrixReadOnly', { value: DOMMatrixReadOnlyStub, writable: true })
}

Object.defineProperties(globalThis.HTMLElement.prototype, {
    offsetHeight: { get: () => 40 },
    offsetWidth: { get: () => 208 },
})
```

- [ ] **Step 2: Write the failing tests**

Create `resources/js/canvas/canvas.test.tsx`:

```tsx
import { ReactFlowProvider, type NodeProps } from '@xyflow/react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { Canvas, canvasBehavior, interactionProps, type NodeflowNode } from './Canvas'
import { CanvasContext } from './context'
import { defaultNodeRenderer, NodeCard, rendererFor } from './NodeCard'

function def(overrides: Partial<NodeTypePayload> = {}): NodeTypePayload {
    return {
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: 'Sends one message',
        outputs: ['sent', 'failed'],
        fields: [],
        default_config: {},
        cardinality: ['subject'],
        ...overrides,
    }
}

const data: NodeCardData = { id: 'n1', type: 'app.send', config: { template: 'welcome' }, isStart: true }

const canvasNode: CanvasNode = { id: 'n1', type: 'nodeflowNode', position: { x: 0, y: 0 }, data }
const canvasEdge: CanvasEdge = { id: 'n1-sent-n2', source: 'n1', sourceHandle: 'sent', target: 'n2' }

const nodeProps: NodeProps<NodeflowNode> = {
    id: 'n1',
    data,
    type: 'nodeflowNode',
    selected: false,
    dragging: false,
    zIndex: 0,
    isConnectable: true,
    positionAbsoluteX: 0,
    positionAbsoluteY: 0,
    selectable: true,
    deletable: true,
    draggable: true,
}

describe('rendererFor', () => {
    // 5.8: the same prop-merge shape as controls, one mechanism learned once.
    // Counterfactual: read the map before the fallback in the wrong order and a
    // host override is ignored.
    it('prefers a host renderer for that node type', () => {
        const Mine = () => null

        expect(rendererFor('app.send', { 'app.send': Mine })).toBe(Mine)
        expect(rendererFor('app.send', {})).toBe(defaultNodeRenderer)
    })
})

describe('defaultNodeRenderer', () => {
    // 5.8 names all four fields. Counterfactual: read only the label from the
    // definition and the icon, group or descriptive context silently vanishes.
    it('reads icon, label, group and description from the definition', () => {
        render(defaultNodeRenderer({ data, def: def({ icon: '✉' }), selected: false, errors: [] }))

        expect(screen.getByText('✉')).toBeInTheDocument()
        expect(screen.getByText('Send message')).toBeInTheDocument()
        expect(screen.getByText('Messaging')).toBeInTheDocument()
        expect(screen.getByText('Sends one message')).toBeInTheDocument()
    })

    // A draft may reference a type the host has not registered - that is legal,
    // and publish is where it is caught. Counterfactual: render nothing when def
    // is undefined and the author sees an empty box they cannot diagnose or
    // delete on purpose.
    it('names an unregistered node type instead of rendering an empty card', () => {
        render(defaultNodeRenderer({ data: { ...data, type: 'not.registered' }, def: undefined, selected: false, errors: [] }))

        expect(screen.getByRole('alert').textContent).toContain('not.registered')
    })

    // Counterfactual: drop the isStart branch and the author cannot tell which
    // node a run begins at, which is the single most consequential property of
    // the graph.
    it('marks the start node', () => {
        render(defaultNodeRenderer({ data, def: def(), selected: false, errors: [] }))

        expect(screen.getByText('START')).toBeInTheDocument()
    })
})

describe('NodeCard', () => {
    // The handles belong to NodeCard, not to the renderer: 5.8 lets a host
    // replace a node's appearance, and a host override that forgot the handles
    // would produce a card that looks right and cannot be wired.
    // Counterfactual: move the handles into defaultNodeRenderer and this fails
    // with a host renderer in place.
    it('draws one source handle per declared output even under a host renderer', () => {
        const Mine = () => <p>mine</p>

        const { container } = render(
            <ReactFlowProvider>
                <CanvasContext.Provider value={{ defs: { 'app.send': def() }, renderers: { 'app.send': Mine }, nodeErrors: {} }}>
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByText('mine')).toBeInTheDocument()
        expect(container.querySelectorAll('[data-handleid="sent"]')).toHaveLength(1)
        expect(container.querySelectorAll('[data-handleid="failed"]')).toHaveLength(1)
        expect(container.querySelectorAll('.react-flow__handle-left')).toHaveLength(1)
    })

    // Publish's per-node errors have to land on the card - foundation spec 11's
    // promise. Counterfactual: ignore nodeErrors and the messages exist only in
    // the banner, which is the state 3a left this in.
    it('renders the errors recorded against its own id and no others', () => {
        // A host renderer is allowed to ignore its `errors` prop. NodeCard still
        // owns the mandatory error list, just as it owns the handles.
        const Mine = () => <p>host body</p>

        render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{ defs: { 'app.send': def() }, renderers: { 'app.send': Mine }, nodeErrors: { n1: ['field [template]: required'], n2: ['not mine'] } }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByRole('alert').textContent).toContain('field [template]: required')
        expect(screen.queryByText('not mine')).toBeNull()
    })
})

describe('interactionProps', () => {
    // `nodesDraggable={false}` and `nodesConnectable={false}` alone still leave
    // nodes keyboard-focusable, selectable and deletable. Counterfactual: omit
    // any one of these flags and Plan 4's frozen run graph still looks or acts
    // editable.
    it('turns every graph mutation and selection affordance off for a read-only canvas', () => {
        expect(interactionProps(false)).toEqual({
            nodesDraggable: false,
            nodesConnectable: false,
            nodesFocusable: false,
            edgesFocusable: false,
            elementsSelectable: false,
            edgesReconnectable: false,
            deleteKeyCode: null,
            disableKeyboardA11y: true,
        })

        const behavior = canvasBehavior(false, [{ ...canvasNode, draggable: true, selectable: true, deletable: true, focusable: true, connectable: true }], [
            { ...canvasEdge, selectable: true, deletable: true, focusable: true, reconnectable: true },
        ], {
            onNodesChange: vi.fn(),
            onEdgesChange: vi.fn(),
            onConnect: vi.fn(),
        })

        expect(behavior.nodes[0]).toMatchObject({ draggable: false, selectable: false, deletable: false, focusable: false, connectable: false })
        expect(behavior.edges[0]).toMatchObject({ selectable: false, deletable: false, focusable: false, reconnectable: false })
        expect(behavior.onNodesChange).toBeUndefined()
        expect(behavior.onEdgesChange).toBeUndefined()
        expect(behavior.onConnect).toBeUndefined()
    })
})

describe('Canvas', () => {
    // A mount smoke test, nothing more: jsdom gives every element zero size, so
    // no layout, viewport or fitView assertion here would mean anything.
    // Counterfactual: forget nodeTypes={{nodeflowNode: NodeCard}} and React Flow
    // renders its default node, so the label never appears.
    it('mounts and renders a node through the registered node type', () => {
        render(
            <Canvas
                nodes={[canvasNode]}
                edges={[]}
                defs={{ 'app.send': def() }}
            />,
        )

        expect(screen.getByText('Send message')).toBeInTheDocument()
    })

    // Per-node flags override React Flow's global defaults, and Handle has its
    // own default of isConnectable=true. Counterfactual: apply only the global
    // policy and a frozen run can still focus/delete this deliberately
    // permissive node or start a connection from one of its handles.
    it('applies read-only mode to the mounted nodes, handles, callbacks and keyboard path', () => {
        const onNodesChange = vi.fn()
        const onEdgesChange = vi.fn()
        const onConnect = vi.fn()
        const { container } = render(
            <Canvas
                interactive={false}
                nodes={[
                    {
                        id: 'n1',
                        type: 'nodeflowNode',
                        position: { x: 0, y: 0 },
                        data,
                        selected: true,
                        draggable: true,
                        selectable: true,
                        deletable: true,
                        focusable: true,
                        connectable: true,
                    },
                ]}
                edges={[]}
                defs={{ 'app.send': def() }}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onConnect={onConnect}
            />,
        )

        expect(screen.getByTestId('rf__node-n1')).not.toHaveAttribute('tabindex')
        for (const handle of container.querySelectorAll('.react-flow__handle')) {
            expect(handle).not.toHaveClass('connectable')
        }

        fireEvent.keyDown(document, { key: 'Delete' })
        expect(onNodesChange).not.toHaveBeenCalled()
        expect(onEdgesChange).not.toHaveBeenCalled()
        expect(onConnect).not.toHaveBeenCalled()
    })
})
```

The `Canvas` composition test is mandatory. If React Flow's jsdom requirements differ from these shims, fix the shims or the component and preserve the assertion. If the installed library makes that impossible, report the task as **BLOCKED** with the exact incompatibility; do not delete, skip or weaken the sole test that proves `Canvas` registers `NodeCard`.

- [ ] **Step 3: Run and confirm failure**

```bash
npm test -- resources/js/canvas
```

Expected: test collection stops at the first unresolved local canvas import (`./Canvas` with Vitest's normal resolver order); none of the nine canvas tests runs before the production modules exist.

- [ ] **Step 4: Create `resources/js/canvas/context.ts`**

```ts
import { createContext } from 'react'
import type { NodeCardData, NodeTypePayload } from '../graph/types'
import type { ReactElement } from 'react'

/**
 * What a node renderer receives. The body of a card, not its wiring: NodeCard
 * keeps the handles (5.8's override must not be able to break connectivity).
 */
export type NodeRendererProps = {
    data: NodeCardData
    /** Undefined when the graph references a type the host has not registered - legal in a draft. */
    def: NodeTypePayload | undefined
    selected: boolean
    /** Publish errors recorded against this node id. */
    errors: string[]
}

export type NodeRenderer = (props: NodeRendererProps) => ReactElement | null

export type NodeRendererMap = Record<string, NodeRenderer>

export type CanvasContextValue = {
    defs: Record<string, NodeTypePayload>
    renderers: NodeRendererMap
    nodeErrors: Record<string, string[]>
}

/**
 * Per-node data that toCanvas() deliberately does not carry.
 *
 * The palette, the host's renderer overrides and the current publish errors all
 * change independently of the graph, and folding them into each node's `data`
 * would mean rebuilding every node object whenever an error appeared - which is
 * also a graph change as far as autosave's serialised comparison is concerned.
 * Context keeps toCanvas() pure and keeps an error from looking like an edit.
 */
export const CanvasContext = createContext<CanvasContextValue>({ defs: {}, renderers: {}, nodeErrors: {} })
```

- [ ] **Step 5: Create `resources/js/canvas/NodeCard.tsx`**

```tsx
import { Handle, Position, type NodeProps } from '@xyflow/react'
import { useContext } from 'react'
import { CanvasContext, type NodeRenderer, type NodeRendererMap } from './context'
import type { NodeflowNode } from './Canvas'
import { NODE_WIDTH, outputHandleTop } from './layout'

export function rendererFor(type: string, renderers: NodeRendererMap): NodeRenderer {
    return renderers[type] ?? defaultNodeRenderer
}

/**
 * The default appearance: label, id, start badge, a short config summary, and
 * the description from the definition (5.8).
 *
 * An unregistered type gets a loud card rather than an empty one. A draft is
 * allowed to reference a type the host has not registered - publish is where
 * that is caught - and an author looking at a blank rectangle has no way to
 * learn what it was or decide to delete it.
 */
export const defaultNodeRenderer: NodeRenderer = ({ data, def }) => (
    <div className="space-y-1 px-3 py-2">
        <div className="flex items-center gap-1.5">
            {data.isStart && (
                <span className="rounded bg-primary px-1 text-[10px] font-semibold uppercase text-primary-foreground">START</span>
            )}
            {def?.icon && <span aria-hidden="true">{def.icon}</span>}
            <span className="text-xs font-semibold text-foreground">{def?.label ?? data.type}</span>
        </div>

        <p className="font-mono text-[10px] text-muted-foreground">{data.id}</p>
        {def?.group && <p className="text-[10px] text-muted-foreground">{def.group}</p>}

        {def === undefined ? (
            <p role="alert" className="text-[11px] text-destructive">
                Node type "{data.type}" is not registered in this application. It can be saved as a draft but not published.
            </p>
        ) : (
            <>
                {def.description && <p className="text-[10px] text-muted-foreground">{def.description}</p>}
                {Object.entries(data.config)
                    .filter(([, value]) => value !== null && value !== '' && value !== undefined)
                    .slice(0, 3)
                    .map(([key, value]) => (
                        <p key={key} className="truncate text-[10px] text-muted-foreground">
                            {key}: <span className="text-foreground">{String(value)}</span>
                        </p>
                    ))}
            </>
        )}
    </div>
)

/**
 * One canvas node: a target handle in, one source handle per declared output,
 * and a body chosen by rendererFor().
 *
 * The handles are here and not in the renderer on purpose. A host replacing a
 * node's appearance (5.8) must not be able to remove the author's ability to
 * wire it - a card that looks right and cannot be connected is a worse failure
 * than an ugly card, because nothing reports it.
 *
 * Each source handle carries id={output}. That is what makes an edge's
 * sourceHandle equal to the output name it leaves from, which is what lets
 * toGraph() resolve an output without guessing.
 */
export function NodeCard({ id, data, selected, isConnectable }: NodeProps<NodeflowNode>) {
    const { defs, renderers, nodeErrors } = useContext(CanvasContext)
    const def = defs[data.type]
    const outputs = def?.outputs ?? []
    const Body = rendererFor(data.type, renderers)
    const errors = nodeErrors[id] ?? []

    return (
        <div
            style={{ width: NODE_WIDTH }}
            className={`rounded-md border bg-card shadow-sm ${selected ? 'border-primary ring-1 ring-primary' : 'border-border'}`}
        >
            <Handle type="target" position={Position.Left} isConnectable={isConnectable} className="!size-2 !bg-muted-foreground" />

            <Body data={data} def={def} selected={selected} errors={errors} />

            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 px-3 pb-2 text-[10px] text-destructive">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}

            {outputs.map((output, index) => (
                <Handle
                    key={output}
                    id={output}
                    type="source"
                    position={Position.Right}
                    isConnectable={isConnectable}
                    style={{ top: outputHandleTop(index) }}
                    className="!size-2 !bg-primary"
                >
                    <span className="pointer-events-none absolute right-3 -top-1.5 text-[9px] text-muted-foreground">{output}</span>
                </Handle>
            ))}
        </div>
    )
}
```

- [ ] **Step 6: Create `resources/js/canvas/Canvas.tsx`**

```tsx
import {
    Background,
    Controls,
    ReactFlow,
    type Connection,
    type Edge,
    type Node,
    type NodeTypes,
    type OnEdgesChange,
    type OnNodesChange,
    type ReactFlowProps,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useMemo } from 'react'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { CanvasContext, type NodeRendererMap } from './context'
import { NodeCard } from './NodeCard'

export type NodeflowNode = CanvasNode & Node<NodeCardData, 'nodeflowNode'>
export type NodeflowEdge = CanvasEdge & Edge

export type CanvasProps = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    defs: Record<string, NodeTypePayload>
    renderers?: NodeRendererMap
    nodeErrors?: Record<string, string[]>
    onNodesChange?: OnNodesChange<NodeflowNode>
    onEdgesChange?: OnEdgesChange<NodeflowEdge>
    onConnect?: (connection: Connection) => void
    onNodeClick?: (id: string) => void
    /** False for Plan 4's run view: a run's graph is frozen and must not look editable. */
    interactive?: boolean
    className?: string
}

// Declared once, at module scope: React Flow warns and remounts every node when
// this object's identity changes between renders.
const nodeTypes = { nodeflowNode: NodeCard } satisfies NodeTypes

type InteractionProps = Pick<
    ReactFlowProps<NodeflowNode, NodeflowEdge>,
    | 'nodesDraggable'
    | 'nodesConnectable'
    | 'nodesFocusable'
    | 'edgesFocusable'
    | 'elementsSelectable'
    | 'edgesReconnectable'
    | 'deleteKeyCode'
    | 'disableKeyboardA11y'
>

/** One policy for both editor mode and Plan 4's genuinely read-only run view. */
export function interactionProps(interactive: boolean): InteractionProps {
    return {
        nodesDraggable: interactive,
        nodesConnectable: interactive,
        nodesFocusable: interactive,
        edgesFocusable: interactive,
        elementsSelectable: interactive,
        edgesReconnectable: interactive,
        deleteKeyCode: interactive ? ['Backspace', 'Delete'] : null,
        disableKeyboardA11y: !interactive,
    }
}

function readOnlyNodes(nodes: NodeflowNode[]): NodeflowNode[] {
    return nodes.map((node) => ({
        ...node,
        draggable: false,
        selectable: false,
        deletable: false,
        focusable: false,
        connectable: false,
    }))
}

function readOnlyEdges(edges: NodeflowEdge[]): NodeflowEdge[] {
    return edges.map((edge) => ({ ...edge, selectable: false, deletable: false, focusable: false, reconnectable: false }))
}

type MutationCallbacks = Pick<CanvasProps, 'onNodesChange' | 'onEdgesChange' | 'onConnect'>

/** Normalise both element-level overrides and callback ownership for read-only mode. */
export function canvasBehavior(
    interactive: boolean,
    nodes: NodeflowNode[],
    edges: NodeflowEdge[],
    callbacks: MutationCallbacks,
): { nodes: NodeflowNode[]; edges: NodeflowEdge[] } & MutationCallbacks {
    return interactive
        ? { nodes, edges, ...callbacks }
        : { nodes: readOnlyNodes(nodes), edges: readOnlyEdges(edges), onNodesChange: undefined, onEdgesChange: undefined, onConnect: undefined }
}

/**
 * The React Flow wrapper, shared by the editor and by Plan 4's run view (E7).
 *
 * It owns no graph state. A run renders a frozen version's graph read-only and
 * an editor renders a mutable draft; a Canvas that owned the nodes could not do
 * both, and one component painting a run's counts onto an editor's nodes is the
 * exact mistake E7 exists to prevent.
 *
 * The height comes from `className` or from the caller's layout. React Flow
 * measures its parent, so a canvas inside a container with no height renders as
 * nothing at all - which is why there is a default here rather than an
 * inherit-and-hope.
 */
export function Canvas({
    nodes,
    edges,
    defs,
    renderers = {},
    nodeErrors = {},
    onNodesChange,
    onEdgesChange,
    onConnect,
    onNodeClick,
    interactive = true,
    className = 'h-full min-h-[32rem] w-full',
}: CanvasProps) {
    const context = useMemo(() => ({ defs, renderers, nodeErrors }), [defs, renderers, nodeErrors])
    const interactions = interactionProps(interactive)
    // Per-element flags override React Flow's global flags, so a frozen run view
    // must clear both layers. Memoisation preserves identity in editor mode.
    const behavior = useMemo(
        () => canvasBehavior(interactive, nodes, edges, { onNodesChange, onEdgesChange, onConnect }),
        [interactive, nodes, edges, onNodesChange, onEdgesChange, onConnect],
    )

    return (
        <CanvasContext.Provider value={context}>
            <div className={className}>
                <ReactFlow<NodeflowNode, NodeflowEdge>
                    nodes={behavior.nodes}
                    edges={behavior.edges}
                    nodeTypes={nodeTypes}
                    onNodesChange={behavior.onNodesChange}
                    onEdgesChange={behavior.onEdgesChange}
                    onConnect={behavior.onConnect}
                    onNodeClick={(_, node) => onNodeClick?.(node.id)}
                    {...interactions}
                    fitView
                    proOptions={{ hideAttribution: true }}
                >
                    <Background />
                    <Controls showInteractive={false} />
                </ReactFlow>
            </div>
        </CanvasContext.Provider>
    )
}
```

`graph/` remains free of `@xyflow/react`, but the hand-off is compiler-checked: `CanvasNode[]` and `CanvasEdge[]` are structurally assignable to the exported `NodeflowNode[]` and `NodeflowEdge[]`. Do not add an `unknown` cast here or in Task 8. Parameterising `NodeProps`, the handlers and `ReactFlow` is what makes a future shape drift fail in `npm run types:check`.

- [ ] **Step 7: Run and confirm green**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 62 Vitest tests pass across six files (including the mandatory Canvas composition and complete read-only-policy tests), `tsc` is silent with no boundary casts, and all 325 PHP tests pass.

- [ ] **Step 8: Commit**

```bash
git add resources/js/canvas resources/js/test-setup.ts
git commit -m "feat: add the shared canvas, with handles the host cannot accidentally remove"
```

---

## Chunk 6: Autosave and conflicts

### Task 6: Debounced autosave, with the 409 conflict as a first-class state

**Files:**
- Create: `resources/js/editor/useAutosave.ts`
- Test: `resources/js/editor/useAutosave.test.tsx`

**Interfaces:**
- Consumes: `Graph` (Task 1), `send` (Task 4).
- Produces:
  - `type AutosaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'`.
  - `type DraftConflict = {graph: Graph; revision: number}`.
  - `type Autosave = {status: AutosaveStatus; revision: number; message: string | null; conflict: DraftConflict | null; lastSavedAt: number | null; preparePublish(): Promise<boolean>; finishPublish(revision?: number): void; resolveConflict(choice: 'mine' | 'theirs', acceptedGraph?: Graph): void}`. For `theirs`, FlowEditor passes the canonical graph it actually mounted, because `toCanvas()` normalises valid nullable/omitted wire containers. `preparePublish` waits for the active PUT, flushes the exact graph being published and holds a barrier against later PUTs; Task 8 must call `finishPublish(responseRevision)` on success or `finishPublish()` on every failure path.
  - `useAutosave(options: {url: string; initialRevision: number; graph: Graph; debounceMs?: number}): Autosave`.
- Later tasks rely on: all of it (Task 8).

**The contract this is written against, verified against the shipped server:** `PUT .../draft` takes `{graph, draft_revision}` and returns `{draft_revision}`. `draft_revision` is an **integer**, `0` for a flow that has never had a draft saved, and nullable on the wire. A mismatch is **409** with `{message, graph, draft_revision}` carrying the **newer** graph. `draft_updated_at` never appears in this endpoint's response and is never the token. Publishing does **not** reset `draft_revision`, which is why publish's response carries it and `finishPublish` adopts it before releasing queued edits.

**Why the change detector is `JSON.stringify`:** the editor recomputes its graph on every render, so object identity cannot tell an edit from a re-render, and a hook that saved on identity would autosave forever on an untouched canvas. Serialising is O(graph) on each render of a structure with tens of nodes, which is cheap, and it is exact. Canvas positions remain untouched through the round trip; a real drag is a real graph edit, while a re-render with the same coordinates serialises identically.

**Why a conflict halts the loop.** Continuing to autosave after a 409 would either keep failing or, once the revision were adopted silently, overwrite the colleague's work the 409 exists to protect. The author is asked, and `resolveConflict` restarts the loop with the answer.

- [ ] **Step 1: Write the failing tests**

Create `resources/js/editor/useAutosave.test.tsx`:

```tsx
import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Graph } from '../graph/types'
import { useAutosave } from './useAutosave'

const URL = '/flows/12/draft'

function graph(start: string): Graph {
    return { start, nodes: [{ id: start, type: 'core.exit', config: {}, position: { x: 0, y: 0 } }], edges: [] }
}

function okOnce(revision: number) {
    return vi.fn().mockResolvedValue(Response.json({ draft_revision: revision }))
}

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
})

afterEach(() => {
    vi.useRealTimers()
})

describe('useAutosave', () => {
    // Counterfactual: save whenever the graph object's identity changes and the
    // editor autosaves on its very first render, minting a revision for a flow
    // nobody has edited.
    it('does not save a graph that has not changed', async () => {
        const fetchMock = okOnce(1)
        vi.stubGlobal('fetch', fetchMock)

        renderHook(() => useAutosave({ url: URL, initialRevision: 0, graph: graph('a'), debounceMs: 500 }))

        await act(async () => {
            vi.advanceTimersByTime(2000)
        })

        expect(fetchMock).not.toHaveBeenCalled()
    })

    // Counterfactual: drop the clearTimeout in the effect's cleanup and each
    // keystroke queues its own request, so typing a template name fires ten
    // saves and the last one to land wins by accident.
    it('coalesces two changes inside the debounce window into one save', async () => {
        const fetchMock = okOnce(1)
        vi.stubGlobal('fetch', fetchMock)

        const { rerender } = renderHook((props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 500 }), {
            initialProps: { graph: graph('a') },
        })

        rerender({ graph: graph('b') })
        rerender({ graph: graph('c') })

        await act(async () => {
            vi.advanceTimersByTime(600)
        })

        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).graph.start).toBe('c')
    })

    // The token round-trip. Counterfactual: send draft_updated_at, or send
    // nothing, and the server rejects every save after the first as stale.
    it('sends the revision it holds and adopts the one it is given', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(Response.json({ draft_revision: 1 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.revision).toBe(1))
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).draft_revision).toBe(0)

        rerender({ graph: graph('c') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.revision).toBe(2))
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(1)
    })

    // An edit can land while the previous graph is crossing the network.
    // Counterfactual: clear `pending` after the response rather than before the
    // request and the latest edit is lost; allow a second concurrent request and
    // both carry the same revision, so one 409s by construction.
    it('queues only the latest graph while one save is in flight, then sends it with the returned revision', async () => {
        let resolveFirst!: (response: Response) => void
        const first = new Promise<Response>((resolve) => {
            resolveFirst = resolve
        })
        const fetchMock = vi.fn().mockReturnValueOnce(first).mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        rerender({ graph: graph('c') })
        rerender({ graph: graph('latest') })
        // Resolve the old request *before* the new edit's debounce expires. Its
        // completion must not bypass the remaining debounce.
        await act(async () => resolveFirst(Response.json({ draft_revision: 1 })))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        await act(async () => vi.advanceTimersByTime(9))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        await act(async () => vi.advanceTimersByTime(1))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

        expect(JSON.parse(fetchMock.mock.calls[1]![1].body)).toMatchObject({
            graph: { start: 'latest' },
            draft_revision: 1,
        })
        await waitFor(() => expect(result.current.revision).toBe(2))
    })

    // Counterfactual: leave C in `pending` after the author reverts to the graph
    // already represented by active PUT B; B's completion then saves abandoned C.
    it('clears a queued edit when the graph returns to the active request body', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => { resolveSave = resolve }))
        vi.stubGlobal('fetch', fetchMock)
        const { rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        rerender({ graph: graph('abandoned') })
        rerender({ graph: graph('b') })
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => vi.advanceTimersByTime(100))

        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // Counterfactual: treat a 409 as an ordinary failure and the editor either
    // keeps retrying forever or silently adopts the server's revision and
    // overwrites the colleague's graph the 409 exists to protect.
    it('stops on a 409 and exposes the newer graph rather than discarding either side', async () => {
        const theirs = graph('theirs')
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(Response.json({ message: 'Someone else edited this flow.', graph: theirs, draft_revision: 9 }, { status: 409 })),
        )

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })

        await waitFor(() => expect(result.current.status).toBe('conflict'))
        expect(result.current.conflict?.graph).toEqual(theirs)
        expect(result.current.conflict?.revision).toBe(9)
        expect(result.current.message).toContain('Someone else edited')

        // And it stays stopped: a further edit must not fire another save while
        // the author is deciding.
        const callsSoFar = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length
        rerender({ graph: graph('mine-again') })
        await act(async () => {
            vi.advanceTimersByTime(500)
        })
        expect((globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length).toBe(callsSoFar)
    })

    // Counterfactual: leave the revision untouched when the author chooses to
    // keep their own version and the retry 409s again, forever.
    it('resolving with "mine" adopts their revision and saves mine over it', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(Response.json({ message: 'Conflict', graph: graph('theirs'), draft_revision: 9 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 10 }))
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('conflict'))

        act(() => {
            result.current.resolveConflict('mine')
        })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })

        await waitFor(() => expect(result.current.revision).toBe(10))
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(9)
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).graph.start).toBe('mine')
    })

    // Counterfactual: fail to reset the baseline when the author takes the
    // server's version and the hook immediately saves the author's abandoned
    // graph back over it.
    it('resolving with "theirs" saves nothing when the mounted graph canonicalises their wire shape', async () => {
        const theirs: Graph = { start: 'theirs', nodes: [{ id: 'theirs', type: 'core.exit' }], edges: null }
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ message: 'Conflict', graph: theirs, draft_revision: 9 }, { status: 409 }))
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('conflict'))

        // The caller replaces the canvas with the canonical graph it can mount,
        // then tells the hook that exact baseline.
        const canonical = graph('theirs')
        act(() => {
            result.current.resolveConflict('theirs', canonical)
        })
        rerender({ graph: canonical })

        await act(async () => {
            vi.advanceTimersByTime(500)
        })

        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(result.current.status).toBe('idle')
    })

    // Counterfactual: parse the 419 body as JSON and the session-expired path
    // reports a SyntaxError instead of telling the author to reload.
    it('reports an expired session in words the author can act on', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Page Expired</html>', { status: 419 })))

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })

        await waitFor(() => expect(result.current.status).toBe('error'))
        expect(result.current.message).toMatch(/session/i)
    })

    // Publish does not reset draft_revision, and a client that stays open across
    // a publish must keep echoing the current token.
    // Counterfactual: drop finishPublish's adoption and the first autosave after a publish
    // 409s with an empty graph.
    it('adopts the revision publish hands back', async () => {
        const fetchMock = okOnce(6)
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        act(() => result.current.finishPublish(5))

        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })

        await waitFor(() => expect(fetchMock).toHaveBeenCalled())
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).draft_revision).toBe(5)
    })

    // Publish must be ordered after every PUT already accepted by the browser,
    // and the barrier must stay closed for the POST's whole lifetime.
    // Counterfactual: release it when preparePublish resolves and an edit during
    // POST starts a PUT that can recreate a draft after publish clears it.
    it('holds edits behind the publish barrier until finishPublish releases them', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => new Promise<Response>((resolve) => { resolveSave = resolve }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))

        const preparation = result.current.preparePublish()
        rerender({ graph: graph('during-publish') })
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => expect(preparation).resolves.toBe(true))

        await act(async () => vi.advanceTimersByTime(100))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        act(() => result.current.finishPublish(1))
        await act(async () => vi.advanceTimersByTime(10))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))

        expect(JSON.parse(fetchMock.mock.calls[1]![1].body)).toMatchObject({ graph: { start: 'during-publish' }, draft_revision: 1 })
    })

    // Counterfactual: leave the temporary edit in `afterPublish` after the
    // author reverts to the publish target; finishPublish then creates a draft
    // containing an edit that is no longer on screen.
    it('clears a post-publish edit when the graph returns to the publish target', async () => {
        const fetchMock = okOnce(1)
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        rerender({ graph: graph('abandoned') })
        rerender({ graph: graph('a') })
        act(() => result.current.finishPublish(0))
        await act(async () => vi.advanceTimersByTime(100))

        expect(fetchMock).not.toHaveBeenCalled()
    })

    // Counterfactual: only wait an active request and a click during the
    // debounce posts publish before the unsaved graph has reached the server.
    it('preparePublish force-flushes and awaits an unexpired debounce', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => {
            resolveSave = resolve
        }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        let prepared: boolean | null = null
        const preparation = result.current.preparePublish().then((value) => {
            prepared = value
        })
        await act(async () => Promise.resolve())
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(prepared).toBeNull()

        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => preparation)

        expect(prepared).toBe(true)
        expect(result.current.revision).toBe(1)
    })

    // Counterfactual: return true after the forced PUT found a conflict and
    // Task 8 posts publish through an unresolved 409 decision.
    it('preparePublish returns false when draft saving halts on a conflict', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(Response.json({ message: 'Conflict', graph: graph('theirs'), draft_revision: 9 }, { status: 409 })),
        )
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('mine') })

        await act(async () => expect(result.current.preparePublish()).resolves.toBe(false))
        expect(result.current.status).toBe('conflict')
    })

    // Counterfactual: leave the request live during cleanup and its completion
    // launches the graph queued behind it after the editor has unmounted.
    it('invalidates an in-flight request and its queued graph on unmount', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => {
            resolveSave = resolve
        }))
        vi.stubGlobal('fetch', fetchMock)
        const { rerender, unmount } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        rerender({ graph: graph('queued') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        unmount()
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))

        expect(fetchMock).toHaveBeenCalledTimes(1)
    })
})
```

- [ ] **Step 2: Run and confirm failure**

```bash
npm test -- resources/js/editor/useAutosave.test.tsx
```

Expected: test collection fails on the unresolved `./useAutosave` import; none of the fifteen autosave tests runs before the hook exists.

- [ ] **Step 3: Create `resources/js/editor/useAutosave.ts`**

```ts
import { useCallback, useEffect, useRef, useState } from 'react'
import type { Graph } from '../graph/types'
import { send } from '../http'

export type AutosaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'

export type DraftConflict = { graph: Graph; revision: number }

export type Autosave = {
    status: AutosaveStatus
    revision: number
    message: string | null
    conflict: DraftConflict | null
    lastSavedAt: number | null
    /** Serialize publish after every accepted draft PUT; false means conflict/error halted saving. */
    preparePublish(): Promise<boolean>
    /** Release the PUT barrier; a revision means publish succeeded, omission means it failed. */
    finishPublish(revision?: number): void
    /**
     * 'mine' adopts the server's revision and immediately saves the author's own
     * graph over theirs. 'theirs' adopts the revision and saves nothing - the
     * caller supplies the canonical graph it actually mounted, which can differ
     * from a valid response whose nullable/omitted containers are normalised.
     */
    resolveConflict(choice: 'mine' | 'theirs', acceptedGraph?: Graph): void
}

const EMPTY_GRAPH: Graph = { start: '', nodes: [], edges: [] }

/**
 * Debounced draft autosave (5.9), with the 409 as a state rather than an error.
 *
 * Change detection is by serialised comparison, not object identity: the editor
 * rebuilds its graph on every render, so identity cannot tell an edit from a
 * re-render and a hook keyed on it would autosave forever on an untouched
 * canvas. Canvas positions remain untouched through the round trip: a real drag
 * is a graph edit, while a re-render at the same coordinates serialises exactly
 * the same way.
 *
 * The token is draft_revision, an integer, and never draft_updated_at: Laravel
 * stores timestamps at second precision and a debounced autosave saves several
 * times per second, so a timestamp token silently stops detecting.
 *
 * A 409 halts the loop. Continuing would either keep failing or, once the
 * revision were adopted silently, overwrite exactly the colleague's work the 409
 * exists to protect.
 */
export function useAutosave({
    url,
    initialRevision,
    graph,
    debounceMs = 1000,
}: {
    url: string
    initialRevision: number
    graph: Graph
    debounceMs?: number
}): Autosave {
    const serialised = JSON.stringify(graph)

    const revision = useRef(initialRevision)
    /** The serialisation the server is known to hold. */
    const baseline = useRef(serialised)
    /** The serialisation waiting to be sent, if any. */
    const pending = useRef<string | null>(null)
    /** When the current pending edit's debounce expires. */
    const pendingDueAt = useRef<number | null>(null)
    /** The request allowed to update state, and the promise publish must await. */
    const activeRequest = useRef<{ id: number; generation: number; body: string; done: Promise<void> } | null>(null)
    const requestSequence = useRef(0)
    /** Invalidates older responses when publish adopts a newer token. */
    const generation = useRef(0)
    /** True from preparePublish until finishPublish: no draft PUT may cross the POST. */
    const publishBarrier = useRef(false)
    const publishTarget = useRef<string | null>(null)
    /** Edits made while POST /publish is in flight become the next draft. */
    const afterPublish = useRef<string | null>(null)
    const mounted = useRef(true)
    const halted = useRef(false)
    const conflict = useRef<DraftConflict | null>(null)
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
    /** Bumped by resolveConflict so the watcher effect reconsiders without the graph changing. */
    const [nudge, setNudge] = useState(0)

    const [state, setState] = useState<{
        status: AutosaveStatus
        revision: number
        message: string | null
        conflict: DraftConflict | null
        lastSavedAt: number | null
    }>({ status: 'idle', revision: initialRevision, message: null, conflict: null, lastSavedAt: null })

    useEffect(() => {
        mounted.current = true

        return () => {
            mounted.current = false
            generation.current += 1
            publishBarrier.current = false
            publishTarget.current = null
            afterPublish.current = null
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
        }
    }, [])

    const run = useCallback((force = false): Promise<void> => {
        if (activeRequest.current !== null) {
            return activeRequest.current.done
        }

        if (halted.current || pending.current === null || !mounted.current || (publishBarrier.current && !force)) {
            return Promise.resolve()
        }

        const body = pending.current
        pending.current = null
        pendingDueAt.current = null
        const requestId = ++requestSequence.current
        const requestGeneration = generation.current
        let finish!: () => void
        const done = new Promise<void>((resolve) => {
            finish = resolve
        })

        activeRequest.current = { id: requestId, generation: requestGeneration, body, done }
        setState((current) => ({ ...current, status: 'saving', message: null }))

        const stillOwnsRequest = () =>
            mounted.current &&
            generation.current === requestGeneration &&
            activeRequest.current?.id === requestId &&
            activeRequest.current.generation === requestGeneration

        void (async () => {
            try {
                const result = await send('PUT', url, { graph: JSON.parse(body) as Graph, draft_revision: revision.current })

                if (!stillOwnsRequest()) {
                    return
                }

                if (result.status === 409) {
                    halted.current = true
                    conflict.current = {
                        graph: (result.data?.graph as Graph | undefined) ?? EMPTY_GRAPH,
                        revision: Number(result.data?.draft_revision ?? revision.current),
                    }
                    setState((current) => ({
                        ...current,
                        status: 'conflict',
                        conflict: conflict.current,
                        message: typeof result.data?.message === 'string' ? result.data.message : 'Someone else edited this flow.',
                    }))

                    return
                }

                if (result.status === 419) {
                    halted.current = true
                    setState((current) => ({ ...current, status: 'error', message: 'Your session expired before this draft could be saved. Reload the page and check your last few changes.' }))

                    return
                }

                if (!result.ok) {
                    halted.current = true
                    setState((current) => ({
                        ...current,
                        status: 'error',
                        message: `The server refused this draft (HTTP ${result.status}). Your changes are still on screen but are not saved.`,
                    }))

                    return
                }

                revision.current = Number(result.data?.draft_revision ?? revision.current)
                baseline.current = body
                setState((current) => ({ ...current, status: 'saved', revision: revision.current, message: null, lastSavedAt: Date.now() }))
            } catch (reason: unknown) {
                if (stillOwnsRequest()) {
                    halted.current = true
                    setState((current) => ({ ...current, status: 'error', message: `Could not reach the server to save this draft: ${String(reason)}` }))
                }
            } finally {
                if (activeRequest.current?.id === requestId) {
                    activeRequest.current = null
                }
                finish()

                // Preserve the new edit's own debounce. If its timer already
                // expired while this request was active, delay is zero.
                if (mounted.current && pending.current !== null && !halted.current && !publishBarrier.current) {
                    const delay = Math.max(0, (pendingDueAt.current ?? Date.now()) - Date.now())

                    if (timer.current !== null) {
                        clearTimeout(timer.current)
                    }
                    timer.current = setTimeout(() => void run(), delay)
                }
            }
        })()

        return done
    }, [url])

    useEffect(() => {
        if (publishBarrier.current) {
            afterPublish.current = serialised === publishTarget.current ? null : serialised

            return
        }

        const represented = activeRequest.current?.body ?? baseline.current

        if (serialised === represented) {
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }

            return
        }

        if (halted.current) {
            return
        }

        pending.current = serialised
        pendingDueAt.current = Date.now() + debounceMs

        if (timer.current !== null) {
            clearTimeout(timer.current)
        }

        timer.current = setTimeout(() => void run(), debounceMs)

        return () => {
            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
        }
    }, [serialised, debounceMs, run, nudge])

    // A tab hidden mid-debounce would otherwise lose the pending edit. fetch on
    // pagehide is unreliable; visibilitychange fires early enough to be honoured.
    useEffect(() => {
        const flush = () => {
            if (document.visibilityState === 'hidden' && pending.current !== null && !halted.current && mounted.current && !publishBarrier.current) {
                if (timer.current !== null) {
                    clearTimeout(timer.current)
                }

                pendingDueAt.current = Date.now()
                void run()
            }
        }

        document.addEventListener('visibilitychange', flush)

        return () => document.removeEventListener('visibilitychange', flush)
    }, [run])

    const resolveConflict = useCallback((choice: 'mine' | 'theirs', acceptedGraph?: Graph) => {
        const theirs = conflict.current

        if (theirs === null) {
            return
        }

        if (choice === 'theirs' && acceptedGraph === undefined) {
            throw new Error('resolveConflict("theirs") requires the canonical graph mounted by the caller.')
        }

        revision.current = theirs.revision
        generation.current += 1
        conflict.current = null
        halted.current = false
        pending.current = null
        pendingDueAt.current = null

        // 'theirs': the caller has replaced its canvas with the canonical graph
        // it can actually mount. Use that exact local baseline, not a valid raw
        // response whose null/omitted containers toCanvas() normalised.
        // 'mine': blank the baseline so the watcher sees the author's graph as a
        // change and saves it over theirs, with their revision as the token.
        baseline.current = choice === 'theirs' ? JSON.stringify(acceptedGraph) : ''

        setState((current) => ({ ...current, status: 'idle', conflict: null, message: null, revision: theirs.revision }))
        setNudge((count) => count + 1)
    }, [])

    const finishPublish = useCallback((nextRevision?: number) => {
        if (!publishBarrier.current) {
            return
        }

        if (nextRevision !== undefined) {
            revision.current = nextRevision
            baseline.current = publishTarget.current ?? baseline.current
            setState((current) => ({ ...current, revision: nextRevision }))
        }

        publishBarrier.current = false
        publishTarget.current = null

        const queued = afterPublish.current
        afterPublish.current = null

        if (queued !== null && queued !== baseline.current && !halted.current && mounted.current) {
            pending.current = queued
            pendingDueAt.current = Date.now() + debounceMs

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
            timer.current = setTimeout(() => void run(), debounceMs)
        }
    }, [debounceMs, run])

    const preparePublish = useCallback(async (): Promise<boolean> => {
        publishBarrier.current = true
        publishTarget.current = serialised
        afterPublish.current = null

        if (timer.current !== null) {
            clearTimeout(timer.current)
        }

        // A PUT already handed to fetch can still mutate the server even if the
        // client ignores its response. Publish must therefore wait for it.
        if (activeRequest.current !== null) {
            await activeRequest.current.done
        }

        if (halted.current || !mounted.current) {
            finishPublish()

            return false
        }

        // Flush the exact graph captured by the publish click. Later edits are
        // held separately by the barrier and become a new draft after POST ends.
        if (publishTarget.current !== baseline.current) {
            pending.current = publishTarget.current
            pendingDueAt.current = Date.now()
            await run(true)
        }

        if (halted.current || !mounted.current) {
            finishPublish()

            return false
        }

        return true
    }, [finishPublish, run, serialised])

    return { ...state, preparePublish, finishPublish, resolveConflict }
}
```

- [ ] **Step 4: Run and confirm green**

```bash
npm test -- resources/js/editor/useAutosave.test.tsx && npm run types:check
```

Expected: all 15 autosave tests pass and `tsc` is silent.

- [ ] **Step 5: Close the timestamp-token finding by experiment**

Change the request body to `{graph, draft_updated_at: null}` and drop `draft_revision`, run `npm test -- resources/js/editor/useAutosave.test.tsx`, and confirm `sends the revision it holds and adopts the one it is given` fails because the request body has no `draft_revision`. Restore, rerun the same command, and confirm all 15 tests pass. Report both. This is the mechanism 3a spent three rounds getting right; the client half must be pinned too.

- [ ] **Step 6: Run every suite after restoring the mutation**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 77 Vitest tests pass, `tsc` is silent, and all 325 PHP tests pass.

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor
git commit -m "feat: autosave the draft on a debounce and treat a 409 as a decision, not a failure"
```

---

## Chunk 7: Publish interpretation and graph gestures

### Task 7: Interpreting publish's two 422s, and minting node ids

**Files:**
- Create: `resources/js/editor/publish.ts`
- Create: `resources/js/editor/ids.ts`
- Test: `resources/js/editor/publish.test.ts`
- Test: `resources/js/editor/ids.test.ts`

**Interfaces:**
- Consumes: `HttpResult` (Task 4), `NodeErrorEntry`, `NodeTypePayload` (Task 1).
- Produces:
  - `type PublishOutcome = {kind: 'published'; version: number; revision: number} | {kind: 'semantic'; banner: string[]; byNode: Record<string, NodeErrorEntry[]>; unplaceable: string[]} | {kind: 'structural'; developer: string[]} | {kind: 'failed'; message: string}`.
  - `byNode` holds the **raw entries**, not formatted strings. Two consumers need different things from them: `ConfigPanel` routes an entry to the field named by `entry.field`, and `NodeCard` renders a formatted line. Formatting here would force the panel to parse its own strings back apart.
  - `interpretPublish(result: HttpResult, knownNodeIds: Set<string>): PublishOutcome`.
  - `nextNodeId(type: string, taken: Set<string>): string`.
  - `canConnect(sourceType: string | undefined, sourceHandle: string | null, defs: Record<string, NodeTypePayload>): boolean`.
- Later tasks rely on: all three functions (Task 8).

**Why these are pure functions in their own task:** they are the three places in this client where a wrong answer is silent. A `node_errors` entry rendered on the wrong card, an id that collides with an existing node, a connection accepted with no resolvable output - none of them throws, and all three are trivially testable away from React, jsdom and React Flow. The rest of Task 8 is assembly.

**The contract, quoted from `docs/02-integration.md`:** publish returns two different 422 bodies under one status code. Tell them apart **by the presence of `node_errors`**, never by the type of `errors`. On the semantic failure `errors` is a flat string array and `node_errors` is `[{node, field, message}]`; `node` is `null` for a graph-level problem such as a cycle, and for "the start node you set does not exist" it is an id that **has no node in the graph**. On the structural failure `errors` is a field-keyed object and `node_errors` is absent entirely - and that shape means the client sent a payload it should have prevented, so it is a developer-facing message, not an author-facing one.

- [ ] **Step 1: Write the failing tests for `interpretPublish`**

Create `resources/js/editor/publish.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import type { HttpResult } from '../http'
import { interpretPublish } from './publish'

const known = new Set(['w1', 'send1'])

function result(status: number, data: Record<string, unknown> | null): HttpResult {
    return { ok: status >= 200 && status < 300, status, data }
}

describe('interpretPublish', () => {
    it('reads the version and the revision off a success', () => {
        // Counterfactual: ignore draft_revision here and the first autosave after
        // a publish 409s, because publishing does not reset the counter.
        expect(interpretPublish(result(200, { version: 4, draft_revision: 7 }), known)).toEqual({
            kind: 'published',
            version: 4,
            revision: 7,
        })
    })

    // The rule: by the key, not by the type of `errors`.
    // Counterfactual: switch on Array.isArray(errors) and a semantic failure
    // that happened to arrive with an object `errors` is misread as structural,
    // so the author sees a developer message and no node errors at all.
    it('reads a semantic failure by the presence of node_errors', () => {
        const outcome = interpretPublish(
            result(422, {
                message: 'The flow could not be published.',
                // Deliberately object-shaped: the discriminator is the presence
                // of node_errors, never Array.isArray(errors).
                errors: { unexpected: ['shape'] },
                node_errors: [{ node: 'w1', field: 'duration', message: 'not a duration' }],
            }),
            known,
        )

        expect(outcome).toEqual({
            kind: 'semantic',
            banner: [],
            byNode: { w1: [{ node: 'w1', field: 'duration', message: 'not a duration' }] },
            unplaceable: [],
        })
    })

    // A cycle belongs to no node. Counterfactual: index byNode with a null key
    // and the message renders on a card called "null", or vanishes.
    it('sends a graph-level error to the banner rather than to a card', () => {
        const outcome = interpretPublish(
            result(422, { errors: ['The graph contains a cycle.'], node_errors: [{ node: null, field: null, message: 'The graph contains a cycle.' }] }),
            known,
        )

        expect(outcome).toMatchObject({ kind: 'semantic', byNode: {} })
        expect(outcome).toMatchObject({ unplaceable: ['The graph contains a cycle.'] })
    })

    // The documented wrinkle: for "the start node you set does not exist", `node`
    // is an id with no node in the graph.
    // Counterfactual: assume every entry maps to a card and this message is
    // attached to nothing and shown nowhere - the author is told the publish
    // failed and not why.
    it('sends an error naming a node that is not in the graph to the banner', () => {
        const outcome = interpretPublish(
            result(422, {
                errors: ['The start node [ghost] is not in the graph.'],
                node_errors: [{ node: 'ghost', field: null, message: 'The start node [ghost] is not in the graph.' }],
            }),
            known,
        )

        expect(outcome).toMatchObject({ kind: 'semantic', byNode: {} })
        expect(outcome).toMatchObject({ unplaceable: ['The start node [ghost] is not in the graph.'] })
    })

    // Counterfactual: treat the structural body as semantic and the author is
    // shown 'graph.nodes.0.id' as if it were something they could fix.
    it('reads a structural failure, with no node_errors key, as a developer message', () => {
        const outcome = interpretPublish(
            result(422, {
                message: 'The graph.nodes.0.id field is required.',
                errors: { 'graph.nodes.0.id': ['The graph.nodes.0.id field is required.'] },
            }),
            known,
        )

        expect(outcome).toEqual({
            kind: 'structural',
            developer: ['graph.nodes.0.id: The graph.nodes.0.id field is required.'],
        })
    })

    // Counterfactual: fall through to `semantic` for anything non-200 and a 403
    // or 419 renders as an empty error banner with no explanation.
    it('reports any other status as a plain failure', () => {
        expect(interpretPublish(result(419, null), known)).toEqual({
            kind: 'failed',
            message: 'Your session expired before this flow could be published. Reload the page and try again.',
        })

        expect(interpretPublish(result(403, null), known)).toMatchObject({ kind: 'failed' })
    })

    // Two messages on the same node must both survive. Counterfactual: assign
    // rather than append and the author fixes one field at a time, republishing
    // to discover the next.
    it('keeps every message recorded against one node', () => {
        const outcome = interpretPublish(
            result(422, {
                errors: ['a', 'b'],
                node_errors: [
                    { node: 'send1', field: 'template', message: 'required' },
                    { node: 'send1', field: 'channel', message: 'required' },
                ],
            }),
            known,
        )

        expect(outcome).toMatchObject({
            byNode: {
                send1: [
                    { node: 'send1', field: 'template', message: 'required' },
                    { node: 'send1', field: 'channel', message: 'required' },
                ],
            },
        })
    })
})
```

- [ ] **Step 2: Run and confirm failure**

```bash
npm test -- resources/js/editor/publish.test.ts
```

Expected: test collection fails on the unresolved `./publish` import; none of the seven publish tests runs before the module exists.

- [ ] **Step 3: Create `resources/js/editor/publish.ts`**

```ts
import type { NodeErrorEntry } from '../graph/types'
import type { HttpResult } from '../http'

export type PublishOutcome =
    | { kind: 'published'; version: number; revision: number }
    | {
          kind: 'semantic'
          /** The flat strings, for a summary banner. */
          banner: string[]
          /** Messages that belong on a card, keyed by node id. */
          byNode: Record<string, NodeErrorEntry[]>
          /** Messages that name no renderable node: a cycle, or a start id with no node. */
          unplaceable: string[]
      }
    | { kind: 'structural'; developer: string[] }
    | { kind: 'failed'; message: string }

/**
 * Publish's answer, in whichever of its four shapes arrived.
 *
 * The two 422s share a status code and do not share a body, and they are told
 * apart by the presence of `node_errors` - never by the type of `errors`. That
 * is the documented rule (docs/02-integration.md, "Telling the two apart") and
 * it is the one that survives: `errors` is a flat array on the semantic failure
 * and a field-keyed object on the structural one, under the same key at the same
 * status, so a type check is a coin flip on a body shape nobody controls.
 *
 * A structural failure is a developer message, not an author message. It means
 * the client sent a payload that skips a shape the editor itself guarantees
 * before it calls publish, so showing 'graph.nodes.0.id' to the person building
 * a customer journey would be telling them about our bug.
 *
 * `unplaceable` exists because two documented cases produce a node_errors entry
 * with nowhere to render: `node: null` for a graph-level failure such as a
 * cycle, and, for "the start node you set does not exist in this flow", a `node`
 * that is by definition absent from the graph. A client that assumed every entry
 * maps to a card would silently drop both.
 */
export function interpretPublish(result: HttpResult, knownNodeIds: Set<string>): PublishOutcome {
    if (result.ok) {
        return {
            kind: 'published',
            version: Number(result.data?.version ?? 0),
            // Publishing does not reset draft_revision, and a client that stays
            // open must keep echoing the current token on its next autosave.
            revision: Number(result.data?.draft_revision ?? 0),
        }
    }

    if (result.status === 419) {
        return { kind: 'failed', message: 'Your session expired before this flow could be published. Reload the page and try again.' }
    }

    if (result.status !== 422) {
        const message = typeof result.data?.message === 'string' ? result.data.message : null

        return { kind: 'failed', message: message ?? `The flow could not be published (HTTP ${result.status}).` }
    }

    const body = result.data ?? {}
    const hasNodeErrors = Object.prototype.hasOwnProperty.call(body, 'node_errors')

    if (!hasNodeErrors) {
        // Laravel's own validation body. `errors` is field-keyed here.
        const errors = (result.data?.errors ?? {}) as Record<string, string[]>

        return {
            kind: 'structural',
            developer: Object.entries(errors).flatMap(([field, messages]) => messages.map((message) => `${field}: ${message}`)),
        }
    }

    const byNode: Record<string, NodeErrorEntry[]> = {}
    const unplaceable: string[] = []
    const nodeErrors = Array.isArray(body.node_errors) ? body.node_errors : []

    for (const entry of nodeErrors as NodeErrorEntry[]) {
        if (entry.node !== null && knownNodeIds.has(entry.node)) {
            // The raw entry, not a formatted line: ConfigPanel routes by
            // entry.field and NodeCard formats. Formatting here would make the
            // panel parse its own strings back apart to find the field.
            byNode[entry.node] = [...(byNode[entry.node] ?? []), entry]

            continue
        }

        unplaceable.push(entry.message)
    }

    const banner = Array.isArray(result.data?.errors) ? (result.data.errors as string[]) : []

    return { kind: 'semantic', banner, byNode, unplaceable }
}
```

- [ ] **Step 4: Run and confirm `publish.ts` green**

```bash
npm test -- resources/js/editor/publish.test.ts
```

Expected: all seven publish-interpretation tests pass.

- [ ] **Step 5: Write the failing tests for `ids.ts`**

Create `resources/js/editor/ids.test.ts`:

```ts
import { describe, expect, it } from 'vitest'
import type { NodeTypePayload } from '../graph/types'
import { canConnect, nextNodeId } from './ids'

function def(type: string, outputs: string[]): NodeTypePayload {
    return { type, label: type, group: 'G', icon: null, description: null, outputs, fields: [], default_config: {}, cardinality: ['subject'] }
}

const defs = { 'app.send': def('app.send', ['sent', 'failed']), 'one.out': def('one.out', ['default']), 'core.exit': def('core.exit', []) }

describe('nextNodeId', () => {
    // Counterfactual: derive the id from a counter that resets on reload and the
    // second session silently reuses an id from the first, so Graph::fromArray()
    // collapses two nodes into one - a data-losing bug that publishes cleanly.
    it('never returns an id that is already taken', () => {
        expect(nextNodeId('app.send', new Set())).toBe('send1')
        expect(nextNodeId('app.send', new Set(['send1']))).toBe('send2')
        expect(nextNodeId('app.send', new Set(['send1', 'send2']))).toBe('send3')
    })

    // Counterfactual: split on '.' and take [1] unconditionally, as the prototype
    // did, and a type with no dot produces 'undefined1'.
    it('copes with a type that has no dot in it', () => {
        expect(nextNodeId('sendsms', new Set())).toBe('sendsms1')
    })

    // Counterfactual: pass the raw segment through and a type like
    // 'yaya.send-message' mints an id publish then has to accept as a string.
    it('reduces the type segment to something readable', () => {
        expect(nextNodeId('yaya.send-message', new Set())).toBe('sendmessage1')
    })
})

describe('canConnect', () => {
    // The prototype's bug, prevented one layer earlier: a connection with no
    // handle from a node with two outputs cannot be attributed to either.
    // Counterfactual: return true unconditionally and the edge lands on the
    // canvas, blocks publish, and the author has to work out why.
    it('refuses an unattributable connection from a multi-output node', () => {
        expect(canConnect('app.send', null, defs)).toBe(false)
        expect(canConnect('app.send', 'sent', defs)).toBe(true)
    })

    // Counterfactual: require a named handle unconditionally and a valid
    // one-output node cannot connect when React Flow supplies a null handle.
    it('allows a handle-less connection from a node with exactly one output', () => {
        expect(canConnect('one.out', null, defs)).toBe(true)
    })

    // Counterfactual: accept every non-null handle and a stale or fabricated
    // output name reaches publish even though the source never declared it.
    it('refuses a named handle the source node does not declare', () => {
        expect(canConnect('app.send', 'not-an-output', defs)).toBe(false)
        expect(canConnect('one.out', 'not-an-output', defs)).toBe(false)
    })

    // Counterfactual: allow it and the author draws an edge from a terminal node,
    // which publish rejects with a message about an output that cannot exist.
    it('refuses any connection out of a node that declares no outputs', () => {
        expect(canConnect('core.exit', null, defs)).toBe(false)
        expect(canConnect('core.exit', 'default', defs)).toBe(false)
    })

    // A draft may reference an unregistered type. Counterfactual: throw on the
    // missing definition and dragging from that node crashes the editor.
    it('refuses a connection from a node type it does not know', () => {
        expect(canConnect('not.registered', null, defs)).toBe(false)
        expect(canConnect(undefined, 'sent', defs)).toBe(false)
    })
})
```

- [ ] **Step 6: Run and confirm `ids.ts` is red**

```bash
npm test -- resources/js/editor/ids.test.ts
```

Expected: test collection fails on the unresolved `./ids` import; none of the eight id/connection tests runs before the module exists.

- [ ] **Step 7: Create `resources/js/editor/ids.ts`**

```ts
import type { NodeTypePayload } from '../graph/types'

/**
 * A readable node id that is not already in use.
 *
 * Readable because the id is what publish's error messages name - "Node [send1]
 * field [template]" - and an author reading that needs to be able to find the
 * card. Not already in use because Graph::fromArray() keys nodes by id and
 * silently collapses a duplicate to last-one-wins: two nodes with one id
 * publishes cleanly and loses a node. The prototype minted ids from a counter
 * that reset on reload, so a second editing session could reuse the first's.
 */
export function nextNodeId(type: string, taken: Set<string>): string {
    const segment = (type.split('.').pop() ?? 'node').replace(/[^a-z0-9_]/gi, '') || 'node'

    let index = 1

    while (taken.has(`${segment}${index}`)) {
        index += 1
    }

    return `${segment}${index}`
}

/**
 * Whether an edge may be drawn from this handle at all.
 *
 * The canvas refuses what toGraph() could not resolve, so the author is stopped
 * at the gesture rather than at publish. A node declaring two outputs and a
 * connection carrying no handle cannot be attributed to either output, and the
 * prototype's answer - call it 'default' - published an edge naming an output
 * the node never declared.
 *
 * An unknown type refuses: a draft may legitimately reference a node type the
 * host has not registered, and nothing is known about its outputs.
 */
export function canConnect(
    sourceType: string | undefined,
    sourceHandle: string | null,
    defs: Record<string, NodeTypePayload>,
): boolean {
    const outputs = sourceType === undefined ? undefined : defs[sourceType]?.outputs

    if (outputs === undefined || outputs.length === 0) {
        return false
    }

    if (sourceHandle === null || sourceHandle === '') {
        return outputs.length === 1
    }

    return outputs.includes(sourceHandle)
}
```

- [ ] **Step 8: Run and confirm `ids.ts` green**

```bash
npm test -- resources/js/editor/ids.test.ts
```

Expected: all eight id/connection tests pass.

- [ ] **Step 9: Run every suite before committing**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 92 Vitest tests pass, `tsc` is silent, and all 325 PHP tests pass.

- [ ] **Step 10: Commit**

```bash
git add resources/js/editor/publish.ts resources/js/editor/publish.test.ts resources/js/editor/ids.ts resources/js/editor/ids.test.ts
git commit -m "feat: tell publish's two 422 shapes apart and refuse unattributable connections"
```

---

## Chunk 8: Editor assembly and public surface

### Task 8: The editor itself, and the package's public surface

**Files:**
- Create: `resources/js/editor/Palette.tsx`
- Create: `resources/js/editor/ConfigPanel.tsx`
- Create: `resources/js/editor/FlowEditor.tsx`
- Create: `resources/js/index.ts`
- Test: `resources/js/editor/Palette.test.tsx`
- Test: `resources/js/editor/ConfigPanel.test.tsx`
- Test: `resources/js/editor/FlowEditor.test.tsx`
- Test: `resources/js/index.test.ts`

**Interfaces:**
- Consumes: everything from Tasks 1, 3, 4, 5, 6, 7.
- Produces:
  - `type FlowEditorProps = {flow: FlowSummary; graph: Graph; palette: NodeTypePayload[]; triggers: TriggerPayload[]; urls: EditorUrls; controls?: ControlMap; nodeRenderers?: NodeRendererMap; autosaveDebounceMs?: number; className?: string}` - the first five keys are exactly the `edit()` props, so the host's page can spread them.
  - `FlowEditor`.
  - `resources/js/index.ts` exporting `FlowEditor`, `Canvas`, `defaultControls`, `mergeControls`, `controlFor`, `Unregistered`, `defaultNodeRenderer`, and the types.
- Later tasks rely on: `FlowEditor` and the `@nodeflow/editor` entry point (Task 10); Plan 4 adds `FlowRun` and `useOverlayPolling` to the same `index.ts`.

**`index.ts` does not export `FlowRun`.** 5.5 lists it, and Plan 4 builds it. Exporting a name that does not exist would fail `tsc`; leaving it out is the honest state. Task 9's docs say so.

**The `triggers` prop is read-only.** There is no route that updates a flow's `trigger_type`, so the editor renders the trigger's label and description from the `triggers` palette and offers no way to change it. That is not an omission - inventing a flow-update endpoint is outside 5.2's route table.

- [ ] **Step 1: Write the failing tests for `ConfigPanel`**

`ConfigPanel` has no dependency on `@xyflow/react`, so this is a real component test rather than a smoke test.

Create `resources/js/editor/ConfigPanel.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { mergeControls } from '../controls'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import type { FieldPayload, NodeCardData, NodeTypePayload } from '../graph/types'
import { ConfigPanel } from './ConfigPanel'

const TEMPLATE = '/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return { key: 'template', type: 'text', label: 'Template', help: null, default: null, required: false, options: {}, dynamic_options: false, ...overrides }
}

function def(fields: FieldPayload[]): NodeTypePayload {
    return {
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: null,
        outputs: ['sent'],
        fields,
        default_config: {},
        cardinality: ['subject'],
    }
}

const data: NodeCardData = { id: 'send1', type: 'app.send', config: { template: 'welcome' }, isStart: false }

function renderPanel(definition: NodeTypePayload, props: Partial<Parameters<typeof ConfigPanel>[0]> = {}) {
    const onConfigChange = props.onConfigChange ?? vi.fn()

    render(
        <FieldOptionsContext.Provider value={{ template: TEMPLATE, cache: new Map() }}>
            <ConfigPanel
                node={props.node ?? data}
                def={definition}
                controls={props.controls ?? mergeControls()}
                errors={props.errors ?? []}
                isStart={props.isStart ?? false}
                onConfigChange={onConfigChange}
                onMakeStart={props.onMakeStart ?? vi.fn()}
                onDelete={props.onDelete ?? vi.fn()}
            />
        </FieldOptionsContext.Provider>,
    )

    return onConfigChange
}

describe('ConfigPanel', () => {
    // Counterfactual: render from default_config instead of the node's own config
    // and every selection shows the author the node's defaults rather than what
    // they typed.
    it('renders the value the node actually holds', () => {
        renderPanel(def([field()]))

        expect(screen.getByLabelText('Template')).toHaveValue('welcome')
    })

    // Counterfactual: mutate a local copy without calling the owner and the
    // visible value changes until the next render but never reaches autosave.
    it('reports an edit as a config change on that key', async () => {
        const onConfigChange = renderPanel(def([field()]))

        await userEvent.clear(screen.getByLabelText('Template'))
        await userEvent.type(screen.getByLabelText('Template'), 'x')

        expect(onConfigChange).toHaveBeenLastCalledWith('template', 'x')
    })

    // Null is a deliberate wire value, distinct from an absent key. A nullable
    // select commonly uses it for "none" even when the definition has a default.
    // Counterfactual: `value ?? default` silently turns that choice back on.
    it('preserves an explicit null instead of replacing it with the field default', () => {
        renderPanel(def([field({ default: 'fallback' })]), { node: { ...data, config: { template: null } } })

        expect(screen.getByLabelText('Template')).toHaveValue('')
        expect(screen.getByLabelText('Template')).not.toHaveValue('fallback')
    })

    // E5, end to end. Counterfactual: hardcode defaultControls in the panel and a
    // host's town picker never renders even when registered.
    it('renders a host control for a custom field type', () => {
        const Town = () => <p>town picker</p>

        renderPanel(def([field({ key: 'destination', type: 'town', label: 'Destination' })]), { controls: mergeControls({ town: Town }) })

        expect(screen.getByText('town picker')).toBeInTheDocument()
    })

    // 5.7 and 10. Counterfactual: fall back to the text control and the author
    // gets a free-text box that passes `string` validation and reaches the node
    // as garbage - silently.
    it('names an unregistered custom type instead of rendering a text box', () => {
        const { container } = render(
            <FieldOptionsContext.Provider value={{ template: TEMPLATE, cache: new Map() }}>
                <ConfigPanel
                    node={data}
                    def={def([field({ key: 'destination', type: 'town', label: 'Destination' })])}
                    controls={mergeControls()}
                    errors={[]}
                    isStart={false}
                    onConfigChange={vi.fn()}
                    onMakeStart={vi.fn()}
                    onDelete={vi.fn()}
                />
            </FieldOptionsContext.Provider>,
        )

        expect(screen.getByRole('alert').textContent).toContain('town')
        expect(container.querySelectorAll('input, select, textarea')).toHaveLength(0)
    })

    // Publish's per-field errors have to reach the field, not just the card.
    // Counterfactual: pass every one of a node's errors to every field and the
    // author fixing `template` sees `channel`'s message under it.
    it('gives each field only the errors recorded against it', () => {
        renderPanel(def([field({ key: 'template', label: 'Template' }), field({ key: 'channel', label: 'Channel' })]), {
            errors: [
                { node: 'send1', field: 'template', message: 'template is required' },
                { node: 'send1', field: 'channel', message: 'channel is required' },
            ],
        })

        const templateError = screen.getByText('template is required').closest('div')

        expect(templateError?.textContent).toContain('Template')
        expect(templateError?.textContent).not.toContain('channel is required')
    })

    // A node-level error - one with no field - still has to be visible somewhere.
    // Counterfactual: drop entries with a null field and the message disappears.
    it('shows a node-level error that names no field', () => {
        renderPanel(def([field()]), { errors: [{ node: 'send1', field: null, message: 'this node cannot be reached' }] })

        expect(screen.getByText('this node cannot be reached')).toBeInTheDocument()
    })

    // A draft may reference an unregistered type; publish catches it. The panel
    // must still let the author see and delete the node.
    // Counterfactual: return null when def is undefined and the author cannot
    // remove the node that is blocking their publish.
    it('still offers delete for a node whose type is not registered', async () => {
        const onDelete = vi.fn()

        render(
            <FieldOptionsContext.Provider value={{ template: TEMPLATE, cache: new Map() }}>
                <ConfigPanel node={data} def={undefined} controls={mergeControls()} errors={[]} isStart={false} onConfigChange={vi.fn()} onMakeStart={vi.fn()} onDelete={onDelete} />
            </FieldOptionsContext.Provider>,
        )

        await userEvent.click(screen.getByRole('button', { name: /delete/i }))

        expect(onDelete).toHaveBeenCalled()
    })
})
```

- [ ] **Step 2: Run and confirm failure**

```bash
npm test -- resources/js/editor/ConfigPanel.test.tsx
```

Expected: collection fails on the unresolved `./ConfigPanel` import; none of the eight mandatory panel cases runs.

- [ ] **Step 3: Create `resources/js/editor/ConfigPanel.tsx`**

```tsx
import { controlFor } from '../controls'
import type { ControlMap } from '../controls/types'
import { useFieldOptions } from '../controls/useFieldOptions'
import type { FieldPayload, NodeCardData, NodeErrorEntry, NodeTypePayload } from '../graph/types'

/**
 * One field, in its own component so useFieldOptions is called once per field
 * with a hook order that does not depend on which node is selected. A loop of
 * hooks inside ConfigPanel would break the moment the author clicked from a
 * two-field node to a five-field one.
 */
function FieldRow({
    nodeType,
    field,
    value,
    controls,
    errors,
    onChange,
}: {
    nodeType: string
    field: FieldPayload
    value: unknown
    controls: ControlMap
    errors: string[]
    onChange: (next: unknown) => void
}) {
    const { options, loading, error } = useFieldOptions(nodeType, field)
    const Control = controlFor(field.type, controls)

    // The option-load failure joins the server's messages rather than needing a
    // seventh prop: 10 wants a named error beside the field, every control
    // already renders `errors` there, and a host's custom control gets it free.
    return (
        <Control
            field={field}
            value={value}
            onChange={onChange}
            errors={error === null ? errors : [...errors, error]}
            options={options}
            optionsLoading={loading}
        />
    )
}

export type ConfigPanelProps = {
    node: NodeCardData
    /** Undefined when the graph references a type the host has not registered. */
    def: NodeTypePayload | undefined
    controls: ControlMap
    /** This node's publish errors, unformatted, so field-level ones can be routed to their field. */
    errors: NodeErrorEntry[]
    isStart: boolean
    onConfigChange: (key: string, value: unknown) => void
    onMakeStart: () => void
    onDelete: () => void
}

/**
 * The selected node's identity and configuration.
 *
 * It renders for a node whose type is not registered too - only the fields
 * disappear. A draft is allowed to hold an unregistered type, and an author whose
 * publish is blocked by one needs to be able to select it and delete it.
 */
export function ConfigPanel({ node, def, controls, errors, isStart, onConfigChange, onMakeStart, onDelete }: ConfigPanelProps) {
    const nodeLevel = errors.filter((entry) => entry.field === null)

    return (
        <div className="space-y-3">
            <div>
                <h2 className="text-sm font-semibold text-foreground">{def?.label ?? node.type}</h2>
                <p className="font-mono text-[10px] text-muted-foreground">
                    {node.id} - {node.type}
                </p>
                {def?.description && <p className="mt-1 text-xs text-muted-foreground">{def.description}</p>}
                {def === undefined ? (
                    <p role="alert" className="mt-1 text-[11px] text-destructive">
                        Node type "{node.type}" is not registered in this application, so it has no configuration to show and the flow cannot be published while it is here.
                    </p>
                ) : (
                    <p className="mt-1 text-[10px] text-muted-foreground">
                        runs per {def.cardinality.join(' + ')} - outputs: {def.outputs.join(', ') || 'none'}
                    </p>
                )}
            </div>

            {nodeLevel.length > 0 && (
                <ul role="alert" className="space-y-0.5 rounded-md border border-destructive/50 bg-destructive/5 p-2 text-[11px] text-destructive">
                    {nodeLevel.map((entry) => (
                        <li key={entry.message}>{entry.message}</li>
                    ))}
                </ul>
            )}

            <button
                type="button"
                onClick={onMakeStart}
                disabled={isStart}
                className="w-full rounded-md border border-input px-2 py-1 text-xs text-foreground disabled:opacity-40"
            >
                {isStart ? 'This is the start node' : 'Make start node'}
            </button>

            {(def?.fields ?? []).map((field) => (
                <FieldRow
                    key={field.key}
                    nodeType={node.type}
                    field={field}
                    value={Object.prototype.hasOwnProperty.call(node.config, field.key) ? node.config[field.key] : field.default}
                    controls={controls}
                    errors={errors.filter((entry) => entry.field === field.key).map((entry) => entry.message)}
                    onChange={(next) => onConfigChange(field.key, next)}
                />
            ))}

            <button
                type="button"
                onClick={onDelete}
                className="w-full rounded-md border border-destructive/50 px-2 py-1 text-xs text-destructive hover:bg-destructive/5"
            >
                Delete node
            </button>
        </div>
    )
}
```

- [ ] **Step 4: Run and confirm green**

```bash
npm test -- resources/js/editor/ConfigPanel.test.tsx && npm run types:check
```

Expected: all eight ConfigPanel tests pass and `tsc` is silent.

- [ ] **Step 5: Write the failing `Palette` tests before its implementation**

Create `resources/js/editor/Palette.test.tsx`:

```tsx
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import type { NodeTypePayload } from '../graph/types'
import { Palette } from './Palette'

function entry(type: string, label: string, group: string): NodeTypePayload {
    return { type, label, group, icon: null, description: `${label} help`, outputs: [], fields: [], default_config: {}, cardinality: ['subject'] }
}

describe('Palette', () => {
    // Counterfactual: preserve registration order and a provider refactor moves
    // tools around in the UI even though their authored group/label did not change.
    it('groups node types and sorts groups and labels for the author', () => {
        render(<Palette palette={[entry('z', 'Zulu', 'Messaging'), entry('e', 'Exit', 'Core'), entry('a', 'Alpha', 'Messaging')]} onAdd={vi.fn()} />)

        expect(screen.getAllByText(/^(Core|Messaging)$/).map((node) => node.textContent)).toEqual(['Core', 'Messaging'])
        expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual(['Exite', 'Alphaa', 'Zuluz'])
    })

    // Counterfactual: render an empty column and a new host has no clue that its
    // registry, rather than the editor bundle, is what remains to configure.
    it('explains an empty registry', () => {
        render(<Palette palette={[]} onAdd={vi.fn()} />)

        expect(screen.getByText(/No node types are registered/)).toBeInTheDocument()
    })

    // Counterfactual: reconstruct an entry from the button text and default
    // config/cardinality metadata is lost before FlowEditor can add the node.
    it('returns the exact definition represented by the clicked button', async () => {
        const definition = entry('app.send', 'Send', 'Messaging')
        const onAdd = vi.fn()
        render(<Palette palette={[definition]} onAdd={onAdd} />)

        await userEvent.click(screen.getByRole('button', { name: /Send/ }))

        expect(onAdd).toHaveBeenCalledWith(definition)
    })
})
```

- [ ] **Step 6: Run and confirm the palette test is red**

```bash
npm test -- resources/js/editor/Palette.test.tsx
```

Expected: collection fails on the unresolved `./Palette` import; no palette case is skipped.

- [ ] **Step 7: Create `resources/js/editor/Palette.tsx`**

```tsx
import type { NodeTypePayload } from '../graph/types'

/**
 * Every registered node type, grouped by its declared group.
 *
 * Grouped and sorted here rather than server-side: NodeRegistry::palette()
 * returns registration order, which is a host's provider file's order and means
 * nothing to an author. Doing it in the browser also keeps the server's payload
 * a plain projection of the registry.
 */
export function Palette({ palette, onAdd }: { palette: NodeTypePayload[]; onAdd: (def: NodeTypePayload) => void }) {
    const groups = new Map<string, NodeTypePayload[]>()

    for (const entry of [...palette].sort((a, b) => a.group.localeCompare(b.group) || a.label.localeCompare(b.label))) {
        groups.set(entry.group, [...(groups.get(entry.group) ?? []), entry])
    }

    return (
        <div className="space-y-3">
            <h2 className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Nodes</h2>

            {palette.length === 0 && (
                <p className="text-xs text-muted-foreground">
                    No node types are registered. Register them with <code>Nodeflow::register([...])</code> in a service provider.
                </p>
            )}

            {[...groups.entries()].map(([group, entries]) => (
                <div key={group} className="space-y-1">
                    <p className="text-[10px] font-medium text-muted-foreground">{group}</p>
                    {entries.map((entry) => (
                        <button
                            key={entry.type}
                            type="button"
                            onClick={() => onAdd(entry)}
                            title={entry.description ?? ''}
                            className="w-full rounded-md border border-border px-2 py-1.5 text-left text-xs text-foreground hover:bg-accent"
                        >
                            <span className="block font-medium">{entry.label}</span>
                            <span className="block font-mono text-[9px] text-muted-foreground">{entry.type}</span>
                        </button>
                    ))}
                </div>
            ))}
        </div>
    )
}
```

- [ ] **Step 8: Run and confirm the palette tests are green**

```bash
npm test -- resources/js/editor/Palette.test.tsx && npm run types:check
```

Expected: all three palette tests pass and `tsc` is silent.

---

## Chunk 8B: Editor assembly and public surface

### Task 8 (continued): Assemble the editor and pin the public entry point

- [ ] **Step 9: Write the failing tests for `FlowEditor`**

Create `resources/js/editor/FlowEditor.test.tsx`:

```tsx
import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { NodeRenderer } from '../canvas/context'
import type { FieldControl } from '../controls/types'
import type { FlowSummary, Graph, NodeTypePayload, TriggerPayload } from '../graph/types'
import { FlowEditor } from './FlowEditor'

const flow: FlowSummary = {
    id: 12,
    name: 'Welcome journey',
    trigger_type: 'app.order_placed',
    status: 'draft',
    version: 3,
    draft_revision: 7,
    draft_updated_at: null,
}

const urls = {
    draft: '/flows/12/draft',
    publish: '/flows/12/publish',
    options: '/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options',
}

const palette: NodeTypePayload[] = [
    {
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: null,
        outputs: ['sent', 'failed'],
        fields: [{ key: 'template', type: 'text', label: 'Template', help: null, default: null, required: true, options: {}, dynamic_options: false }],
        default_config: { template: null },
        cardinality: ['subject'],
    },
    { type: 'core.exit', label: 'Exit', group: 'Core', icon: null, description: null, outputs: [], fields: [], default_config: {}, cardinality: ['subject'] },
]

const triggers: TriggerPayload[] = [{ type: 'app.order_placed', label: 'Order placed', description: 'When a customer places an order', fields: [] }]

const graph = {
    start: 'send1',
    nodes: [
        { id: 'send1', type: 'app.send', config: { template: 'welcome' }, position: { x: 0, y: 0 } },
        { id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } },
    ],
    edges: [{ from: 'send1', to: 'exit1', output: 'sent' }],
} satisfies Graph

function renderEditor(overrides: Partial<Parameters<typeof FlowEditor>[0]> = {}) {
    return render(<FlowEditor flow={flow} graph={graph} palette={palette} triggers={triggers} urls={urls} autosaveDebounceMs={5} {...overrides} />)
}

function canvasNode(id: string): Element {
    const element = document.querySelector(`.react-flow__node[data-id="${id}"]`)
    if (element === null) {
        throw new Error(`React Flow did not render ${id}`)
    }

    return element
}

function editorSummary(): HTMLElement {
    return screen.getByText(/published v/)
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 8 })))
})

describe('FlowEditor', () => {
    // Counterfactual: render flow.trigger_type raw (or use only the first field)
    // and the trigger palette's author-facing explanation disappears.
    it('names and explains the trigger in words, from the triggers prop', () => {
        renderEditor()

        expect(screen.getByText('Welcome journey')).toBeInTheDocument()
        expect(screen.getByText(/Order placed/)).toBeInTheDocument()
        expect(screen.getByText('When a customer places an order')).toBeInTheDocument()
    })

    // Counterfactual: report flow.version as the draft's version and an author
    // with unsaved work is told they are editing v4 when v3 is what is live.
    it('reports the published version while showing a draft', () => {
        renderEditor()

        expect(screen.getByText(/v3/)).toBeInTheDocument()
    })

    // The semantic 422, end to end: banner plus per-node routing.
    // Counterfactual: read `errors` for the node routing rather than
    // `node_errors` and nothing reaches a card.
    it('shows a semantic publish failure in the banner and on the node', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                Response.json(
                    {
                        message: 'The flow could not be published.',
                        errors: ['Node [send1] field [template]: required'],
                        node_errors: [{ node: 'send1', field: 'template', message: 'required' }],
                    },
                    { status: 422 },
                ),
            ),
        )

        renderEditor()
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(screen.getByText(/Node \[send1\] field \[template\]/)).toBeInTheDocument())
        expect(screen.getByText('template: required')).toBeInTheDocument()

        // Select the card: ConfigPanel must receive the raw entry so it can put
        // the unprefixed message beside the right field.
        await userEvent.click(canvasNode('send1'))
        expect(screen.getByText('required')).toBeInTheDocument()
    })

    // The documented wrinkle: an entry naming a node with no card.
    // Counterfactual: assume every entry maps to a card and this message renders
    // nowhere, so the author is told the publish failed and not why.
    it('shows an error naming an absent node in the banner rather than dropping it', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                Response.json(
                    { errors: [], node_errors: [{ node: 'ghost', field: null, message: 'The start node [ghost] is not in the graph.' }] },
                    { status: 422 },
                ),
            ),
        )

        renderEditor()
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(screen.getByText(/\[ghost\]/)).toBeInTheDocument())
    })

    // The structural 422 means the client sent a payload it should have
    // prevented. Counterfactual: render 'graph.nodes.0.id' as an author-facing
    // message and the person building a journey is shown our bug.
    it('marks a structural publish failure as a client problem, not the authors', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(Response.json({ message: 'The graph.nodes.0.id field is required.', errors: { 'graph.nodes.0.id': ['required'] } }, { status: 422 })),
        )

        renderEditor()
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(screen.getByText(/editor sent/i)).toBeInTheDocument())
        expect(screen.getByText('graph.nodes.0.id: required')).toBeInTheDocument()
    })

    // The prototype's bug at the integration level. Counterfactual: publish
    // regardless and the request goes out with output: 'default', and the server
    // answers with a message about an output the author never chose.
    it('refuses to publish an edge whose output it cannot resolve, and sends nothing', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 4, draft_revision: 7 }))
        vi.stubGlobal('fetch', fetchMock)

        renderEditor({ graph: { ...graph, edges: [{ from: 'send1', to: 'exit1', output: null }] } })

        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(screen.getByText(/which output/i)).toBeInTheDocument())
        expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/publish'))).toHaveLength(0)
    })

    // Counterfactual: wire both conflict buttons to the same/no-op handler. The
    // controls still render, but "mine" never retries against the server's token.
    it('retries the exact local graph at revision 20 when the author keeps mine', async () => {
        const conflict = Response.json(
            { message: 'Someone else edited this flow.', graph: { start: 'theirs1', nodes: [], edges: [] }, draft_revision: 20 },
            { status: 409 },
        )
        const fetchMock = vi.fn().mockResolvedValueOnce(conflict).mockResolvedValueOnce(Response.json({ draft_revision: 21 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))
        await waitFor(() => expect(screen.getByText(/Someone else edited this flow/)).toBeInTheDocument())
        const firstBody = JSON.parse(String(fetchMock.mock.calls[0]![1]?.body))

        await userEvent.click(screen.getByRole('button', { name: /keep mine/i }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
        const retryBody = JSON.parse(String(fetchMock.mock.calls[1]![1]?.body))

        expect(retryBody.draft_revision).toBe(20)
        expect(retryBody.graph).toEqual(firstBody.graph)
    })

    // Counterfactual: adopt only their revision while leaving our canvas in
    // place; the next save then overwrites the graph the author chose to accept.
    it('replaces nodes, edges and start, clears selection, and does not overwrite theirs', async () => {
        const theirs = {
            start: null,
            nodes: [
                // Keep the selected local id deliberately: if FlowEditor forgets
                // to clear selection, ConfigPanel remains visible after adoption.
                { id: 'exit2', type: 'app.send' },
                { id: 'theirs2', type: 'core.exit' },
            ],
            edges: [{ from: 'exit2', to: 'theirs2', output: 'sent' }],
        } satisfies Graph
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(Response.json({ message: 'Conflict', graph: theirs, draft_revision: 20 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ version: 4, draft_revision: 20 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))
        await waitFor(() => expect(screen.getByRole('button', { name: /use theirs/i })).toBeInTheDocument())
        await userEvent.click(screen.getByRole('button', { name: /use theirs/i }))

        expect(canvasNode('exit2')).toBeInTheDocument()
        expect(canvasNode('theirs2')).toBeInTheDocument()
        expect(editorSummary()).toHaveTextContent(/start:\s*none/)
        expect(screen.queryByRole('button', { name: /delete node/i })).not.toBeInTheDocument()

        await userEvent.click(screen.getByRole('button', { name: /publish/i }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/publish'))).toHaveLength(1))
        const publishCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        const accepted = JSON.parse(String(publishCall?.[1]?.body)).graph
        expect(accepted.start).toBe('')
        expect(accepted.nodes.map((node: { id: string }) => node.id)).toEqual(['exit2', 'theirs2'])
        expect(accepted.nodes.every((node: { config?: unknown; position?: unknown }) => node.config !== undefined && node.position !== undefined)).toBe(true)
        expect(accepted.edges).toEqual([{ from: 'exit2', to: 'theirs2', output: 'sent' }])
        expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/draft'))).toHaveLength(1)
    })

    // Ignoring an old autosave response cannot undo the PUT on the server.
    // Counterfactual: POST publish while that PUT is unresolved and the PUT can
    // finish last, recreate a draft and advance the token past publish's reply.
    it('publishes the exact edited graph after the active PUT and adopts the publish revision', async () => {
        let resolveDraft!: (response: Response) => void
        let draftCalls = 0
        const fetchMock = vi.fn((url: string | URL | Request, _init?: RequestInit) => {
            if (String(url).endsWith('/publish')) {
                return Promise.resolve(Response.json({ version: 4, draft_revision: 30 }))
            }
            draftCalls += 1

            return draftCalls === 1
                ? new Promise<Response>((resolve) => { resolveDraft = resolve })
                : Promise.resolve(Response.json({ draft_revision: 31 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/draft'))).toBe(true))
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))
        expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/publish'))).toHaveLength(0)

        await act(async () => resolveDraft(Response.json({ draft_revision: 8 })))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/publish'))).toHaveLength(1))
        const publishCall = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        const publishedGraph = JSON.parse(String(publishCall?.[1]?.body)).graph
        expect(publishedGraph).toEqual({
            start: 'send1',
            nodes: [...graph.nodes, { id: 'exit2', type: 'core.exit', config: {}, position: { x: 180, y: 160 } }],
            edges: graph.edges,
        })

        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/draft'))).toHaveLength(2))
        const nextDraft = fetchMock.mock.calls.filter(([url]) => String(url).endsWith('/draft'))[1]
        expect(JSON.parse(String(nextDraft?.[1]?.body)).draft_revision).toBe(30)
    })

    // Counterfactual: let send() reject out of the event handler. The publish
    // barrier remains held and the disabled button strands the editor.
    it('shows a named network failure and re-enables publish', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(screen.getByText(/Could not reach the server.*offline/)).toBeInTheDocument())
        expect(screen.getByRole('button', { name: /publish/i })).toBeEnabled()
    })

    // Counterfactual: mint ids from a bare counter and adding a node after a
    // reload reuses an id already in the graph, which Graph::fromArray()
    // collapses to last-one-wins - a node silently lost on publish.
    it('adds a node from the palette with an id that is not already taken', async () => {
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /Send message/ }))

        await waitFor(() => expect(screen.getByText('send2')).toBeInTheDocument())
    })

    // Counterfactual: render a working ConfigPanel but forget to apply its
    // callback to FlowEditor's node state; the input looks editable but the wire
    // graph retains the stale value.
    it('publishes a config edit made through the selected node panel', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 4, draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(canvasNode('send1'))
        await userEvent.clear(screen.getByLabelText('Template'))
        await userEvent.type(screen.getByLabelText('Template'), 'changed')
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/publish'))).toBe(true))
        const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        const sent = JSON.parse(String(call?.[1]?.body))
        expect(sent.graph.nodes).toContainEqual({ ...graph.nodes[0], config: { template: 'changed' } })
    })

    // Counterfactual: ConfigPanel removes only the node. The stale start id and
    // incident edge survive invisibly and every publish fails server validation.
    it('deleting the start in ConfigPanel clears start and incident edges', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 4, draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(canvasNode('send1'))
        await userEvent.click(screen.getByRole('button', { name: /delete node/i }))
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/publish'))).toBe(true))
        const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        expect(JSON.parse(String(call?.[1]?.body)).graph).toEqual({ start: '', nodes: [graph.nodes[1]], edges: [] })
    })

    // React Flow can emit a remove change directly from its Delete shortcut;
    // ConfigPanel's delete callback cannot protect that second path.
    // Counterfactual: handle only the panel and keyboard deletion leaves the
    // same dangling start/edge under a different gesture.
    it('keyboard deletion also clears the start and incident edges', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 4, draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        const { container } = renderEditor()
        const startNode = container.querySelector('.react-flow__node[data-id="send1"]')
        if (startNode === null) {
            throw new Error('React Flow did not render send1')
        }

        await userEvent.click(startNode)
        await userEvent.keyboard('{Delete}')
        await waitFor(() => expect(screen.queryByText('send1')).not.toBeInTheDocument())
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/publish'))).toBe(true))
        const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        expect(JSON.parse(String(call?.[1]?.body)).graph).toEqual({ start: '', nodes: [graph.nodes[1]], edges: [] })
    })

    // Counterfactual: initialise a newly added card's isStart flag but forget
    // FlowEditor's authoritative startId; the badge lies and the graph sends ''.
    it('makes the first node added to an empty graph the real start', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 1, draft_revision: 1 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { start: null, nodes: [], edges: [] } })

        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))
        expect(screen.getByText('START')).toBeInTheDocument()
        expect(editorSummary()).toHaveTextContent(/start:\s*exit1/)
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/publish'))).toBe(true))
        const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        expect(JSON.parse(String(call?.[1]?.body)).graph.start).toBe('exit1')
    })

    // Counterfactual: ConfigPanel renders its button but FlowEditor drops the
    // callback, so the badge/header/wire graph all keep the old start.
    it('makes a selected existing node the serialized start', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ version: 4, draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        await userEvent.click(canvasNode('exit1'))
        await userEvent.click(screen.getByRole('button', { name: /make start node/i }))
        expect(editorSummary()).toHaveTextContent(/start:\s*exit1/)
        await userEvent.click(screen.getByRole('button', { name: /publish/i }))

        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => String(url).endsWith('/publish'))).toBe(true))
        const call = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/publish'))
        expect(JSON.parse(String(call?.[1]?.body)).graph.start).toBe('exit1')
    })

    // Counterfactual: pass the host map straight through rather than merging it;
    // the custom control works while the adjacent built-in becomes unregistered.
    it('merges a host field control with the built-ins in the editor assembly', async () => {
        const Town: FieldControl = ({ value }) => <p>host town control: {String(value)}</p>
        const customPalette = palette.map((definition) =>
            definition.type === 'app.send'
                ? {
                      ...definition,
                      fields: definition.fields.flatMap((field, index) =>
                          index === 0 ? [{ ...field, key: 'destination', type: 'town', label: 'Destination' }, field] : [field],
                      ),
                      default_config: { destination: 'Bucharest', template: 'welcome' },
                  }
                : definition,
        )
        renderEditor({
            palette: customPalette,
            graph: {
                ...graph,
                nodes: graph.nodes.map((node) =>
                    node.id === 'send1' ? { ...node, config: { destination: 'Bucharest', template: 'welcome' } } : node,
                ),
            },
            controls: { town: Town },
        })

        await userEvent.click(canvasNode('send1'))

        expect(screen.getByText('host town control: Bucharest')).toBeInTheDocument()
        expect(screen.getByLabelText('Template')).toHaveValue('welcome')
    })

    // Counterfactual: FlowEditor ignores nodeRenderers even though Canvas's unit
    // test accepts an already-populated map; the host body never reaches Canvas.
    it('passes a host node renderer through the editor assembly', () => {
        const Mine: NodeRenderer = ({ data }) => <p>host node body: {data.id}</p>

        renderEditor({ nodeRenderers: { 'app.send': Mine } })

        expect(screen.getByText('host node body: send1')).toBeInTheDocument()
    })

    // Counterfactual: provide FieldOptionsContext in a unit test but omit or
    // mis-template it in FlowEditor; a dynamic select becomes silently empty.
    it('routes a named option-load failure through ConfigPanel', async () => {
        const dynamicPalette = palette.map((definition) =>
            definition.type === 'app.send'
                ? { ...definition, fields: definition.fields.map((field, index) => (index === 0 ? { ...field, type: 'select', dynamic_options: true } : field)) }
                : definition,
        )
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string | URL | Request) =>
                String(url).includes('/options')
                    ? Promise.resolve(Response.json({ message: 'broken options' }, { status: 500 }))
                    : Promise.resolve(Response.json({ draft_revision: 8 })),
            ),
        )
        renderEditor({ palette: dynamicPalette })

        await userEvent.click(canvasNode('send1'))

        await waitFor(() => expect(screen.getByText(/Could not load.*HTTP 500/)).toBeInTheDocument())
    })
})
```

- [ ] **Step 10: Run and confirm failure**

```bash
npm test -- resources/js/editor/FlowEditor.test.tsx
```

Expected: test collection fails on the unresolved `./FlowEditor` import; none of the 19 integration tests runs before the component exists. Every case is mandatory. If React Flow exposes a new jsdom requirement, fix the shared shims from Task 5 or report **BLOCKED** with the exact incompatibility; do not delete, skip or weaken a FlowEditor case.

- [ ] **Step 11: Create `resources/js/editor/FlowEditor.tsx`**

```tsx
import { addEdge, applyEdgeChanges, applyNodeChanges, type Connection, type OnEdgesChange, type OnNodesChange } from '@xyflow/react'
import { useCallback, useMemo, useRef, useState } from 'react'
import { Canvas, type NodeflowEdge, type NodeflowNode } from '../canvas/Canvas'
import type { NodeRendererMap } from '../canvas/context'
import { mergeControls } from '../controls'
import type { ControlMap } from '../controls/types'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import { toCanvas } from '../graph/toCanvas'
import { defsByType, toGraph } from '../graph/toGraph'
import type { EditorUrls, FlowSummary, Graph, NodeErrorEntry, NodeTypePayload, TriggerPayload } from '../graph/types'
import { send } from '../http'
import { canConnect, nextNodeId } from './ids'
import { Palette } from './Palette'
import { ConfigPanel } from './ConfigPanel'
import { interpretPublish, type PublishOutcome } from './publish'
import { useAutosave } from './useAutosave'

export type FlowEditorProps = {
    /** The first five are exactly the edit() props, so a host page can spread them. */
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    triggers: TriggerPayload[]
    urls: EditorUrls
    /** Merged over the package defaults (E5). */
    controls?: ControlMap
    /** Merged over the default node renderer (5.8). Same mechanism, learned once. */
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
    className?: string
}

/**
 * The editor.
 *
 * It owns every piece of state and passes it down, which is what lets Canvas be
 * shared with Plan 4's read-only run view (E7). It imports nothing from
 * @inertiajs/react: props arrive as props from the host's three-line page, and
 * every request goes through http.ts - so the host's page is the only thing that
 * knows Inertia exists, and this component is testable with no Inertia runtime.
 *
 * Publish never sends a graph this editor knows is unpublishable. The one case
 * that matters is an edge whose output could not be resolved: the throwaway
 * prototype sent 'default' and the server answered with a message about an
 * output the author had never chosen.
 */
export function FlowEditor({
    flow,
    graph,
    palette,
    triggers,
    urls,
    controls,
    nodeRenderers = {},
    autosaveDebounceMs,
    className,
}: FlowEditorProps) {
    const defs = useMemo(() => defsByType(palette), [palette])
    const mergedControls = useMemo(() => mergeControls(controls), [controls])
    const optionsCache = useRef(new Map<string, Record<string, string>>())
    const optionsSource = useMemo(() => ({ template: urls.options, cache: optionsCache.current }), [urls.options])

    const initial = useMemo(() => toCanvas(graph), [graph])
    const [nodes, setNodes] = useState<NodeflowNode[]>(initial.nodes)
    const [edges, setEdges] = useState<NodeflowEdge[]>(initial.edges)
    const [startId, setStartId] = useState(graph.start ?? '')
    const [selectedId, setSelectedId] = useState<string | null>(null)
    const [outcome, setOutcome] = useState<PublishOutcome | null>(null)
    const [publishing, setPublishing] = useState(false)

    // isStart lives on each node's data because that is what the card reads, so
    // it is recomputed rather than stored twice.
    const canvasNodes = useMemo(
        () => nodes.map((node) => (node.data.isStart === (node.id === startId) ? node : { ...node, data: { ...node.data, isStart: node.id === startId } })),
        [nodes, startId],
    )

    const built = useMemo(() => toGraph({ nodes: canvasNodes, edges }, startId, defs), [canvasNodes, edges, startId, defs])

    const autosave = useAutosave({
        url: urls.draft,
        initialRevision: flow.draft_revision,
        graph: built.graph,
        debounceMs: autosaveDebounceMs,
    })

    const cleanRemoved = useCallback((removed: Set<string>) => {
        if (removed.size === 0) {
            return
        }

        setEdges((current) => current.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)))
        setStartId((current) => (current !== '' && removed.has(current) ? '' : current))
        setSelectedId((current) => (current !== null && removed.has(current) ? null : current))
    }, [])

    const deleteNodes = useCallback(
        (removed: Set<string>) => {
            setNodes((current) => current.filter((node) => !removed.has(node.id)))
            cleanRemoved(removed)
        },
        [cleanRemoved],
    )

    const onNodesChange: OnNodesChange<NodeflowNode> = useCallback(
        (changes) => {
            const removed = new Set(changes.filter((change) => change.type === 'remove').map((change) => change.id))
            setNodes((current) => applyNodeChanges(changes, current))
            cleanRemoved(removed)
        },
        [cleanRemoved],
    )

    const onEdgesChange: OnEdgesChange<NodeflowEdge> = useCallback(
        (changes) => setEdges((current) => applyEdgeChanges(changes, current)),
        [],
    )

    const onConnect = useCallback(
        (connection: Connection) => {
            const sourceType = nodes.find((node) => node.id === connection.source)?.data.type

            // Refused at the gesture rather than at publish: an edge whose output
            // cannot be attributed is not a connection, it is a question.
            if (!canConnect(sourceType, connection.sourceHandle, defs)) {
                return
            }

            setEdges((current) => addEdge({ ...connection, label: connection.sourceHandle ?? undefined }, current))
        },
        [nodes, defs],
    )

    const addNode = useCallback(
        (def: NodeTypePayload) => {
            const id = nextNodeId(def.type, new Set(nodes.map((node) => node.id)))

            setNodes((current) => [
                ...current,
                {
                    id,
                    type: 'nodeflowNode' as const,
                    position: { x: 120 + current.length * 30, y: 120 + current.length * 20 },
                    data: { id, type: def.type, config: { ...def.default_config }, isStart: current.length === 0 },
                },
            ])

            if (nodes.length === 0) {
                setStartId(id)
            }

            setSelectedId(id)
        },
        [nodes],
    )

    const selected = canvasNodes.find((node) => node.id === selectedId) ?? null

    const nodeErrorEntries: NodeErrorEntry[] = outcome?.kind === 'semantic' ? Object.values(outcome.byNode).flat() : []

    const nodeErrors = useMemo(() => {
        if (outcome?.kind !== 'semantic') {
            return {}
        }

        return Object.fromEntries(
            Object.entries(outcome.byNode).map(([id, entries]) => [id, entries.map((entry) => (entry.field === null ? entry.message : `${entry.field}: ${entry.message}`))]),
        )
    }, [outcome])

    const publish = useCallback(async () => {
        if (built.unresolved.length > 0) {
            setOutcome({
                kind: 'failed',
                message: `${built.unresolved.length} connection(s) do not say which output they leave from. Drag each one from the output handle you mean, then publish again.`,
            })

            return
        }

        setPublishing(true)
        const ready = await autosave.preparePublish()

        if (!ready) {
            setPublishing(false)
            setOutcome({ kind: 'failed', message: 'Resolve the draft save or conflict before publishing this flow.' })

            return
        }

        let result
        try {
            result = await send('POST', urls.publish, { graph: built.graph })
        } catch (reason: unknown) {
            autosave.finishPublish()
            setPublishing(false)
            setOutcome({ kind: 'failed', message: `Could not reach the server to publish this flow: ${String(reason)}` })

            return
        }

        const next = interpretPublish(result, new Set(canvasNodes.map((node) => node.id)))
        autosave.finishPublish(next.kind === 'published' ? next.revision : undefined)
        setPublishing(false)
        setOutcome(next)
    }, [built, urls.publish, canvasNodes, autosave])

    const trigger = triggers.find((candidate) => candidate.type === flow.trigger_type)

    return (
        <FieldOptionsContext.Provider value={optionsSource}>
            <section className={className ?? 'space-y-4'}>
                <header className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2">
                    <div>
                        <h1 className="text-sm font-semibold text-foreground">{flow.name}</h1>
                        <p className="text-xs text-muted-foreground">
                            {trigger?.label ?? flow.trigger_type} - published v{flow.version ?? '-'} - start:{' '}
                            <span className="font-mono">{startId || 'none'}</span>
                        </p>
                        {trigger?.description && <p className="text-xs text-muted-foreground">{trigger.description}</p>}
                    </div>

                    <p className="ml-auto text-xs text-muted-foreground" aria-live="polite">
                        {autosave.status === 'saving' && 'Saving...'}
                        {autosave.status === 'saved' && `Draft saved (revision ${autosave.revision})`}
                        {autosave.status === 'error' && autosave.message}
                    </p>

                    <button
                        type="button"
                        onClick={() => void publish()}
                        disabled={publishing}
                        className="rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-50"
                    >
                        {publishing ? 'Publishing...' : 'Publish'}
                    </button>
                </header>

                {autosave.conflict !== null && (
                    <div role="alert" className="flex flex-wrap items-center gap-3 border-b border-destructive/50 bg-destructive/5 px-4 py-2">
                        <p className="text-xs text-destructive">
                            {autosave.message} Their draft is revision {autosave.conflict.revision}; yours has not been saved.
                        </p>
                        <button
                            type="button"
                            className="rounded-md border border-input px-2 py-1 text-xs"
                            onClick={() => autosave.resolveConflict('mine')}
                        >
                            Keep mine
                        </button>
                        <button
                            type="button"
                            className="rounded-md border border-input px-2 py-1 text-xs"
                            onClick={() => {
                                const theirs = autosave.conflict
                                if (theirs === null) {
                                    return
                                }
                                const replacement = toCanvas(theirs.graph)
                                const canonical = toGraph(
                                    { nodes: replacement.nodes, edges: replacement.edges },
                                    theirs.graph.start ?? '',
                                    defs,
                                ).graph
                                setNodes(replacement.nodes)
                                setEdges(replacement.edges)
                                setStartId(theirs.graph.start ?? '')
                                setSelectedId(null)
                                autosave.resolveConflict('theirs', canonical)
                            }}
                        >
                            Use theirs
                        </button>
                    </div>
                )}

                {outcome !== null && outcome.kind !== 'published' && (
                    <div role="alert" className="border-b border-destructive/50 bg-destructive/5 px-4 py-2 text-xs text-destructive">
                        {outcome.kind === 'failed' && <p>{outcome.message}</p>}

                        {outcome.kind === 'structural' && (
                            <>
                                <p className="font-medium">The editor sent a graph the server could not read. This is a bug in the editor, not in your flow.</p>
                                <ul className="mt-1 list-inside list-disc">
                                    {outcome.developer.map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            </>
                        )}

                        {outcome.kind === 'semantic' && (
                            <>
                                <p className="font-medium">This flow could not be published:</p>
                                <ul className="mt-1 list-inside list-disc">
                                    {[...new Set([...outcome.banner, ...outcome.unplaceable])].map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </div>
                )}

                {outcome?.kind === 'published' && (
                    <p className="border-b border-border px-4 py-2 text-xs text-foreground">Published v{outcome.version}.</p>
                )}

                <div className="flex min-h-0 flex-1">
                    <aside className="w-52 shrink-0 overflow-auto border-r border-border p-2">
                        <Palette palette={palette} onAdd={addNode} />
                    </aside>

                    <main className="min-w-0 flex-1">
                        <Canvas
                            nodes={canvasNodes}
                            edges={edges}
                            defs={defs}
                            renderers={nodeRenderers}
                            nodeErrors={nodeErrors}
                            onNodesChange={onNodesChange}
                            onEdgesChange={onEdgesChange}
                            onConnect={onConnect}
                            onNodeClick={setSelectedId}
                        />
                    </main>

                    <aside className="w-72 shrink-0 overflow-auto border-l border-border p-3">
                        {selected === null ? (
                            <p className="text-xs text-muted-foreground">Select a node to configure it.</p>
                        ) : (
                            <ConfigPanel
                                node={selected.data}
                                def={defs[selected.data.type]}
                                controls={mergedControls}
                                errors={nodeErrorEntries.filter((entry) => entry.node === selected.id)}
                                isStart={startId === selected.id}
                                onConfigChange={(key, value) =>
                                    setNodes((current) =>
                                        current.map((node) =>
                                            node.id === selected.id ? { ...node, data: { ...node.data, config: { ...node.data.config, [key]: value } } } : node,
                                        ),
                                    )
                                }
                                onMakeStart={() => setStartId(selected.id)}
                                onDelete={() => deleteNodes(new Set([selected.id]))}
                            />
                        )}
                    </aside>
                </div>
            </section>
        </FieldOptionsContext.Provider>
    )
}
```

- [ ] **Step 12: Run the assembled editor green before moving to the public surface**

```bash
npm test -- resources/js/editor/FlowEditor.test.tsx && npm run types:check
```

Expected: all 19 mandatory FlowEditor cases pass and `tsc` is silent. Fix the component or tests here; do not let the index task obscure an assembly failure.

- [ ] **Step 13: Write the failing public-entry-point test**

Create `resources/js/index.test.ts` before `index.ts` exists:

```ts
import { describe, expect, expectTypeOf, it } from 'vitest'
import { Canvas, controlFor, defaultControls, defaultNodeRenderer, FieldOptionsContext, FlowEditor, mergeControls, rendererFor, Unregistered } from '.'
import type {
    CanvasContextValue,
    CanvasEdge,
    CanvasNode,
    CanvasProps,
    ControlMap,
    EditorUrls,
    FieldControl,
    FieldControlProps,
    FieldPayload,
    FlowEditorProps,
    FlowSummary,
    Graph,
    GraphEdge,
    GraphNode,
    NodeCardData,
    NodeErrorEntry,
    NodeflowEdge,
    NodeflowNode,
    NodeRenderer,
    NodeRendererMap,
    NodeRendererProps,
    NodeTypePayload,
    PublishErrorBody,
    TriggerPayload,
} from '.'

type EveryPublicType =
    | CanvasContextValue | CanvasEdge | CanvasNode | CanvasProps | ControlMap | EditorUrls
    | FieldControl | FieldControlProps | FieldPayload | FlowEditorProps | FlowSummary | Graph
    | GraphEdge | GraphNode | NodeCardData | NodeErrorEntry | NodeflowEdge | NodeflowNode
    | NodeRenderer | NodeRendererMap | NodeRendererProps | NodeTypePayload | PublishErrorBody | TriggerPayload

describe('public editor entry point', () => {
    // Counterfactual: internal tests import source files directly, so forgetting
    // one package export stays invisible until a host's Vite build fails.
    it('exports every promised runtime and type surface', () => {
        expect([Canvas, controlFor, defaultControls, defaultNodeRenderer, FieldOptionsContext, FlowEditor, mergeControls, rendererFor, Unregistered])
            .not.toContain(undefined)
        expectTypeOf<EveryPublicType>().not.toBeNever()
        expectTypeOf<FlowEditorProps>().toHaveProperty('urls')
    })
})
```

- [ ] **Step 14: Run and confirm both public-entry-point gates are red**

```bash
npm test -- resources/js/index.test.ts
npm run types:check
```

Expected: Vitest collection fails because the package entry point does not exist, and `tsc` separately fails on the missing runtime/type exports. Run both commands even though the first is expected non-zero; Vitest erases type-only imports and cannot substitute for the second gate.

- [ ] **Step 15: Create `resources/js/index.ts`**

```ts
/**
 * The package's entire public surface. Everything else under resources/js is
 * internal and free to move.
 *
 * FlowRun and useOverlayPolling belong here too (5.5) and land in Plan 4;
 * exporting a name that does not exist yet would fail tsc, so they are absent
 * rather than stubbed.
 */
export { Canvas } from './canvas/Canvas'
export { defaultNodeRenderer, rendererFor } from './canvas/NodeCard'
export { controlFor, defaultControls, mergeControls, Unregistered } from './controls'
export { FieldOptionsContext } from './controls/useFieldOptions'
export { FlowEditor } from './editor/FlowEditor'

export type { CanvasProps, NodeflowEdge, NodeflowNode } from './canvas/Canvas'
export type { CanvasContextValue, NodeRenderer, NodeRendererMap, NodeRendererProps } from './canvas/context'
export type { ControlMap, FieldControl, FieldControlProps } from './controls/types'
export type { FlowEditorProps } from './editor/FlowEditor'
export type {
    CanvasEdge,
    CanvasNode,
    EditorUrls,
    FieldPayload,
    FlowSummary,
    Graph,
    GraphEdge,
    GraphNode,
    NodeCardData,
    NodeErrorEntry,
    NodeTypePayload,
    PublishErrorBody,
    TriggerPayload,
} from './graph/types'
```

- [ ] **Step 16: Run the targeted public-entry-point green gate**

```bash
npm test -- resources/js/index.test.ts && npm run types:check
```

Expected: the runtime export test passes and every imported public type resolves under `tsc`.

- [ ] **Step 17: Run everything**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 123 Vitest tests pass across 13 files, `tsc` is silent with no graph/React Flow boundary casts, and all 325 PHP tests pass. If the runner reports a different count, reconcile it against the mandatory cases above before changing this line; never paper over a missing collection.

- [ ] **Step 18: Commit**

```bash
git add resources/js
git commit -m "feat: assemble the flow editor and export the package's public surface"
```

---

## Chunk 9: Documentation and real-app acceptance

### Task 9: Documentation, and deleting the lines that deny what now exists

**Files:**
- Create: `docs/08-editor-client.md`
- Modify: `docs/02-integration.md` (the "What you have not wired yet" section)
- Modify: `README.md`

**Interfaces:** none. This task ships no code.

The spec delivery marker and open-issues reconciliation deliberately wait until Task 11, after the real-app check. Documentation may describe a built package here; the project record may not call Plan 3 delivered before its stated acceptance criterion has passed.

**This task exists because of a recorded, three-time failure.** Plan 1 shipped a line denying the scaffolding generator it added. Plan 2 shipped one claiming a cross-tenant id is a 404 when the default made it a 200. Plan 3a shipped a "There is no UI" section two sections below the routes that render one. Step 1 is a grep, and it is not optional.

- [ ] **Step 1: Find every line that denies what this branch built**

```bash
rg -ni 'no bundled front|no front end|there is no ui|not built yet|no canvas|missing is the react|no field controls|no palette' README.md docs/ -g '*.md' -g '!docs/prompts/**'
```

Every hit outside `docs/superpowers/` is a line to rewrite. `docs/prompts/` is excluded deliberately: the Plan 3b handoff records what was false at the previous session boundary and must remain historical evidence, not be rewritten as if it described the new branch. Record the list in the task report before changing anything, so a reviewer can check the list against the diff.

- [ ] **Step 2: Write `docs/08-editor-client.md`**

The document a client author reads. It must contain, in this order:

1. **What the package ships** - TypeScript source under `resources/js`, compiled by the host's Vite against the host's React and Tailwind tokens (D2), exporting components and not pages (E4). No bundle, no npm package, no CSS file of its own.
2. **Five wiring steps, each with the exact snippet and the exact symptom of skipping it.** Four are from 5.6; the fifth was found while planning this work:
   - **The Vite alias.** `'@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js')`. Symptom if missing: the build fails to resolve the import. **Loud.**
   - **The tsconfig path mapping.** `"@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"]` plus `"@nodeflow/editor/*"`. Symptom if missing: the build succeeds and both the host's `tsc` and their editor's IntelliSense fail on the import. **Quiet.**
   - **The Tailwind `@source` line.** `@source '../../vendor/atram/laravel-nodeflow/resources/js';` in the host's CSS entry. Symptom if missing: the build succeeds and the editor renders, but utilities used only in the package source (for example `min-h-[32rem]` in `Canvas.tsx`) are absent; utilities the host happens to use elsewhere may mask part of the damage. Tailwind v4's automatic source detection deliberately skips gitignored paths, and `vendor/` is gitignored. **Quiet, and the worst of the five.**
   - **`@xyflow/react` in the host's `package.json`.** The host's Vite compiles our source, so npm installs nothing on our behalf and an alias into `vendor/` does not pull a package's dependencies. Symptom if missing: the build fails to resolve `@xyflow/react`. **Loud.**
   - **`resolve.dedupe: ['react', 'react-dom', '@xyflow/react']`, required when the package is symlinked for local development.** Vite resolves symlinks to their real path, so a bare `react` import inside `resources/js` resolves from the *package's* `node_modules` - which exists, because Vitest and `tsc` need it - rather than the host's. Two Reacts in one page means "Invalid hook call" or "Cannot read properties of null (reading 'useState')" the first time the editor mounts. A host that installed the package with Composer normally has no `node_modules` inside `vendor/atram/laravel-nodeflow`, so resolution walks up to theirs and the problem does not arise; `dedupe` is harmless there and is the one line that makes both cases work. **Quiet, and it looks like a React bug rather than a config one.**
3. **The thin page**, with the note that it is three things at once - the Inertia resolver entry, the layout wrap and the theming seam:

   ```tsx
   import { FlowEditor } from '@nodeflow/editor'
   import type { FlowEditorProps } from '@nodeflow/editor'

   export default function Page(props: FlowEditorProps) {
       return <FlowEditor {...props} />
   }
   ```

   With the explanation: the file must live in the host's own `resources/js/pages` at the path the controller renders - `nodeflow/editor` - because Inertia's resolver globs the host's pages and a page inside `vendor/` is never found. Wrap it in the host's layout here, or let the host's global `layout` resolver do it.
4. **Registering a control for a custom field type** (E5), with `Field::custom('destination', 'town')` on one side and `controls={{ town: TownPicker }}` on the other, the `FieldControlProps` contract in full, and the statement that an unregistered type renders a visible named error and never a text input, with the reason.
5. **Overriding a node's appearance** (5.8), with `nodeRenderers={{ 'yaya.send_message': MyCard }}`, `NodeRendererProps` in full, and the fact that **the handles are not the renderer's job** - so an override cannot accidentally make a node unwireable.
6. **What the editor does with the endpoints**: autosave on a debounce echoing `draft_revision`; a 409 offering "keep mine" or "use theirs" rather than picking; publish waiting every accepted draft PUT and holding later edits behind a barrier until POST completes; publish refusing to send an edge whose output it cannot resolve; the two 422 shapes and which one is shown to the author.
7. **What is not here yet**: the run view (`FlowRun`) lands in Plan 4, and `nodeflow:install` (which will verify all five wiring steps) lands in Plan 5. Until then the five steps are manual, and three of the five fail quietly.

- [ ] **Step 3: Rewrite `docs/02-integration.md`'s "What you have not wired yet"**

The section currently opens "There is **no bundled front end**" and closes by explaining how to publish flows programmatically. The programmatic path is still true and stays. The denial goes. Verify the anchor first:

```bash
rg -n 'no bundled front end' docs/02-integration.md
```

Expected: exactly one hit. Replace the opening paragraph with a pointer to `08-editor-client.md`, keep the programmatic example under a heading such as "Building flows without the editor", and keep the closing note that the JSON shape is the same one the editor's routes consume. Rename the section heading to something that is true - "Wiring the editor's front end" - and check whether anything links to the old anchor:

```bash
rg -n 'what-you-have-not-wired-yet' README.md docs/ -g '*.md'
```

Fix every hit the grep finds.

- [ ] **Step 4: Update `README.md`**

Replace the current `> **Status: foundation.**` block with this factual state (reflowing Markdown is fine; do not weaken any limitation):

> **Status: editor foundation.** The durable headless engine, node generator, opt-in editor routes and React `FlowEditor` client ship. The run-inspection UI (`FlowRun`) is still Plan 4 work, and domain-specific nodes remain yours to write. The package is verified by 325 PHP tests and 123 client Vitest tests, but the interpreter has not yet been exercised against a real queue worker. See [Known limitations](docs/05-execution-model.md#known-limitations) before you depend on it.

Add `docs/08-editor-client.md` to the documentation table as **8. Editor client**, described as the five host-wiring requirements, thin Inertia page and extension props. This explicitly removes the current **203 tests** and **There is no UI yet** drift without implying the run view already exists.

- [ ] **Step 5: Verify the searches come back clean**

```bash
rg -ni 'no bundled front|there is no ui|not built yet|missing is the react' README.md docs/*.md
```

Expected: no hits. Then re-run Step 1's wider search with the same `!docs/prompts/**` exclusion and confirm every remaining hit is inside `docs/superpowers/` (where a historical record is correct) and is genuinely historical. Separately report, but do not edit, the two expected historical handoff hits in `docs/prompts/plan-3b-and-beyond.md`.

- [ ] **Step 6: Verify all suites after the documentation edits**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: 123 Vitest tests pass, `tsc` is silent, and all 325 PHP tests pass. Preserve the actual counts for Task 11's project record.

- [ ] **Step 7: Commit**

```bash
git add README.md docs
git commit -m "docs: document the editor client and delete the lines denying it exists"
```

---

## Chunk 10: Real-app acceptance

### Task 10: Replace the demo app's prototype with the real editor

**Exact worktree for every path and command in this task:**
`/Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor`.
Nothing in this task edits `~/Sites/test-workflow` directly.

**Files, all relative to that isolated demo worktree - a separate repository and a separate commit:**
- Modify: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Modify: `resources/js/pages/nodeflow/demo.tsx`
- Rewrite tests first: `tests/Feature/NodeflowEditorTest.php`
- Modify: `vite.config.ts`, `tsconfig.json`, `resources/css/app.css`, `routes/web.php`
- Modify: `app/Providers/NodeflowServiceProvider.php`
- Rewrite: `resources/js/pages/nodeflow/editor.tsx` (333 lines to about five)
- Delete: `app/Http/Controllers/NodeflowEditorController.php`

**Interfaces:** consumes the finished package. Produces nothing the package depends on.

**This is the acceptance criterion.** 11 puts Playwright and browser E2E out of scope explicitly because "the v1 acceptance criterion is already a real-app check". The demo symlinks the package at `vendor/atram/laravel-nodeflow`, so it sees package changes instantly - and it is therefore also the one place the `resolve.dedupe` requirement is real.

**Five things about this app that were verified while planning, so they are not surprises:**
1. **It defines no gates.** `rg -n 'Gate::' app/` returns nothing, and Plan 2's policies deny by default. Without Step 10, every editor route returns 403 and the page looks broken for a reason that has nothing to do with this plan.
2. **`@xyflow/react` is already a dependency** (`^12.11.3`), so wiring requirement 4 is already satisfied. Do not add it twice.
3. **`resources/js/app.tsx` has a global `layout` resolver** whose `default` case is `AppLayout`, so the thin page needs no layout wrap of its own.
4. **`resources/js/pages/nodeflow/demo.tsx` links to `/nodeflow/flows/${f.id}/edit` as a hardcoded path, twice.** Registering `Nodeflow::routes()` under `prefix('nodeflow')` keeps both links working. Do not give that group a `->name()` prefix here: the package handles one correctly (Task 2) but the demo's own wayfinder-generated route helpers regenerate from the route list, and renaming is a second change with its own blast radius.
5. **The demo baseline drifted behind Plan 3a.** With this feature package linked, `php artisan test` is 39/44 because its copied migration lacks all three draft columns and `nodeflow_flow_versions.tenant_id`; `npm run types:check` independently reports two errors: `demo.tsx:34` passes `Record<string, unknown>` where Inertia requires a convertible request payload, and the prototype `editor.tsx:177` sends its broad local `Graph` through `router.post`. Step 3 fixes only the unrelated demo helper; the prototype error is expected to remain until Step 11 deletes that implementation.

- [ ] **Step 1: Prepare the isolated demo worktree and point its ignored package link at this branch**

Before dispatching this task, the main coordinator uses `superpowers:using-git-worktrees` to create branch `feature/use-nodeflow-editor` at this exact path and gives it to the implementer:

```text
/Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor
```

Then, and only there, run these validated commands. `unlink` targets one verified ignored symlink; it must never target the demo root or a directory.

```bash
DEMO_WORKTREE=/Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor
PACKAGE_WORKTREE=/Users/mikelmao/.config/superpowers/worktrees/laravel-nodeflow/plan-3b-editor-client
PACKAGE_LINK="$DEMO_WORKTREE/vendor/atram/laravel-nodeflow"

cd "$DEMO_WORKTREE"
test "$(pwd -P)" = "$DEMO_WORKTREE"
composer install
npm ci
test -L "$PACKAGE_LINK"
OLD_PACKAGE_TARGET="$(realpath "$PACKAGE_LINK")"
test "$OLD_PACKAGE_TARGET" = /Users/mikelmao/Projects/laravel-nodeflow
test "$(realpath "$PACKAGE_WORKTREE")" = "$PACKAGE_WORKTREE"
unlink "$PACKAGE_LINK"
ln -s "$PACKAGE_WORKTREE" "$PACKAGE_LINK"
test "$(realpath "$PACKAGE_LINK")" = "$PACKAGE_WORKTREE"
git status --short
```

Report `OLD_PACKAGE_TARGET` and the final `realpath`; `vendor/` must remain ignored and absent from `git status`. Every later command begins from `$DEMO_WORKTREE`, and every later path is relative to it.

- [ ] **Step 2: Reproduce the two independent baseline failures**

From the exact worktree:

```bash
cd /Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor
php artisan test
npm run types:check
```

Expected control: Pest reports 44 tests collected, 39 passing and five failures caused by the copied Nodeflow migration missing `draft_graph`, `draft_updated_at`, `draft_revision`, and `nodeflow_flow_versions.tenant_id`. TypeScript separately reports the two observed payload errors at `demo.tsx:34` and prototype `editor.tsx:177`. If either control differs, stop and record the actual baseline before editing.

- [ ] **Step 3: Repair the demo's copied migration and its unrelated type drift**

The package is unreleased and the demo publishes a copy of its migration, so align that copied migration exactly rather than adding an upgrade migration. In `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`, after `current_version_id` add:

```php
            $t->json('draft_graph')->nullable();
            $t->timestamp('draft_updated_at')->nullable();
            $t->unsignedInteger('draft_revision')->default(0);
```

and make the first line of `nodeflow_flow_versions`:

```php
            $t->id();
            $t->string('tenant_id')->index();
```

In `resources/js/pages/nodeflow/demo.tsx`, narrow the helper's payload to the primitive shapes every call in this file actually sends:

```tsx
const post = (url: string, data: Record<string, string | number | boolean> = {}) =>
    router.post(url, data, { preserveScroll: true, preserveState: true });
```

Run the old demo contract as a control:

```bash
php artisan test
npm run types:check
```

Expected: all 44 existing PHP tests pass. `tsc` now reports only the prototype `editor.tsx:177` graph-payload error; `demo.tsx:34` is gone. That remaining error is part of the prototype Step 11 replaces, so do not cast it away just to make this intermediate control silent.

- [ ] **Step 4: Rewrite the three editor feature tests to the package route contract, before replacing the routes**

Rewrite `tests/Feature/NodeflowEditorTest.php`. Keep three tests, but pin the actual JSON/Inertia graph contract, authentication and lazy options. Every case carries its counterfactual:

```php
<?php

use App\Models\User;
use Database\Seeders\NodeflowDemoSeeder;
use Nodeflow\Models\Flow;

beforeEach(function () {
    $this->seed(NodeflowDemoSeeder::class);
    $this->flow = Flow::withoutTenancy()->where('name', 'Fast demo (seconds)')->firstOrFail();
    $this->user = User::where('organization_id', $this->flow->tenant_id)->firstOrFail();
    $this->actingAs($this->user);
    $this->withSession(['demo_tenant_id' => $this->flow->tenant_id]);
});

it('loads the package editor contract without resolving dynamic options eagerly', function () {
    // Counterfactual: leave the prototype controller in place and `urls` is
    // absent while the condition options are resolved during every page load.
    $response = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->get("/nodeflow/flows/{$this->flow->id}/edit")
        ->assertOk()
        ->assertJsonPath('component', 'nodeflow/editor')
        ->assertJsonPath('props.graph.start', 'welcome')
        ->assertJsonPath('props.flow.draft_revision', 0)
        ->assertJsonPath('props.urls.draft', "http://localhost/nodeflow/flows/{$this->flow->id}/draft")
        ->assertJsonPath('props.urls.publish', "http://localhost/nodeflow/flows/{$this->flow->id}/publish")
        ->assertJsonPath(
            'props.urls.options',
            "http://localhost/nodeflow/flows/{$this->flow->id}/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options",
        );

    $decoded = json_decode($response->getContent());
    $condition = collect($decoded->props->palette)->firstWhere('type', 'core.condition');
    $attribute = collect($condition->fields)->firstWhere('key', 'attribute');

    expect($attribute->dynamic_options)->toBeTrue()
        ->and($attribute->options)->toBeObject()
        ->and(get_object_vars($attribute->options))->toBe([]);
});

it('saves a positioned draft and publishes it as a new immutable version with the same token', function () {
    // Counterfactual: keep testing the prototype redirect and a package route
    // can drop position or the revision handshake while this demo stays green.
    $before = $this->flow->currentVersion->version;
    $graph = $this->flow->currentVersion->graph;
    $graph['nodes'][1]['config']['duration'] = '45 seconds';
    $graph['nodes'][1]['position'] = ['x' => 40.5, 'y' => 90.25];

    $this->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
        'graph' => $graph,
        'draft_revision' => 0,
    ])->assertOk()->assertJsonPath('draft_revision', 1);

    $this->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => $graph])
        ->assertOk()
        ->assertJsonPath('version', $before + 1)
        ->assertJsonPath('draft_revision', 1);

    $this->flow->refresh();
    expect($this->flow->currentVersion->graph['nodes'][1]['position'])->toBe(['x' => 40.5, 'y' => 90.25])
        ->and($this->flow->versions()->where('version', $before)->firstOrFail()->graph['nodes'][1]['config']['duration'])
        ->toBe('10 seconds');
});

it('returns semantic publish failures as JSON pinned to the offending node', function () {
    // Counterfactual: assert only a session flash and the React client receives
    // neither the 422 body nor the field/node coordinates it renders.
    $graph = $this->flow->currentVersion->graph;
    $graph['nodes'][1]['config']['duration'] = '1 dya';

    $this->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => $graph])
        ->assertStatus(422)
        ->assertJsonPath('node_errors.0.node', 'wait10s')
        ->assertJsonPath('node_errors.0.field', 'duration')
        ->assertJsonPath('errors.0', fn (string $message) => str_contains($message, 'wait10s'));

    expect($this->flow->fresh()->currentVersion->version)->toBe(1);
});
```

- [ ] **Step 5: Run and confirm the new graph-contract tests are red**

```bash
php artisan test tests/Feature/NodeflowEditorTest.php
```

Expected: all three cases fail against the prototype contract: the edit response lacks `urls`/lazy options, there is no draft PUT route, and publish redirects/uses session errors instead of returning JSON. Do not proceed if any case passes for the wrong reason.

- [ ] **Step 6: Add the Vite alias and the dedupe**

In the isolated worktree's `vite.config.ts`, add a `resolve` block beside `plugins`:

```ts
    resolve: {
        alias: {
            '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
        },
        // Required because vendor/atram/laravel-nodeflow is a symlink to the
        // package's working tree, which has its own node_modules (Vitest and tsc
        // need one). Vite resolves symlinks to their real path, so a bare `react`
        // import inside the package would resolve to the package's copy and the
        // page would mount two Reacts - "Invalid hook call" on first render.
        dedupe: ['react', 'react-dom', '@xyflow/react'],
    },
```

with `import path from 'node:path'` at the top.

- [ ] **Step 7: Add the tsconfig path mapping**

In `tsconfig.json`, extend `compilerOptions.paths`:

```json
        "paths": {
            "@/*": ["./resources/js/*"],
            "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
            "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
        },
```

- [ ] **Step 8: Add the Tailwind source line**

In `resources/css/app.css`, beside the existing `@source` lines:

```css
@source '../../vendor/atram/laravel-nodeflow/resources/js';
```

Verify the anchor is there and unique first:

```bash
rg -c "@source '../views'" resources/css/app.css
```

Expected: `1`.

- [ ] **Step 9: Switch the routes to the package's**

In `routes/web.php`, replace the second `nodeflow` group:

```php
Route::prefix('nodeflow')->name('nodeflow.')->group(function () {
    Route::get('flows/{flow}/edit', [\App\Http\Controllers\NodeflowEditorController::class, 'edit'])->name('edit');
    Route::post('flows/{flow}/publish', [\App\Http\Controllers\NodeflowEditorController::class, 'publish'])->name('publish');
});
```

with:

```php
// The package's own editor routes, opt-in and inside this app's group so the
// prefix and middleware stay this app's choice. No ->name() prefix here: the
// demo's links and its generated route helpers are keyed on the package's own
// names.
Route::middleware(['web', 'auth'])->prefix('nodeflow')->group(
    fn () => \Nodeflow\Nodeflow::routes()
);
```

Then delete `app/Http/Controllers/NodeflowEditorController.php`.

- [ ] **Step 10: Define the four gates**

Plan 2's policies deny when the host has defined no gate, so without this every route here is a 403. In `app/Providers/NodeflowServiceProvider.php`'s `boot()`:

```php
        // Plan 2's policies delegate to these and deny when they are undefined,
        // so a host that wires nothing gets a blanket 403 by design. This is a
        // demo: any authenticated user may do anything.
        foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
            Gate::define("nodeflow.{$ability}", fn ($user, $flow = null) => $user !== null);
        }
```

with `use Illuminate\Support\Facades\Gate;` added.

- [ ] **Step 11: Replace the prototype page**

Overwrite `resources/js/pages/nodeflow/editor.tsx` entirely:

```tsx
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'
import { Head } from '@inertiajs/react'

/**
 * The whole page. E4: the package exports components, not pages - Inertia's
 * resolver globs this app's own pages directory, so a page inside vendor/ would
 * never be found. This file is the resolver entry, the layout seam (app.tsx's
 * global resolver wraps it in AppLayout) and the theming seam all at once.
 */
export default function Page(props: FlowEditorProps) {
    return (
        <>
            <Head title={`Edit ${props.flow.name}`} />
            <FlowEditor {...props} />
        </>
    )
}
```

Read the old file's 333 lines before deleting them, and confirm nothing in it was doing something this plan has not accounted for. Its findings are already in the spec (the `multiselect` gap, the undefined `options_source` convention, the `?? 'default'` bug); if you find a fourth, stop and report it rather than dropping it.

- [ ] **Step 12: Run the demo's PHP tests, build, and verify the Tailwind source line actually took**

```bash
php artisan test && npm run types:check && npm run build
```

All three must exit 0. Then verify the class scan reached into `vendor/` - a "scripted edit that silently matched nothing" is a recorded failure mode of this project, and this one fails by producing a correct-looking build:

```bash
rg -lF 'min-h-\[32rem\]' public/build/assets/*.css
```

Expected: all 44 PHP tests pass, `tsc` is silent, Vite exits 0, and the fixed-string search names at least one CSS asset. Generated arbitrary-value selectors escape their brackets, hence the literal `min-h-\[32rem\]` rather than the source spelling. That utility appears only in `Canvas.tsx`; it proves package-only utilities were scanned. Without `@source`, shared utilities the host also uses can remain while package-only ones disappear, so a visual spot-check alone is not this proof.

- [ ] **Step 13: Initialise the isolated app and click through it in a browser**

This is a fresh worktree: create only its ignored environment/database, rebuild it from migrations, seed the known login, and serve the built assets. `migrate:fresh` destroys only this isolated worktree's SQLite database.

```bash
cd /Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor
test -f .env || cp .env.example .env
test -f database/database.sqlite || touch database/database.sqlite
rg -q '^APP_KEY=.+$' .env || php artisan key:generate
php artisan migrate:fresh --force
php artisan db:seed --class=NodeflowDemoSeeder --force
npm run build
php artisan serve --host=127.0.0.1 --port=8123
```

Keep the server running in its own terminal/session. Use the available `browser:control-in-app-browser` skill against `http://127.0.0.1:8123`; do not substitute an unreported visual guess. Log in at `/login` as `acme-1@example.test` / `password`, then visit `/nodeflow` once so `NodeflowDemoController` writes `demo_tenant_id` to the session. Open **Fast demo (seconds)**. Verify in this order, recording the flow id and the fresh node id:

1. The page renders inside the app's own layout, with the app's fonts and colours, and the palette lists grouped registered nodes.
2. Before selecting a condition, confirm the network log contains no `core.condition/fields/attribute/options` request. Select the existing `core.condition` card and confirm exactly one GET to `.../nodes/core.condition/fields/attribute/options`, returning the demo's four subject attributes.
3. Add **`core.wait` specifically** from the palette. Confirm one fresh card id and select it; this is the same node rewired, configured, published and broken below, not an arbitrary node.
4. The seeded graph has every source output occupied. Select and delete the existing edge `welcome --sent--> wait10s`; then connect `welcome`'s `sent` handle to the new wait's input, and connect the new wait's **`default`** handle to `wait10s`. Confirm both edge labels. This inserts the new node into the reachable graph rather than leaving an unpublishable orphan.
5. Drag the new wait to a visibly different position. Wait for the header to report the draft saved. With the recorded flow id, run in a second terminal:

   ```bash
   php artisan tinker --execute='$flow = Nodeflow\Models\Flow::withoutTenancy()->findOrFail(FLOW_ID); dump([$flow->draft_revision, $flow->draft_graph]);'
   ```

   Replace `FLOW_ID` with the recorded integer before running. Confirm the revision is greater than zero, the new id and both rewired edges exist, and every canvas node (especially the dragged wait) carries `position.x` and `position.y`.
6. In that same new `core.wait` panel, set duration to **1 minute**, publish, and confirm the displayed published version advances by one. Prove the frozen version and graph with the recorded integer id:

   ```bash
   php artisan tinker --execute='$flow = Nodeflow\Models\Flow::withoutTenancy()->findOrFail(FLOW_ID); dump([$flow->currentVersion->version, $flow->currentVersion->graph]);'
   ```

   Confirm the version is the newly displayed value and the graph contains the recorded fresh node id with `duration` equal to `1 minute`. Preserve that version number for the next comparison.
7. Break the same node deliberately: clear its required duration and publish. Its field message must appear on that node's card and in the banner. Prove the failed publish did not advance the frozen version while the broken graph did autosave:

   ```bash
   php artisan tinker --execute='$flow = Nodeflow\Models\Flow::withoutTenancy()->findOrFail(FLOW_ID); dump([$flow->currentVersion->version, $flow->draft_revision, $flow->draft_graph]);'
   ```

   Confirm `currentVersion.version` is byte-for-byte the Step 6 value, `draft_revision` advanced, and the recorded node's draft duration is empty/null.
8. Reload. The broken draft returns with the empty duration while `flow.version` still reports the successful version from Step 6. This proves draft precedence without confusing a failed publish for a successful one.

Stop the server session after recording the checks. A failure at any step blocks Task 11; do not convert the browser criterion into a documentation gap.

- [ ] **Step 14: Commit, in the demo repository**

```bash
git add -A
git commit -m "feat: use the package's FlowEditor and delete the prototype editor"
```

---

## Chunk 11: Acceptance record and two-repository completion gate

### Task 11: Reconcile the authoritative record only after real-app acceptance

**Files, in the package worktree:**
- Modify: `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md`
- Modify: `docs/superpowers/open-issues.md`

**Interfaces:** consumes Task 10's committed demo acceptance evidence; produces the final Plan 3 project record. It ships no runtime code.

- [ ] **Step 1: Capture evidence before writing the record**

From the package worktree, record the package implementation commit range through Task 9, the demo acceptance commit from Task 10, and fresh suite counts:

```bash
cd /Users/mikelmao/.config/superpowers/worktrees/laravel-nodeflow/plan-3b-editor-client
git log --oneline --reverse 62c9e66..HEAD
npm test
npm run types:check
vendor/bin/pest
git -C /Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor log -1 --oneline
git -C /Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor status --short
```

Expected: 123 Vitest tests, silent `tsc`, 325 PHP tests, and a clean demo worktree whose tip is Task 10's acceptance commit. The package range begins with Plan 3a's approved E2a/server work and ends at Task 9's user-facing documentation; do not guess the terminal hash.

- [ ] **Step 2: Mark §5 as built and Plan 3 delivered, with the accepted demo evidence**

In `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md`:

- Correct §4's binding **As built** tenancy paragraph before adding §5's block. It still describes Plan 2's `disabled` default and points at D-1 as a future inference, but E2a/Plan 3a already shipped `auto` plus `NoTenancyResolver`. Preserve the §4 heading's Plan 2 commit range and its historical prose below the block; update only the authoritative As-built statement to the current three modes and `auto` default/inference. Also distinguish the newly approved diagnostic-strengthening follow-up from the inference mechanism that already exists.
- In §3's table, mark Plan 3 delivered with the recorded package commit range, matching Plans 1 and 2, and include the separate demo acceptance commit in a short parenthetical.
- Change §5's heading to delivered and add an **As built** block immediately below it, in §4/§7.2's style. Record at minimum:
  - The `urls` prop, why it exists, and the two option-template sentinels.
  - Prefix-aware route-name resolution.
  - `FieldControlProps` is the specified six keys; an option-load failure is folded into `errors`, not added as a seventh key.
  - `NodeCard` owns handles; a `nodeRenderers` override owns only the body.
  - An unresolved edge output round-trips as `null` in a draft and blocks publish client-side, never becoming `'default'`.
  - `gridPosition` lives in `canvas/layout.ts` and `toCanvas` imports it.
  - `index.ts` honestly omits `FlowRun` until Plan 4.
  - Host wiring has a fifth requirement, `resolve.dedupe`, omitted by the prose in §5.6 and now proven by the symlinked demo; Plan 5's installer must verify it too.
  - The autosave debounce default; 409 halts the loop until an explicit choice.
  - Publish waits accepted draft PUTs, holds a POST barrier, adopts publish's revision, and queues edits made during POST as the next draft.
  - The accepted counts: 123 package Vitest tests, 325 package Pest tests and 44 demo Pest tests, plus the demo build/type/browser result and its commit.

- [ ] **Step 3: Update open issues without rewriting the history that already shipped**

In `docs/superpowers/open-issues.md`:

- Update "Last updated" to Task 10/11's acceptance date, hashes and counts.
- Preserve D-1's existing outcome verbatim: **E2a's `auto` inference already shipped in Plan 3a.** Append a dated follow-up decision saying only the newly proposed strengthening remains: record/diagnose when the host's tenancy binding caused `auto` to choose resolver mode. That strengthening is decided in favour, unimplemented, and belongs with D-2 in a dedicated security-hardening plan after 3b. Never relabel the already-shipped `auto` implementation as unimplemented.
- Mark D-2 decided in favour of implementing the durable execution-path tenant assertion, still unimplemented in that same dedicated follow-up.
- Add a GAP recording that §5.6 lists four host wiring requirements but the accepted symlinked app proved five; `resolve.dedupe` must join the other four in Plan 5's `nodeflow:install` checks.
- Record that both mandatory jsdom composition tests shipped and Task 10's real-browser acceptance passed. This is evidence, not a new gap.
- Leave F-1, F-2, G-1, G-2, G-3 and every C-series item open and otherwise untouched.

- [ ] **Step 4: Verify and commit the reconciliation**

```bash
git diff --check
rg -n 'Plan 3|As built|D-1|D-2|resolve.dedupe|123|325|44' docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md docs/superpowers/open-issues.md
npm test && npm run types:check && vendor/bin/pest
git add docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md docs/superpowers/open-issues.md
git commit -m "docs: record Plan 3 as accepted in the package and demo app"
```

Expected: the reconciliation search exposes every required datum, 123 Vitest tests pass, `tsc` is silent, all 325 package Pest tests pass, and only the two authoritative record files enter this commit.

### Coordinator-only completion sequence (after Task 11 review)

Do not merge either repository inside a task agent. Run one final whole-branch spec/code review of the package and a separate review of the demo commit. Then use `superpowers:finishing-a-development-branch`, presenting its exact four choices. If the user chooses local integration, the order is binding because the demo consumes the package:

1. Merge the package feature branch to `/Users/mikelmao/Projects/laravel-nodeflow` `main`. In package `main`, refresh both dependency trees and verify the merged state:

   ```bash
   cd /Users/mikelmao/Projects/laravel-nodeflow
   composer install
   npm ci
   vendor/bin/pest && npm test && npm run types:check
   ```

2. Before merging the demo branch, relink its worktree from the package feature worktree to merged package `main` through Composer, and prove the target:

   ```bash
   cd /Users/mikelmao/.config/superpowers/worktrees/test-workflow/use-nodeflow-editor
   composer reinstall atram/laravel-nodeflow
   test "$(realpath vendor/atram/laravel-nodeflow)" = /Users/mikelmao/Projects/laravel-nodeflow
   php artisan test && npm run types:check && npm run build
   rg -lF 'min-h-\[32rem\]' public/build/assets/*.css
   ```

   Repeat Task 10 Step 13's browser path against this relinked demo branch. This is the proof that merged package `main`, rather than the feature-worktree symlink, works in the real app.

3. Only after Step 2 passes, merge `feature/use-nodeflow-editor` into `/Users/mikelmao/Sites/test-workflow` `main`. Refresh the demo's clean dependency trees and verify the integrated demo commit:

   ```bash
   cd /Users/mikelmao/Sites/test-workflow
   composer install
   composer reinstall atram/laravel-nodeflow
   npm ci
   test "$(realpath vendor/atram/laravel-nodeflow)" = /Users/mikelmao/Projects/laravel-nodeflow
   php artisan test && npm run types:check && npm run build
   rg -lF 'min-h-\[32rem\]' public/build/assets/*.css
   ```

4. Run the same login/tenant/editor browser smoke on demo `main` against an explicitly fresh SQLite database; editing the already-run published migration does not upgrade any existing ignored database. Initialise the exact server environment before the smoke:

   ```bash
   cd /Users/mikelmao/Sites/test-workflow
   test -f .env || cp .env.example .env
   rg -q '^APP_KEY=.+$' .env || php artisan key:generate
   DEMO_ACCEPTANCE_DB=/tmp/laravel-nodeflow-plan3b-demo-main.sqlite
   test ! -e "$DEMO_ACCEPTANCE_DB"
   touch "$DEMO_ACCEPTANCE_DB"
   DB_CONNECTION=sqlite DB_DATABASE="$DEMO_ACCEPTANCE_DB" php artisan migrate:fresh --force
   DB_CONNECTION=sqlite DB_DATABASE="$DEMO_ACCEPTANCE_DB" php artisan db:seed --class=NodeflowDemoSeeder --force
   DB_CONNECTION=sqlite DB_DATABASE="$DEMO_ACCEPTANCE_DB" php artisan serve --host=127.0.0.1 --port=8123
   ```

   Keep that environment on the server process while repeating Task 10 Step 13. In the second terminal, prefix **every** repeated tinker command exactly as follows so it reads the same temporary database rather than `.env`'s default:

   ```bash
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/laravel-nodeflow-plan3b-demo-main.sqlite php artisan tinker --execute='$flow = Nodeflow\Models\Flow::withoutTenancy()->findOrFail(FLOW_ID); dump([$flow->draft_revision, $flow->draft_graph]);'
   DB_CONNECTION=sqlite DB_DATABASE=/tmp/laravel-nodeflow-plan3b-demo-main.sqlite php artisan tinker --execute='$flow = Nodeflow\Models\Flow::withoutTenancy()->findOrFail(FLOW_ID); dump([$flow->currentVersion->version, $flow->draft_revision, $flow->draft_graph, $flow->currentVersion->graph]);'
   ```

   Replace `FLOW_ID` in both commands with the integer recorded from this main-branch smoke.

   After stopping the server, delete only `/tmp/laravel-nodeflow-plan3b-demo-main.sqlite` (which the `test ! -e` guard proved this run created) and report that cleanup. Report both repository merge commits, all package/demo counts, build/type results, CSS proof and browser outcome. A green package `main` without the demo integration, or a green demo branch still linked to the package feature worktree, is not completion.

If the user chooses PRs or to keep the worktrees, do not perform these merges; state the same ordered gates as required merge/CI checks.

---

## Self-review against the spec

Run through this before dispatching Task 1.

**Spec coverage, section by section.**

| Spec | Where in this plan |
|---|---|
| 5.5 `resources/js` layout, `index.ts` as the only public surface | Tasks 1, 3, 4, 5, 6, 7, 8. `run/` is Plan 4 and `index.ts` says so |
| 5.6 four host-wiring requirements | Task 9 Step 2, plus a fifth found while planning; verified for real in Task 10 |
| 5.6 the thin page | Task 9 Step 2, Task 10 Step 11 |
| 5.7 `FieldControlProps`, the six keys | Task 3 Step 4 |
| 5.7 controls merge over defaults, unmatched type renders `Unregistered` | Task 3 Steps 12, 13 |
| 5.7 `multiselect` becomes a real control | Task 3 Step 10 |
| 5.7 `duration` as amount-plus-unit emitting only what `ValidDuration` accepts | Task 3 Steps 11, 15 |
| 5.7 option fetching is the package's job, in `useFieldOptions` | Task 4 |
| 5.8 node appearance overrides by the same prop-merge shape | Task 5 |
| 5.9 debounce, `PUT` with the last-seen token, 409 surfaces the conflict | Task 6 |
| E4 components not pages | Task 8's `index.ts`, Task 10's page |
| E5 prop-merge, never a global registry | Tasks 3, 5; the `useFieldOptions` cache too |
| E12 private dev-only `package.json` | Task 1 Step 2 |
| 9's Vitest list: round-trip; control selection; unregistered named error; duration emits only valid strings; autosave's 409 | Tasks 1, 3, 6 |
| 9's named case: the prototype's `?? 'default'` | Task 1 Steps 13, 17; Task 7's `canConnect`; Task 8's publish refusal |
| 10's error table, every row this plan owns | Draft/409 Task 6; publish's two 422s Task 7; options 404 and non-`OptionSource` Task 4; unregistered control Task 3 |
| 12 traceability: config schema declared once in PHP; a host can register a control; `multiselect`; positions round-trip | Tasks 1, 3, 8 |

**Rows of 10 this plan does not own:** the cross-tenant 404, the undefined-gate 403, `resolver`-mode null and `extract-node` are server-side and already shipped or belong to Plan 6. Task 10 Step 10 exercises the 403 path by fixing it in the demo.

**Type consistency, checked across tasks.** `NodeTypePayload` (Task 1) is what `defsByType`, `rendererFor`, `canConnect`, `Palette` and `Canvas` all take - not `PaletteNode`, which is the prototype's name and appears nowhere in this plan. `interpretPublish` returns `byNode: Record<string, NodeErrorEntry[]>` (raw entries, so `ConfigPanel` can route by `field` and `NodeCard` can format), and both consumers in Task 8 use it that way. `resolveOutput` and `canConnect` are two functions, deliberately: the first decides what a stored edge means, the second decides whether a gesture is allowed, and they agree on the single-output rule. `FieldControlProps` is six keys in Task 3 and six keys everywhere it is used.

**Interfaces produced but never consumed:** `outputHandleTop` and `NODE_WIDTH` are consumed by `NodeCard`; `HANDLE_ROW_HEIGHT` is consumed by `outputHandleTop`; `formatDuration`/`parseDuration` by `Duration` and its tests; `csrfHeaders` by `send`. `PublishErrorBody` in `graph/types.ts` is deliberately exported, though not consumed internally, so a host that wraps `FlowEditor` can type its own publish diagnostics; its Task 1 doc comment must say that explicitly.
