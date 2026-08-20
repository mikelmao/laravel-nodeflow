# Editor Client Implementation Plan (Plan 3b of 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

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
- **The package's JS imports nothing from the host.** No `@/` alias, no `@inertiajs/react`, no host component library. Every internal import is relative. The only bare imports permitted anywhere under `resources/js` are `react`, `react-dom`, `@xyflow/react`, and `@xyflow/react/dist/style.css`.
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
- **Test commands:** PHP `vendor/bin/pest` (filter: `vendor/bin/pest --filter='<pattern>'`). JS `npm test` (= `vitest run`) and `npm run types:check` (= `tsc --noEmit`), both from the package root. All three must be green at every commit from Task 1 onward.
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
| `tsconfig.json` | Strict, `noEmit`, `jsx: react-jsx`. What `npm run types:check` reads |
| `vitest.config.ts` | jsdom environment, explicit imports (no globals) |
| `resources/js/index.ts` | The public surface: `FlowEditor`, `defaultControls`, `defaultNodeRenderer`, `Unregistered`, types |
| `resources/js/types/css.d.ts` | `declare module '*.css'` so `tsc` accepts React Flow's stylesheet import |
| `resources/js/graph/types.ts` | The wire types: the four `edit()` props, the graph, the palette payload |
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
| `.gitignore` | `node_modules/` already ignored — verify, do not duplicate |
| `docs/02-integration.md` | `urls` in the edit-page props; rewrite "What you have not wired yet"; link to `08-editor-client.md` |
| `README.md` | Whatever it says about the missing front end |
| `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` | An "as built" block on §5; §3's table marks Plan 3 delivered |
| `docs/superpowers/open-issues.md` | "Last updated"; anything this plan found |

### Modified — demo app (`~/Sites/test-workflow`, a separate repo and a separate commit)

| Path | Change |
|---|---|
| `vite.config.ts` | The `@nodeflow/editor` alias and `resolve.dedupe` |
| `tsconfig.json` | The matching path mapping |
| `resources/css/app.css` | The Tailwind `@source` line into `vendor/` |
| `routes/web.php` | `Nodeflow::routes()` replaces the two hand-rolled editor routes |
| `app/Providers/NodeflowServiceProvider.php` | Define the four gates — the demo defines none today, so every editor route 403s |
| `resources/js/pages/nodeflow/editor.tsx` | 333 lines become the thin page |
| `app/Http/Controllers/NodeflowEditorController.php` | Deleted |

---
## Task 1: The dev toolchain and the pure graph transforms

**Files:**
- Create: `package.json`, `tsconfig.json`, `vitest.config.ts`
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
  - `resolveOutput(sourceHandle: string | null, def: NodeTypePayload | undefined): string | null`.
  - `toGraph(canvas: {nodes: CanvasNode[]; edges: CanvasEdge[]}, start: string, defs: Record<string, NodeTypePayload>): {graph: Graph; unresolved: CanvasEdge[]}`.
- Later tasks rely on: every name above. `canvas/layout.ts` is created here, not in Task 5, because `toCanvas` needs `gridPosition` and a task must not depend on a file a later task creates.

**Why `canvas/layout.ts` holds `gridPosition` even though `graph/` is meant to be the pure module:** §5.5 lists `layout.ts` under `canvas/`, and `layout.ts` is pure — no React, no `@xyflow/react`. `graph/toCanvas.ts` importing it introduces no cycle, because nothing in `canvas/layout.ts` imports from `graph/`. Keeping a second grid function inside `graph/` to avoid the import would be two sources of truth for a node's default position.

- [ ] **Step 1: Install the toolchain**

Run, from the package root:

```bash
npm install --save-dev --save-exact typescript vitest jsdom @vitejs/plugin-react \
  @testing-library/react @testing-library/dom @testing-library/user-event \
  react react-dom @types/react @types/react-dom @xyflow/react
```

Let npm write the versions — do not hand-pick them. `react`, `react-dom` and `@xyflow/react` are devDependencies **and** peerDependencies: dev so `tsc` and Vitest can resolve them, peer so the contract with the host is documented (E12 says peerDependencies exist for documentation).

`npm install` will create `node_modules/` and `package-lock.json`. `node_modules/` is already in `.gitignore` — confirm with `grep -n 'node_modules' .gitignore` rather than adding a second line. **Commit `package-lock.json`.**

- [ ] **Step 2: Make `package.json` private and dev-only (E12)**

Edit the `package.json` npm just created so it reads exactly like this, keeping the `devDependencies` versions npm chose:

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
    },
    "devDependencies": {
        "...": "as installed above"
    }
}
```

There is no `main`, no `module`, no `exports` and no `files` key, and there never will be: E12 forbids an npm publish target because publishing would reopen the two-sources-of-truth problem D2 closed. `"private": true` is the mechanical guard.

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

/** src/Nodes/NodeRegistry.php::palette() over src/Schema/NodeDefinition.php::toArray(). */
export type NodeTypePayload = {
    type: string
    label: string
    group: string
    icon: string | null
    description: string | null
    outputs: string[]
    fields: FieldPayload[]
    default_config: Record<string, unknown>
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
    config: Record<string, unknown>
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
    output: string | null
}

export type Graph = { start: string; nodes: GraphNode[]; edges: GraphEdge[] }

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
 * never by the type of `errors`.
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

const graph: Graph = {
    start: 'n1',
    nodes: [
        { id: 'n1', type: 'app.send', config: { template: 'welcome' }, position: { x: 40, y: 80 } },
        { id: 'n2', type: 'core.exit', config: {} },
    ],
    edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
}

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

        expect(first).toEqual(again)
        expect(first).not.toEqual({ x: 0, y: 0 })
    })

    // Counterfactual: set isStart on every node, or on none, and the START badge
    // stops meaning anything.
    it('marks exactly the start node', () => {
        expect(toCanvas(graph).nodes.map((n) => n.data.isStart)).toEqual([true, false])
    })

    // Counterfactual: pass `config: n.config` without the `?? {}` and a node
    // published with no config crashes the config panel on selection.
    it('defaults a missing config to an empty object', () => {
        const bare: Graph = { start: '', nodes: [{ id: 'x', type: 't' } as never], edges: [] }

        expect(toCanvas(bare).nodes[0]?.data.config).toEqual({})
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

    // Counterfactual: key edges by index alone and two edges between the same
    // pair collide, so React Flow renders one.
    it('gives two edges between the same pair distinct ids', () => {
        const twoOutputs: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 't', config: {} }, { id: 'b', type: 't', config: {} }],
            edges: [{ from: 'a', to: 'b', output: 'yes' }, { from: 'a', to: 'b', output: 'no' }],
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

Expected: every case fails on `Failed to resolve import "./toCanvas"`. If any case *passes*, the file already exists and you are not starting from red.

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
import type { CanvasEdge, CanvasNode, Graph } from './types'

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
        nodes: graph.nodes.map((node, index) => ({
            id: node.id,
            type: 'nodeflowNode' as const,
            position: node.position ?? gridPosition(index),
            data: {
                id: node.id,
                type: node.type,
                config: node.config ?? {},
                isStart: graph.start === node.id,
            },
        })),
        // The index is in the id because a node with two outputs wired to the
        // same target produces two edges that are otherwise identical, and React
        // Flow drops duplicate ids.
        edges: graph.edges.map((edge, index) => ({
            id: `nf${index}-${edge.from}-${edge.output ?? ''}-${edge.to}`,
            source: edge.from,
            sourceHandle: edge.output,
            target: edge.to,
            label: edge.output ?? undefined,
        })),
    }
}
```

- [ ] **Step 11: Run the tests and the type check**

```bash
npm test -- resources/js/graph/toCanvas.test.ts && npm run types:check
```

Expected: 7 passing, `tsc` silent.

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

const graph: Graph = {
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
        const { graph: out, unresolved } = toGraph(toCanvas(graph), graph.start, defs)

        expect(out).toEqual(graph)
        expect(unresolved).toEqual([])
    })

    // Counterfactual: emit the live canvas position unrounded and every mouse
    // move writes a graph differing in the twelfth decimal place, so autosave
    // fires forever on a canvas nobody is editing.
    it('rounds positions so a sub-pixel drag is not a change', () => {
        const canvas = toCanvas(graph)
        canvas.nodes[0]!.position = { x: 40.4, y: 80.6 }

        expect(toGraph(canvas, 'n1', defs).graph.nodes[0]?.position).toEqual({ x: 40, y: 81 })
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

        expect(out.edges[0]?.output).toBeNull()
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

        expect(out.edges[0]?.output).toBe('default')
        expect(unresolved).toEqual([])
    })

    // A draft may reference a type the host has not registered — that is legal,
    // and publish is where it is caught. Counterfactual: index defs without a
    // fallback and this throws instead of producing a savable draft.
    it('leaves an edge unresolved when the source node type is not in the palette', () => {
        const unknown: Graph = {
            start: 'a',
            nodes: [{ id: 'a', type: 'not.registered', config: {}, position: { x: 0, y: 0 } },
                    { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } }],
            edges: [{ from: 'a', to: 'b', output: null }],
        }

        const { graph: out, unresolved } = toGraph(toCanvas(unknown), 'a', defs)

        expect(out.edges[0]?.output).toBeNull()
        expect(unresolved).toHaveLength(1)
    })
})

describe('resolveOutput', () => {
    // Counterfactual: treat '' as a handle name and the edge publishes with an
    // empty output, which GraphValidator reports as an unknown output ''.
    it('treats an empty handle as no handle', () => {
        expect(resolveOutput('', def('t', ['a', 'b']))).toBeNull()
    })

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

Expected: failure on `Failed to resolve import "./toGraph"`.

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
export function resolveOutput(sourceHandle: string | null, def: NodeTypePayload | undefined): string | null {
    if (sourceHandle !== null && sourceHandle !== '') {
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
                // Rounded, because React Flow reports fractional coordinates
                // while dragging and autosave compares serialised graphs: an
                // unrounded position turns every pixel of mouse movement into a
                // new draft revision.
                position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
            })),
            edges,
        },
        unresolved,
    }
}
```

- [ ] **Step 16: Run the whole JS suite and the type check**

```bash
npm test && npm run types:check
```

Expected: 15 passing across two files, `tsc` silent.

- [ ] **Step 17: Close the prototype bug by experiment**

Change `resolveOutput`'s last line to `return outputs.length === 1 ? outputs[0]! : 'default'`, run `npm test`, and confirm **`never invents an output for a handle it cannot resolve` fails**. Restore the line and confirm green. Record both results in the task report — the discipline is that a finding proven by experiment is closed by experiment.

- [ ] **Step 18: Commit**

```bash
git add resources/js/graph
git commit -m "feat: convert the canvas back to a graph without inventing edge outputs"
```

---

## Task 2: The server hands the client its endpoint URLs

**Files:**
- Modify: `src/Http/Controllers/FlowEditorController.php` (`edit()`, plus three private helpers)
- Modify: `tests/Feature/EditorRoutesTest.php`
- Modify: `docs/02-integration.md` ("The edit page" props block)

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: an `urls` prop on `GET flows/{flow}/edit` — `{draft: string, publish: string, options: string}` — where `options` is a URL template containing the literal substrings `__NODEFLOW_TYPE__` and `__NODEFLOW_FIELD__`. Task 4's `useFieldOptions` and Task 6's `useAutosave` consume it. The two sentinel constants are also referenced by `docs/08-editor-client.md` in Task 8.

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
    expect($response->json('props.urls.options'))
        ->toContain('__NODEFLOW_TYPE__')
        ->toContain('__NODEFLOW_FIELD__')
        ->toContain("/nodeflow/flows/{$this->flow->id}/nodes/");
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
        ->assertJsonPath('props.urls.publish', "http://localhost/admin/flows/{$this->flow->id}/publish");
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
grep -c "assertJsonPath('props.flow.draft_updated_at', null)" tests/Feature/EditorRoutesTest.php
```

Expected: `1`. Any other number means stop and re-read the file.

- [ ] **Step 2: Run the tests and confirm they fail**

```bash
vendor/bin/pest --filter='urls'
```

Expected: three failures, each reporting a missing `props.urls` path — not an error about a missing route or a missing method.

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

Expected: the three filtered tests pass, and the full suite is 322 passing (319 + the two new tests + no losses). If any pre-existing test now fails, stop: `edit()`'s signature changed, and something else calls it.

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
grep -n '"triggers": \[' docs/02-integration.md
```

Expected: exactly one hit, inside the edit-page props block.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/FlowEditorController.php tests/Feature/EditorRoutesTest.php docs/02-integration.md
git commit -m "feat: hand the editor client its endpoint urls, prefix-aware"
```

---
## Task 3: The six field controls, the unregistered-type error, and the merge

**Files:**
- Create: `resources/js/controls/types.ts`, `resources/js/controls/Field.tsx`, `resources/js/controls/index.ts`
- Create: `resources/js/controls/Text.tsx`, `Number.tsx`, `Boolean.tsx`, `Select.tsx`, `Multiselect.tsx`, `Duration.tsx`, `Unregistered.tsx`
- Create: `resources/js/test-setup.ts`
- Modify: `vitest.config.ts` (add `setupFiles`)
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
  - `DURATION_UNITS`, `formatDuration(amount, unit)`, `parseDuration(value)` from `Duration.tsx`.
  - `FieldShell` and `inputClass` from `Field.tsx`.
- Later tasks rely on: `ConfigPanel.tsx` (Task 7) calls `controlFor`; `index.ts` (Task 7) re-exports `defaultControls` and `Unregistered`.

**The two hard rules, both from 10's error table:**

1. An unmatched field type renders a **named error and no input of any kind**. `Field::custom('destination', 'town')` sets the PHP enum to `Text` internally while sending `type: 'town'` on the wire, so a text fallback would look right and be wrong: a town picker degraded to free text passes `string` validation and reaches a node as garbage.
2. A control never emits `''` where the server's rules will read it as a value. `Field::rules()` adds `in:<keys>` to a select with static options, and `nullable` does not exempt `''` from `in:` - so an empty select must emit `null`. The same reasoning makes `number` emit `null` rather than `''` for an empty box.

**The duration unit list is verified, not assumed.** `ValidDuration::seconds()` was probed directly for every candidate unit at amounts 1, 2 and 90: `seconds`, `minutes`, `hours`, `days` and `weeks` all resolve to a positive number of seconds. `months` also parses - but `CarbonInterval::fromString('1 months')` resolves to **28 days**, which would silently mislead an author writing a monthly follow-up, so `months` is excluded. `'0 days'` resolves to 0, which `ValidDuration` rejects, so the control must never emit an amount below 1.

- [ ] **Step 1: Add the DOM matchers and the setup file**

```bash
npm install --save-dev --save-exact @testing-library/jest-dom
```

Create `resources/js/test-setup.ts`:

```ts
import '@testing-library/jest-dom/vitest'
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
import { DURATION_UNITS, formatDuration, parseDuration } from './Duration'
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

    it('formats an amount and a unit into the string the engine parses', () => {
        expect(formatDuration(5, 'minutes')).toBe('5 minutes')
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

    it('emits a duration string when both parts are present', async () => {
        const onChange = renderControl(field({ key: 'duration', type: 'duration' }), '5 minutes')

        await userEvent.selectOptions(screen.getByRole('combobox'), 'days')

        expect(onChange).toHaveBeenLastCalledWith('5 days')
    })

    // Counterfactual: drop min={1} and the spinner's own down-arrow reaches 0.
    it('refuses an amount below one', () => {
        renderControl(field({ key: 'duration', type: 'duration' }), '5 minutes')

        expect(screen.getByRole('spinbutton')).toHaveAttribute('min', '1')
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

export type DurationUnit = (typeof DURATION_UNITS)[number]

const DEFAULT_UNIT: DurationUnit = 'minutes'

export function formatDuration(amount: number, unit: DurationUnit): string {
    return `${amount} ${unit}`
}

/** Strict on purpose: anything this does not recognise becomes an empty amount, so the author retypes it rather than publishing it. */
export function parseDuration(value: unknown): { amount: number | null; unit: DurationUnit } {
    const match = typeof value === 'string' ? /^(\d+)\s+(\w+)$/.exec(value.trim()) : null
    const unit = match?.[2] as DurationUnit | undefined

    if (!match || !unit || !(DURATION_UNITS as readonly string[]).includes(unit)) {
        return { amount: null, unit: DEFAULT_UNIT }
    }

    return { amount: Number(match[1]), unit }
}

export function Duration({ field, value, onChange, errors }: FieldControlProps) {
    const { amount, unit } = parseDuration(value)

    // Null, not '0 minutes' and not '': ValidDuration rejects anything resolving
    // to zero or fewer seconds, and '0 days' resolves to 0. Emitting null lets
    // required() produce "this field is required" and lets nullable() pass,
    // which are both the message the author needs.
    const emit = (nextAmount: number | null, nextUnit: DurationUnit) =>
        onChange(nextAmount === null || nextAmount < 1 ? null : formatDuration(nextAmount, nextUnit))

    return (
        <FieldShell field={field} errors={errors}>
            <div className="flex gap-1">
                <input
                    id={`nf-${field.key}`}
                    type="number"
                    min="1"
                    step="1"
                    className={inputClass}
                    value={amount === null ? '' : String(amount)}
                    onChange={(event) => emit(event.target.value === '' ? null : Number(event.target.value), unit)}
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

Expected: all control cases pass alongside Task 1's, `tsc` silent.

- [ ] **Step 15: Write the PHP test that pins the unit list across the language boundary**

Create `tests/Unit/DurationControlUnitsTest.php`:

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

it('finds the unit list the duration control actually offers', function () {
    // Counterfactual: rename DURATION_UNITS in Duration.tsx and this fails
    // rather than the next test passing on an empty list.
    expect(durationUnitsFromControl())->toHaveCount(5);
});

it('offers only units the engine resolves to a positive number of seconds', function () {
    // ValidDuration rejects <= 0, and Carbon resolves both '' and 'banana' to
    // zero without complaint - so a unit the control offers and Carbon does not
    // understand would publish a zero-second wait.
    // Counterfactual: add 'fortnights' to DURATION_UNITS and this fails.
    foreach (durationUnitsFromControl() as $unit) {
        foreach ([1, 2, 90] as $amount) {
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

Expected: three new tests pass; the full suite is 325 passing.

- [ ] **Step 17: Close the cross-language pin by experiment**

Add `'fortnights'` to `DURATION_UNITS` in `Duration.tsx`, run `vendor/bin/pest --filter='positive number of seconds'`, and confirm it fails naming `1 fortnights`. Remove it and confirm green. Report both results.

- [ ] **Step 18: Commit**

```bash
git add resources/js/controls resources/js/test-setup.ts vitest.config.ts package.json package-lock.json tests/Unit/DurationControlUnitsTest.php
git commit -m "feat: add the six field controls and a loud error for an unregistered type"
```

---

## Task 4: The fetch helper and lazy per-field option loading

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
  - `useFieldOptions(nodeType: string, field: FieldPayload): {options: Record<string, string>; loading: boolean; error: string | null}`.
- Later tasks rely on: `send` (Tasks 6, 7), `useFieldOptions` and `FieldOptionsContext` (Task 7).

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

        await send('PUT', '/draft', { graph: { start: '' } })

        const [url, init] = fetchMock.mock.calls[0]!

        expect(url).toBe('/draft')
        expect(init.method).toBe('PUT')
        expect(init.credentials).toBe('same-origin')
        expect(init.headers.Accept).toBe('application/json')
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest')
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

- [ ] **Step 5: Write the failing tests for `useFieldOptions`**

Create `resources/js/controls/useFieldOptions.test.tsx`:

```tsx
import { renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { FieldOptionsContext, useFieldOptions } from './useFieldOptions'

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

    // Counterfactual: dereference the context without a null check and a host
    // rendering a control outside the editor crashes.
    it('is inert with no provider', () => {
        const { result } = renderHook(() => useFieldOptions('app.send', field({ dynamic_options: true })))

        expect(result.current.loading).toBe(false)
        expect(result.current.options).toEqual({})
    })
})
```

- [ ] **Step 6: Run and confirm failure**

```bash
npm test -- resources/js/controls/useFieldOptions.test.tsx
```

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

type State = { options: Record<string, string> | null; loading: boolean; error: string | null }

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
    const key = `${nodeType} ${field.key}`
    const cached = source?.cache.get(key)
    const dynamic = field.dynamic_options && source !== null

    const [state, setState] = useState<State>(() => ({
        options: cached ?? null,
        loading: dynamic && cached === undefined,
        error: null,
    }))

    useEffect(() => {
        if (!dynamic || !source || source.cache.has(key)) {
            return
        }

        let live = true

        setState({ options: null, loading: true, error: null })

        send('GET', optionsUrl(source.template, nodeType, field.key))
            .then((result) => {
                if (!live) {
                    return
                }

                if (!result.ok) {
                    setState({
                        options: null,
                        loading: false,
                        error: `Could not load the choices for this field (HTTP ${result.status}). The node type or field key may not be registered, or its option source may not implement Nodeflow\\Schema\\OptionSource.`,
                    })

                    return
                }

                const options = (result.data?.options ?? {}) as Record<string, string>

                source.cache.set(key, options)
                setState({ options, loading: false, error: null })
            })
            .catch((reason: unknown) => {
                if (live) {
                    setState({ options: null, loading: false, error: `Could not load the choices for this field: ${String(reason)}` })
                }
            })

        return () => {
            live = false
        }
    }, [dynamic, source, key, nodeType, field.key])

    if (!field.dynamic_options) {
        return { options: field.options, loading: false, error: null }
    }

    return { options: state.options ?? cached ?? EMPTY, loading: state.loading, error: state.error }
}
```

- [ ] **Step 8: Run and confirm green**

```bash
npm test && npm run types:check
```

- [ ] **Step 9: Commit**

```bash
git add resources/js/http.ts resources/js/http.test.ts resources/js/controls/useFieldOptions.ts resources/js/controls/useFieldOptions.test.tsx
git commit -m "feat: fetch a field's options lazily, and name the failure when it fails"
```

---
## Task 5: The canvas primitives, shared with Plan 4's run view

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
class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}

globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver

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
import { ReactFlowProvider } from '@xyflow/react'
import { render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import type { NodeCardData, NodeTypePayload } from '../graph/types'
import { Canvas } from './Canvas'
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
    // Counterfactual: read the label from data.type instead of the definition and
    // every card shows a machine type string to a non-technical author.
    it('reads label and description from the definition', () => {
        render(defaultNodeRenderer({ data, def: def(), selected: false, errors: [] })!)

        expect(screen.getByText('Send message')).toBeInTheDocument()
        expect(screen.getByText('Sends one message')).toBeInTheDocument()
    })

    // A draft may reference a type the host has not registered - that is legal,
    // and publish is where it is caught. Counterfactual: render nothing when def
    // is undefined and the author sees an empty box they cannot diagnose or
    // delete on purpose.
    it('names an unregistered node type instead of rendering an empty card', () => {
        render(defaultNodeRenderer({ data: { ...data, type: 'not.registered' }, def: undefined, selected: false, errors: [] })!)

        expect(screen.getByRole('alert').textContent).toContain('not.registered')
    })

    // Counterfactual: drop the isStart branch and the author cannot tell which
    // node a run begins at, which is the single most consequential property of
    // the graph.
    it('marks the start node', () => {
        render(defaultNodeRenderer({ data, def: def(), selected: false, errors: [] })!)

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
                    <NodeCard id="n1" data={data} selected={false} type="nodeflowNode" dragging={false} zIndex={0} isConnectable positionAbsoluteX={0} positionAbsoluteY={0} />
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
        render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{ defs: { 'app.send': def() }, renderers: {}, nodeErrors: { n1: ['field [template]: required'], n2: ['not mine'] } }}
                >
                    <NodeCard id="n1" data={data} selected={false} type="nodeflowNode" dragging={false} zIndex={0} isConnectable positionAbsoluteX={0} positionAbsoluteY={0} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByRole('alert').textContent).toContain('field [template]: required')
        expect(screen.queryByText('not mine')).toBeNull()
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
                nodes={[{ id: 'n1', type: 'nodeflowNode', position: { x: 0, y: 0 }, data }]}
                edges={[]}
                defs={{ 'app.send': def() }}
            />,
        )

        expect(screen.getByText('Send message')).toBeInTheDocument()
    })
})
```

If the `Canvas` mount test cannot be made to pass after the shims - React Flow's jsdom support is version-dependent and this is the one assertion in the plan that depends on it - **delete that single `describe('Canvas')` block, and say so in the task report.** Do not weaken any other test to compensate, and do not skip it silently: the `rendererFor`, `defaultNodeRenderer` and `NodeCard` cases carry the behaviour, and Task 10's browser click-through is the real acceptance check (11 puts browser E2E out of scope precisely because a real-app check is the v1 criterion).

- [ ] **Step 3: Run and confirm failure**

```bash
npm test -- resources/js/canvas
```

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
import type { NodeCardData } from '../graph/types'
import { CanvasContext, type NodeRenderer, type NodeRendererMap } from './context'
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
export const defaultNodeRenderer: NodeRenderer = ({ data, def, errors }) => (
    <div className="space-y-1 px-3 py-2">
        <div className="flex items-center gap-1.5">
            {data.isStart && (
                <span className="rounded bg-primary px-1 text-[10px] font-semibold uppercase text-primary-foreground">START</span>
            )}
            {def?.icon && <span aria-hidden="true">{def.icon}</span>}
            <span className="text-xs font-semibold text-foreground">{def?.label ?? data.type}</span>
        </div>

        <p className="font-mono text-[10px] text-muted-foreground">{data.id}</p>

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

        {errors.length > 0 && (
            <ul role="alert" className="space-y-0.5 text-[10px] text-destructive">
                {errors.map((error) => (
                    <li key={error}>{error}</li>
                ))}
            </ul>
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
export function NodeCard({ id, data, selected }: NodeProps & { data: NodeCardData }) {
    const { defs, renderers, nodeErrors } = useContext(CanvasContext)
    const def = defs[data.type]
    const outputs = def?.outputs ?? []
    const Body = rendererFor(data.type, renderers)

    return (
        <div
            style={{ width: NODE_WIDTH }}
            className={`rounded-md border bg-card shadow-sm ${selected ? 'border-primary ring-1 ring-primary' : 'border-border'}`}
        >
            <Handle type="target" position={Position.Left} className="!size-2 !bg-muted-foreground" />

            <Body data={data} def={def} selected={selected} errors={nodeErrors[id] ?? []} />

            {outputs.map((output, index) => (
                <Handle
                    key={output}
                    id={output}
                    type="source"
                    position={Position.Right}
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
    type OnEdgesChange,
    type OnNodesChange,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useMemo } from 'react'
import type { CanvasEdge, CanvasNode, NodeTypePayload } from '../graph/types'
import { CanvasContext, type NodeRendererMap } from './context'
import { NodeCard } from './NodeCard'

export type CanvasProps = {
    nodes: CanvasNode[]
    edges: CanvasEdge[]
    defs: Record<string, NodeTypePayload>
    renderers?: NodeRendererMap
    nodeErrors?: Record<string, string[]>
    onNodesChange?: OnNodesChange
    onEdgesChange?: OnEdgesChange
    onConnect?: (connection: Connection) => void
    onNodeClick?: (id: string) => void
    /** False for Plan 4's run view: a run's graph is frozen and must not look editable. */
    interactive?: boolean
    className?: string
}

// Declared once, at module scope: React Flow warns and remounts every node when
// this object's identity changes between renders.
const nodeTypes = { nodeflowNode: NodeCard }

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

    return (
        <CanvasContext.Provider value={context}>
            <div className={className}>
                <ReactFlow
                    nodes={nodes as unknown as Node[]}
                    edges={edges as unknown as Edge[]}
                    nodeTypes={nodeTypes}
                    onNodesChange={onNodesChange}
                    onEdgesChange={onEdgesChange}
                    onConnect={onConnect}
                    onNodeClick={(_, node) => onNodeClick?.(node.id)}
                    nodesDraggable={interactive}
                    nodesConnectable={interactive}
                    elementsSelectable
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

The two `as unknown as` casts are the single hand-off point between our own canvas types and React Flow's. `graph/` stays free of `@xyflow/react` so the round-trip transforms remain pure (Task 1), and the shapes are structurally compatible by construction; this is the one place that is asserted by hand rather than by the compiler. Write a comment saying so at the cast.

- [ ] **Step 7: Run and confirm green**

```bash
npm test && npm run types:check
```

- [ ] **Step 8: Commit**

```bash
git add resources/js/canvas resources/js/test-setup.ts
git commit -m "feat: add the shared canvas, with handles the host cannot accidentally remove"
```

---

## Task 6: Debounced autosave, with the 409 conflict as a first-class state

**Files:**
- Create: `resources/js/editor/useAutosave.ts`
- Test: `resources/js/editor/useAutosave.test.tsx`

**Interfaces:**
- Consumes: `Graph` (Task 1), `send` (Task 4).
- Produces:
  - `type AutosaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'`.
  - `type DraftConflict = {graph: Graph; revision: number}`.
  - `type Autosave = {status: AutosaveStatus; revision: number; message: string | null; conflict: DraftConflict | null; lastSavedAt: number | null; resolveConflict(choice: 'mine' | 'theirs'): void; adoptRevision(revision: number): void}`.
  - `useAutosave(options: {url: string; initialRevision: number; graph: Graph; debounceMs?: number}): Autosave`.
- Later tasks rely on: all of it (Task 8).

**The contract this is written against, verified against the shipped server:** `PUT .../draft` takes `{graph, draft_revision}` and returns `{draft_revision}`. `draft_revision` is an **integer**, `0` for a flow that has never had a draft saved, and nullable on the wire. A mismatch is **409** with `{message, graph, draft_revision}` carrying the **newer** graph. `draft_updated_at` never appears in this endpoint's response and is never the token. Publishing does **not** reset `draft_revision`, which is why publish's response carries it and why `adoptRevision` exists.

**Why the change detector is `JSON.stringify`:** the editor recomputes its graph on every render, so object identity cannot tell an edit from a re-render, and a hook that saved on identity would autosave forever on an untouched canvas. Serialising is O(graph) on each render of a structure with tens of nodes, which is cheap, and it is exact. It is also why `toGraph` rounds positions (Task 1): an unrounded fractional coordinate makes every pixel of mouse movement a new revision.

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
    it('resolving with "theirs" saves nothing until the next real edit', async () => {
        const theirs = graph('theirs')
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

        // The caller replaces the canvas with theirs, then tells the hook.
        act(() => {
            result.current.resolveConflict('theirs')
        })
        rerender({ graph: theirs })

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
    // Counterfactual: drop adoptRevision and the first autosave after a publish
    // 409s with an empty graph.
    it('adopts the revision publish hands back', async () => {
        const fetchMock = okOnce(6)
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )

        act(() => {
            result.current.adoptRevision(5)
        })

        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })

        await waitFor(() => expect(fetchMock).toHaveBeenCalled())
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).draft_revision).toBe(5)
    })
})
```

- [ ] **Step 2: Run and confirm failure**

```bash
npm test -- resources/js/editor/useAutosave.test.tsx
```

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
    /**
     * 'mine' adopts the server's revision and immediately saves the author's own
     * graph over theirs. 'theirs' adopts the revision and saves nothing - the
     * caller is expected to have replaced its canvas with conflict.graph.
     */
    resolveConflict(choice: 'mine' | 'theirs'): void
    /** After a publish, whose response carries the current token (publish does not reset it). */
    adoptRevision(revision: number): void
}

const EMPTY_GRAPH: Graph = { start: '', nodes: [], edges: [] }

/**
 * Debounced draft autosave (5.9), with the 409 as a state rather than an error.
 *
 * Change detection is by serialised comparison, not object identity: the editor
 * rebuilds its graph on every render, so identity cannot tell an edit from a
 * re-render and a hook keyed on it would autosave forever on an untouched
 * canvas. This is also why toGraph() rounds positions - an unrounded fractional
 * coordinate would make every pixel of mouse movement a new revision.
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
    const inFlight = useRef(false)
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

    const run = useCallback(async () => {
        if (inFlight.current || halted.current || pending.current === null) {
            return
        }

        const body = pending.current
        pending.current = null
        inFlight.current = true
        setState((current) => ({ ...current, status: 'saving', message: null }))

        let result
        try {
            result = await send('PUT', url, { graph: JSON.parse(body) as Graph, draft_revision: revision.current })
        } catch (reason: unknown) {
            inFlight.current = false
            halted.current = true
            setState((current) => ({ ...current, status: 'error', message: `Could not reach the server to save this draft: ${String(reason)}` }))

            return
        }

        inFlight.current = false

        if (result.status === 409) {
            halted.current = true
            conflict.current = {
                // The endpoint always answers with a graph-shaped body, but keep
                // the fallback: a client typed from the docs should not crash if
                // it ever meets an older server.
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

        // A change made while that request was in flight.
        if (pending.current !== null) {
            void run()
        }
    }, [url])

    useEffect(() => {
        if (halted.current || serialised === baseline.current) {
            return
        }

        pending.current = serialised

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
            if (document.visibilityState === 'hidden' && pending.current !== null && !halted.current) {
                if (timer.current !== null) {
                    clearTimeout(timer.current)
                }

                void run()
            }
        }

        document.addEventListener('visibilitychange', flush)

        return () => document.removeEventListener('visibilitychange', flush)
    }, [run])

    const resolveConflict = useCallback((choice: 'mine' | 'theirs') => {
        const theirs = conflict.current

        if (theirs === null) {
            return
        }

        revision.current = theirs.revision
        conflict.current = null
        halted.current = false
        pending.current = null

        // 'theirs': the caller has replaced its canvas with their graph, so that
        // is now what the server holds and nothing needs sending.
        // 'mine': blank the baseline so the watcher sees the author's graph as a
        // change and saves it over theirs, with their revision as the token.
        baseline.current = choice === 'theirs' ? JSON.stringify(theirs.graph) : ''

        setState((current) => ({ ...current, status: 'idle', conflict: null, message: null, revision: theirs.revision }))
        setNudge((count) => count + 1)
    }, [])

    const adoptRevision = useCallback((next: number) => {
        revision.current = next
        setState((current) => ({ ...current, revision: next }))
    }, [])

    return { ...state, resolveConflict, adoptRevision }
}
```

- [ ] **Step 4: Run and confirm green**

```bash
npm test && npm run types:check
```

- [ ] **Step 5: Close the timestamp-token finding by experiment**

Change the request body to `{graph, draft_updated_at: null}` and drop `draft_revision`, run `npm test -- resources/js/editor/useAutosave.test.tsx`, and confirm `sends the revision it holds and adopts the one it is given` fails. Restore and confirm green. Report both. This is the mechanism 3a spent three rounds getting right; the client half must be pinned too.

- [ ] **Step 6: Commit**

```bash
git add resources/js/editor
git commit -m "feat: autosave the draft on a debounce and treat a 409 as a decision, not a failure"
```

---

## Task 7: Interpreting publish's two 422s, and minting node ids

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
                errors: ['Node [w1] field [duration]: not a duration'],
                node_errors: [{ node: 'w1', field: 'duration', message: 'not a duration' }],
            }),
            known,
        )

        expect(outcome).toEqual({
            kind: 'semantic',
            banner: ['Node [w1] field [duration]: not a duration'],
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

    const nodeErrors = result.data?.node_errors

    if (!Array.isArray(nodeErrors)) {
        // Laravel's own validation body. `errors` is field-keyed here.
        const errors = (result.data?.errors ?? {}) as Record<string, string[]>

        return {
            kind: 'structural',
            developer: Object.entries(errors).flatMap(([field, messages]) => messages.map((message) => `${field}: ${message}`)),
        }
    }

    const byNode: Record<string, NodeErrorEntry[]> = {}
    const unplaceable: string[] = []

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

- [ ] **Step 4: Run and confirm green, then write the failing tests for `ids.ts`**

```bash
npm test -- resources/js/editor/publish.test.ts
```

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
        expect(nextNodeId('yaya.send_message', new Set())).toBe('send_message1')
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

    it('allows a handle-less connection from a node with exactly one output', () => {
        expect(canConnect('one.out', null, defs)).toBe(true)
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

- [ ] **Step 5: Run and confirm failure, then create `resources/js/editor/ids.ts`**

```bash
npm test -- resources/js/editor/ids.test.ts
```

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

- [ ] **Step 6: Run the whole JS suite and the type check**

```bash
npm test && npm run types:check
```

- [ ] **Step 7: Commit**

```bash
git add resources/js/editor/publish.ts resources/js/editor/publish.test.ts resources/js/editor/ids.ts resources/js/editor/ids.test.ts
git commit -m "feat: tell publish's two 422 shapes apart and refuse unattributable connections"
```

---
## Task 8: The editor itself, and the package's public surface

**Files:**
- Create: `resources/js/editor/Palette.tsx`
- Create: `resources/js/editor/ConfigPanel.tsx`
- Create: `resources/js/editor/FlowEditor.tsx`
- Create: `resources/js/index.ts`
- Test: `resources/js/editor/ConfigPanel.test.tsx`
- Test: `resources/js/editor/FlowEditor.test.tsx`

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
                node={data}
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

    it('reports an edit as a config change on that key', async () => {
        const onConfigChange = renderPanel(def([field()]))

        await userEvent.clear(screen.getByLabelText('Template'))
        await userEvent.type(screen.getByLabelText('Template'), 'x')

        expect(onConfigChange).toHaveBeenLastCalledWith('template', 'x')
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
                    value={node.config[field.key] ?? field.default}
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

- [ ] **Step 5: Create `resources/js/editor/Palette.tsx`**

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

- [ ] **Step 6: Write the failing tests for `FlowEditor`**

Create `resources/js/editor/FlowEditor.test.tsx`:

```tsx
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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

const graph: Graph = {
    start: 'send1',
    nodes: [
        { id: 'send1', type: 'app.send', config: { template: 'welcome' }, position: { x: 0, y: 0 } },
        { id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } },
    ],
    edges: [{ from: 'send1', to: 'exit1', output: 'sent' }],
}

function renderEditor(overrides: Partial<Parameters<typeof FlowEditor>[0]> = {}) {
    return render(<FlowEditor flow={flow} graph={graph} palette={palette} triggers={triggers} urls={urls} autosaveDebounceMs={5} {...overrides} />)
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 8 })))
})

describe('FlowEditor', () => {
    // Counterfactual: render flow.trigger_type raw and a non-technical author is
    // shown 'app.order_placed' where the trigger palette has a real label.
    it('names the flow and its trigger in words, from the triggers prop', () => {
        renderEditor()

        expect(screen.getByText('Welcome journey')).toBeInTheDocument()
        expect(screen.getByText(/Order placed/)).toBeInTheDocument()
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
    })

    // The documented wrinkle: an entry naming a node with no card.
    // Counterfactual: assume every entry maps to a card and this message renders
    // nowhere, so the author is told the publish failed and not why.
    it('shows an error naming an absent node in the banner rather than dropping it', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                Response.json(
                    { errors: ['The start node [ghost] is not in the graph.'], node_errors: [{ node: 'ghost', field: null, message: 'The start node [ghost] is not in the graph.' }] },
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

    // Counterfactual: keep autosaving after a 409 and the loser's graph either
    // keeps failing or, once the revision is adopted silently, overwrites the
    // colleague's work.
    it('offers a choice on a draft conflict instead of picking a winner', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(
                Response.json({ message: 'Someone else edited this flow.', graph: { start: 'exit1', nodes: [], edges: [] }, draft_revision: 20 }, { status: 409 }),
            ),
        )

        renderEditor()

        // Any edit is enough to start the autosave clock.
        await userEvent.click(screen.getByRole('button', { name: /Exit/ }))

        await waitFor(() => expect(screen.getByText(/Someone else edited this flow/)).toBeInTheDocument())
        expect(screen.getByRole('button', { name: /keep mine/i })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: /use theirs/i })).toBeInTheDocument()
    })

    // Counterfactual: mint ids from a bare counter and adding a node after a
    // reload reuses an id already in the graph, which Graph::fromArray()
    // collapses to last-one-wins - a node silently lost on publish.
    it('adds a node from the palette with an id that is not already taken', async () => {
        renderEditor()

        await userEvent.click(screen.getByRole('button', { name: /Send message/ }))

        await waitFor(() => expect(screen.getByText('send2')).toBeInTheDocument())
    })
})
```

- [ ] **Step 7: Run and confirm failure**

```bash
npm test -- resources/js/editor/FlowEditor.test.tsx
```

If a case fails because React Flow will not mount in jsdom rather than because `FlowEditor` does not exist yet, apply the same rule as Task 5: keep the cases that do not need the canvas, delete the ones that do, and **say exactly which in the task report.** The canvas-independent cases here are the trigger label, the version, all three publish-outcome cases, the unresolved-edge refusal and the conflict prompt - eight of nine. Do not weaken an assertion to make a case pass.

- [ ] **Step 8: Create `resources/js/editor/FlowEditor.tsx`**

```tsx
import { addEdge, applyEdgeChanges, applyNodeChanges, type Connection, type Edge, type Node, type OnEdgesChange, type OnNodesChange } from '@xyflow/react'
import { useCallback, useMemo, useRef, useState } from 'react'
import { Canvas } from '../canvas/Canvas'
import type { NodeRendererMap } from '../canvas/context'
import { mergeControls } from '../controls'
import type { ControlMap } from '../controls/types'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import { toCanvas } from '../graph/toCanvas'
import { defsByType, toGraph } from '../graph/toGraph'
import type { CanvasEdge, CanvasNode, EditorUrls, FlowSummary, Graph, NodeErrorEntry, NodeTypePayload, TriggerPayload } from '../graph/types'
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
    const optionsSource = useRef({ template: urls.options, cache: new Map<string, Record<string, string>>() })

    const initial = useMemo(() => toCanvas(graph), [graph])
    const [nodes, setNodes] = useState<CanvasNode[]>(initial.nodes)
    const [edges, setEdges] = useState<CanvasEdge[]>(initial.edges)
    const [startId, setStartId] = useState(graph.start)
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

    const onNodesChange: OnNodesChange = useCallback(
        (changes) => setNodes((current) => applyNodeChanges(changes, current as unknown as Node[]) as unknown as CanvasNode[]),
        [],
    )

    const onEdgesChange: OnEdgesChange = useCallback(
        (changes) => setEdges((current) => applyEdgeChanges(changes, current as unknown as Edge[]) as unknown as CanvasEdge[]),
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

            setEdges(
                (current) =>
                    addEdge({ ...connection, label: connection.sourceHandle ?? undefined }, current as unknown as Edge[]) as unknown as CanvasEdge[],
            )
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
        const result = await send('POST', urls.publish, { graph: built.graph })
        setPublishing(false)

        const next = interpretPublish(result, new Set(canvasNodes.map((node) => node.id)))
        setOutcome(next)

        if (next.kind === 'published') {
            // Publishing does not reset draft_revision, so the token travels back
            // in the response and the still-open editor has to adopt it.
            autosave.adoptRevision(next.revision)
        }
    }, [built, urls.publish, canvasNodes, autosave])

    const trigger = triggers.find((candidate) => candidate.type === flow.trigger_type)

    return (
        <FieldOptionsContext.Provider value={optionsSource.current}>
            <div className={className ?? 'flex h-[calc(100vh-6rem)] min-h-[36rem] flex-col'}>
                <header className="flex flex-wrap items-center gap-3 border-b border-border px-4 py-2">
                    <div>
                        <h1 className="text-sm font-semibold text-foreground">{flow.name}</h1>
                        <p className="text-xs text-muted-foreground">
                            {trigger?.label ?? flow.trigger_type} - published v{flow.version ?? '-'} - start:{' '}
                            <span className="font-mono">{startId || 'none'}</span>
                        </p>
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
                                setNodes(replacement.nodes)
                                setEdges(replacement.edges)
                                setStartId(theirs.graph.start)
                                setSelectedId(null)
                                autosave.resolveConflict('theirs')
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
                                    {(outcome.banner.length > 0 ? outcome.banner : outcome.unplaceable).map((message) => (
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
                                onDelete={() => {
                                    setNodes((current) => current.filter((node) => node.id !== selected.id))
                                    setEdges((current) => current.filter((edge) => edge.source !== selected.id && edge.target !== selected.id))
                                    setSelectedId(null)
                                }}
                            />
                        )}
                    </aside>
                </div>
            </div>
        </FieldOptionsContext.Provider>
    )
}
```

- [ ] **Step 9: Create `resources/js/index.ts`**

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

export type { CanvasProps } from './canvas/Canvas'
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

- [ ] **Step 10: Run everything**

```bash
npm test && npm run types:check && vendor/bin/pest
```

Expected: the full JS suite green, `tsc` silent, and the PHP suite still at 325.

- [ ] **Step 11: Commit**

```bash
git add resources/js
git commit -m "feat: assemble the flow editor and export the package's public surface"
```

---

## Task 9: Documentation, and deleting the lines that deny what now exists

**Files:**
- Create: `docs/08-editor-client.md`
- Modify: `docs/02-integration.md` (the "What you have not wired yet" section)
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` (an "as built" block on 5; 3's table)
- Modify: `docs/superpowers/open-issues.md`

**Interfaces:** none. This task ships no code.

**This task exists because of a recorded, three-time failure.** Plan 1 shipped a line denying the scaffolding generator it added. Plan 2 shipped one claiming a cross-tenant id is a 404 when the default made it a 200. Plan 3a shipped a "There is no UI" section two sections below the routes that render one. Step 1 is a grep, and it is not optional.

- [ ] **Step 1: Find every line that denies what this branch built**

```bash
grep -rniE 'no bundled front|no front end|there is no ui|not built yet|no canvas|missing is the react|no field controls|no palette' README.md docs/ --include='*.md'
```

Every hit outside `docs/superpowers/` is a line to rewrite. Record the list in the task report before changing anything, so a reviewer can check the list against the diff.

- [ ] **Step 2: Write `docs/08-editor-client.md`**

The document a client author reads. It must contain, in this order:

1. **What the package ships** - TypeScript source under `resources/js`, compiled by the host's Vite against the host's React and Tailwind tokens (D2), exporting components and not pages (E4). No bundle, no npm package, no CSS file of its own.
2. **Five wiring steps, each with the exact snippet and the exact symptom of skipping it.** Four are from 5.6; the fifth was found while planning this work:
   - **The Vite alias.** `'@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js')`. Symptom if missing: the build fails to resolve the import. **Loud.**
   - **The tsconfig path mapping.** `"@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"]` plus `"@nodeflow/editor/*"`. Symptom if missing: the build succeeds and both the host's `tsc` and their editor's IntelliSense fail on the import. **Quiet.**
   - **The Tailwind `@source` line.** `@source '../../vendor/atram/laravel-nodeflow/resources/js';` in the host's CSS entry. Symptom if missing: the build succeeds, the editor renders, and **every class is missing** - Tailwind v4's automatic source detection deliberately skips gitignored paths, and `vendor/` is gitignored. **Quiet, and the worst of the five.**
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
6. **What the editor does with the endpoints**: autosave on a debounce echoing `draft_revision`; a 409 offering "keep mine" or "use theirs" rather than picking; publish refusing to send an edge whose output it cannot resolve; the two 422 shapes and which one is shown to the author.
7. **What is not here yet**: the run view (`FlowRun`) lands in Plan 4, and `nodeflow:install` (which will verify all five wiring steps) lands in Plan 5. Until then the five steps are manual, and three of the five fail quietly.

- [ ] **Step 3: Rewrite `docs/02-integration.md`'s "What you have not wired yet"**

The section currently opens "There is **no bundled front end**" and closes by explaining how to publish flows programmatically. The programmatic path is still true and stays. The denial goes. Verify the anchor first:

```bash
grep -n 'no bundled front end' docs/02-integration.md
```

Expected: exactly one hit. Replace the opening paragraph with a pointer to `08-editor-client.md`, keep the programmatic example under a heading such as "Building flows without the editor", and keep the closing note that the JSON shape is the same one the editor's routes consume. Rename the section heading to something that is true - "Wiring the editor's front end" - and check whether anything links to the old anchor:

```bash
grep -rn 'what-you-have-not-wired-yet' README.md docs/ --include='*.md'
```

Fix every hit the grep finds.

- [ ] **Step 4: Update `README.md`**

Read it, find whatever it says about the front end or about what is not built, and correct it. Add `docs/08-editor-client.md` to the docs index if it has one.

- [ ] **Step 5: Add the "as built" block to the spec and mark the plan delivered**

In `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md`:

- 3's table: mark Plan 3 delivered with its commit range, in the same form 1 and 2 use.
- 5: add an "as built" block in the same style as 4's, immediately under the section heading, recording what actually shipped and where it differs from the prose beneath. It must state at minimum:
  - The `urls` prop, why it exists and the two sentinels.
  - Prefix-aware route-name resolution.
  - `FieldControlProps` is the six keys as specified, and an option-load failure is folded into `errors` rather than becoming a seventh key.
  - `NodeCard` owns the handles; a `nodeRenderers` override owns only the body.
  - An unresolved edge output round-trips as `null` in a draft and blocks publish client-side, rather than becoming `'default'`.
  - `gridPosition` lives in `canvas/layout.ts` and `toCanvas` imports it.
  - `index.ts` does not yet export `FlowRun`.
  - The fifth wiring requirement, `resolve.dedupe`, which 5.6 does not list and `nodeflow:install` must therefore also verify in Plan 5.
  - The autosave debounce default, and that a 409 halts the loop until the author chooses.

- [ ] **Step 6: Update `docs/superpowers/open-issues.md`**

- Change "Last updated" to reflect this plan's merge and the new test count.
- Record anything this plan found and did not fix. At minimum, add a `GAP` entry: **5.6 lists four host-wiring requirements and there are five** - `resolve.dedupe` is required for a symlinked development install and `nodeflow:install` (Plan 5) must verify it along with the other four.
- If the `Canvas`/`FlowEditor` jsdom mount tests had to be deleted (Task 5 Step 2, Task 8 Step 7), record that as a `GAP`: the canvas has no automated mount coverage and the acceptance check is Task 10's browser click-through.
- Leave **F-1**, **F-2**, **G-1**, **G-2**, **G-3**, **D-2** and the C-series open and untouched. None is in this plan's path, and closing one as a drive-by is how a plan grows a second subject.

- [ ] **Step 7: Verify the greps come back clean**

```bash
grep -rniE 'no bundled front|there is no ui|not built yet|missing is the react' README.md docs/*.md
```

Expected: no hits. Then re-run Step 1's wider grep and confirm every remaining hit is inside `docs/superpowers/` (where a historical record is correct) and is genuinely historical.

- [ ] **Step 8: Commit**

```bash
git add README.md docs
git commit -m "docs: document the editor client and delete the lines denying it exists"
```

---

## Task 10: Replace the demo app's prototype with the real editor

**Files, all in `~/Sites/test-workflow` - a separate repository and a separate commit:**
- Modify: `vite.config.ts`, `tsconfig.json`, `resources/css/app.css`, `routes/web.php`
- Modify: `app/Providers/NodeflowServiceProvider.php`
- Rewrite: `resources/js/pages/nodeflow/editor.tsx` (333 lines to about five)
- Delete: `app/Http/Controllers/NodeflowEditorController.php`

**Interfaces:** consumes the finished package. Produces nothing the package depends on.

**This is the acceptance criterion.** 11 puts Playwright and browser E2E out of scope explicitly because "the v1 acceptance criterion is already a real-app check". The demo symlinks the package at `vendor/atram/laravel-nodeflow`, so it sees package changes instantly - and it is therefore also the one place the `resolve.dedupe` requirement is real.

**Four things about this app that were verified while planning, so they are not surprises:**
1. **It defines no gates.** `grep -rn 'Gate::' app/` returns nothing, and Plan 2's policies deny by default. Without Step 5, every editor route returns 403 and the page looks broken for a reason that has nothing to do with this plan.
2. **`@xyflow/react` is already a dependency** (`^12.11.3`), so wiring requirement 4 is already satisfied. Do not add it twice.
3. **`resources/js/app.tsx` has a global `layout` resolver** whose `default` case is `AppLayout`, so the thin page needs no layout wrap of its own.
4. **`resources/js/pages/nodeflow/demo.tsx` links to `/nodeflow/flows/${f.id}/edit` as a hardcoded path, twice.** Registering `Nodeflow::routes()` under `prefix('nodeflow')` keeps both links working. Do not give that group a `->name()` prefix here: the package handles one correctly (Task 2) but the demo's own wayfinder-generated route helpers regenerate from the route list, and renaming is a second change with its own blast radius.

- [ ] **Step 1: Add the Vite alias and the dedupe**

In `~/Sites/test-workflow/vite.config.ts`, add a `resolve` block beside `plugins`:

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

- [ ] **Step 2: Add the tsconfig path mapping**

In `tsconfig.json`, extend `compilerOptions.paths`:

```json
        "paths": {
            "@/*": ["./resources/js/*"],
            "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
            "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
        },
```

- [ ] **Step 3: Add the Tailwind source line**

In `resources/css/app.css`, beside the existing `@source` lines:

```css
@source '../../vendor/atram/laravel-nodeflow/resources/js';
```

Verify the anchor is there and unique first:

```bash
grep -c "@source '../views'" resources/css/app.css
```

Expected: `1`.

- [ ] **Step 4: Switch the routes to the package's**

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

- [ ] **Step 5: Define the four gates**

Plan 2's policies deny when the host has defined no gate, so without this every route here is a 403. In `app/Providers/NodeflowServiceProvider.php`'s `boot()`:

```php
        // Plan 2's policies delegate to these and deny when they are undefined,
        // so a host that wires nothing gets a blanket 403 by design. This is a
        // demo: any authenticated user may do anything.
        foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
            Gate::define("nodeflow.{$ability}", fn ($user) => $user !== null);
        }
```

with `use Illuminate\Support\Facades\Gate;` added.

- [ ] **Step 6: Replace the prototype page**

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

- [ ] **Step 7: Build, and verify the Tailwind source line actually took**

```bash
cd ~/Sites/test-workflow && npm run types:check && npm run build
```

Both must exit 0. Then verify the class scan reached into `vendor/` - a "scripted edit that silently matched nothing" is a recorded failure mode of this project, and this one fails by producing a correct-looking build:

```bash
grep -rlo 'min-h-\[36rem\]' public/build/assets/*.css
```

Expected: at least one file. `min-h-[36rem]` appears only in `FlowEditor.tsx`'s default `className`, so finding it in the built CSS proves Tailwind scanned the package's source. If the grep finds nothing, the `@source` line did not take - fix that before going further, because the editor would render unstyled and every other check would still pass.

- [ ] **Step 8: Click through it in a browser**

Start the app and open a flow's edit page. Verify, in this order, and report what you actually saw at each step:

1. The page renders inside the app's own layout, with the app's fonts and colours - not as an unstyled block.
2. The palette lists the demo's registered nodes, grouped.
3. Adding a node from the palette puts a card on the canvas with a fresh id.
4. Selecting it shows its fields; `core.condition`'s `attribute` field loads its options over the network (the demo registers four subject attributes through `SubjectAttributeRegistry`, which is itself an `OptionSource`) - check the request in the network panel and confirm it is one request, to the `.../nodes/core.condition/fields/attribute/options` URL, and that it happens on selection rather than on page load.
5. Dragging from an output handle to another node's input creates an edge labelled with the output name.
6. After a moment, the header says the draft was saved. Confirm in the database that `nodeflow_flows.draft_revision` advanced and `draft_graph` holds the graph including each node's `position`.
7. Set a `core.wait` node's duration to 1 minute, publish, and confirm the version number advances.
8. Then break it deliberately: clear a required field and publish. The message must appear **on that node's card** and in the banner, and the flow must not have published.
9. Reload. The draft comes back, not the published version - and `flow.version` still reports the published number.

- [ ] **Step 9: Commit, in the demo repository**

```bash
cd ~/Sites/test-workflow
git add -A
git commit -m "feat: use the package's FlowEditor and delete the prototype editor"
```

- [ ] **Step 10: Verify the merged result, not just the branch**

Back in the package. This project has shipped a green branch that failed on `main` once already - 3a's suite passed in its worktree and failed after merge, because `composer.lock` merged while `vendor/` is gitignored, so main's install predated a new dev dependency. The JS equivalent is `package-lock.json` and `node_modules/`.

After merging to `main`:

```bash
cd ~/Projects/laravel-nodeflow
composer install
npm ci
vendor/bin/pest && npm test && npm run types:check
```

All three must be green on `main` itself, from a clean install, before this is called done.

---

## Self-review against the spec

Run through this before dispatching Task 1.

**Spec coverage, section by section.**

| Spec | Where in this plan |
|---|---|
| 5.5 `resources/js` layout, `index.ts` as the only public surface | Tasks 1, 3, 4, 5, 6, 7, 8. `run/` is Plan 4 and `index.ts` says so |
| 5.6 four host-wiring requirements | Task 9 Step 2, plus a fifth found while planning; verified for real in Task 10 |
| 5.6 the thin page | Task 9 Step 2, Task 10 Step 6 |
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

**Rows of 10 this plan does not own:** the cross-tenant 404, the undefined-gate 403, `resolver`-mode null and `extract-node` are server-side and already shipped or belong to Plan 6. Task 10 Step 5 exercises the 403 path by fixing it in the demo.

**Type consistency, checked across tasks.** `NodeTypePayload` (Task 1) is what `defsByType`, `rendererFor`, `canConnect`, `Palette` and `Canvas` all take - not `PaletteNode`, which is the prototype's name and appears nowhere in this plan. `interpretPublish` returns `byNode: Record<string, NodeErrorEntry[]>` (raw entries, so `ConfigPanel` can route by `field` and `NodeCard` can format), and both consumers in Task 8 use it that way. `resolveOutput` and `canConnect` are two functions, deliberately: the first decides what a stored edge means, the second decides whether a gesture is allowed, and they agree on the single-output rule. `FieldControlProps` is six keys in Task 3 and six keys everywhere it is used.

**Interfaces produced but never consumed:** `outputHandleTop` and `NODE_WIDTH` are consumed by `NodeCard`; `HANDLE_ROW_HEIGHT` is consumed by `outputHandleTop`; `formatDuration`/`parseDuration` by `Duration` and its tests; `csrfHeaders` by `send`. `PublishErrorBody` in `graph/types.ts` is exported for a host typing its own error handling and is not consumed internally - keep it, and say so in its doc comment, or delete it. Decide during Task 1 and report which.
