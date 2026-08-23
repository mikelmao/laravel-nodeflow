# Workflow Studio Editor Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Nodeflow's raw default graph editor with a polished, responsive Workflow Studio while preserving graph, autosave, publishing, tenancy, and host-extension contracts.

**Architecture:** Keep `FlowEditor` as the public session boundary and `Canvas` as the shared state-free graph primitive. Add a focused document/history controller, pure deterministic layout and presentation helpers, then compose package-owned toolbar, library, canvas, and inspector regions through an `EditorShell`. Add one authorized non-mutating validation endpoint so Validate uses the same semantic rules as Publish.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Inertia 2, React 18/19, TypeScript 7, `@xyflow/react` 12, Tailwind CSS 4 host tokens, Pest 4, Vitest 4, Testing Library, PHPStan, Pint, Vite, Git.

---

## Global constraints

- Binding design: `docs/superpowers/specs/2026-08-23-workflow-studio-editor-redesign-design.md` at commit `b0dfaca`, subsequently approved by the user.
- At execution time invoke `superpowers:using-git-worktrees`; implement in an ignored worktree from then-current local `main`, never directly on `main`.
- Use strict red-green-refactor TDD. Run and commit each focused failing counterexample before the minimum passing production change, then commit each coherent green task.
- Use `apply_patch` for hand edits. Preserve unrelated work and stop on overlapping changes.
- Do not pull, push, tag, publish, open a PR, or otherwise mutate a remote.
- Do not change graph JSON semantics, draft permissiveness, revision concurrency, publish sequencing, tenant scoping, policy gates, host `FieldControlProps`, or host renderer body ownership.
- `Canvas` stays usable by `FlowRun` and does not learn autosave, validation, publishing, or run-domain vocabulary.
- Stored positions win on hydration. Automatic topology placement fills missing positions only; explicit Auto layout is the sole action that repositions every node.
- Draft cycles and disconnected components are legal editor states. Layout is deterministic and non-throwing for them even though publishing rejects cycles.
- Do not add a layout, drag/drop, or icon peer dependency. React, React DOM, and `@xyflow/react` remain the only frontend peers.
- Keep Tailwind classes statically discoverable under `resources/js`; add no host stylesheet installation step.
- Canvas shortcuts have visible controls, support Cmd/Ctrl where relevant, and are suppressed inside inputs, textareas, selects, contenteditable regions, and host controls.
- Validate is non-mutating, uses the existing `publish` policy, calls `GraphValidator`, returns warnings on success and failure, and never saves a draft or creates a version.
- Publish remains authoritative and revalidates even after Validate succeeds.
- Selection, panels, search, and viewport are ephemeral UI; they never enter history or autosave.
- Use `/Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo` as the representative host. Do not reset or reseed its database without separate authorization.
- Before a demo gate prove the package symlink target, repoint only that exact link to the feature worktree, and restore the original exact target afterward.
- Measure actual final test/assertion counts; never predict or pad them.

---

## File map

### Create

- `resources/js/editor/validation.ts` and `.test.ts` — strict validation response interpretation.
- `resources/js/editor/history.ts` and `.test.ts` — graph-document undo/redo and edit transactions.
- `resources/js/graph/layout.ts` and `.test.ts` — deterministic SCC-aware topology layout and missing-position policy.
- `resources/js/presentation/icons.tsx` — internal dependency-free icon set.
- `resources/js/presentation/node.ts` and `.test.tsx` — category presentation and human configuration summaries.
- `resources/js/canvas/WorkflowEdge.tsx` — smooth stepped edge with readable output chip.
- `resources/js/editor/NodeLibrary.tsx` and `.test.tsx` — grouped searchable click/drag library.
- `resources/js/editor/FlowOverview.tsx` — deselected graph and readiness view.
- `resources/js/editor/NodeInspector.tsx` and `Inspector.test.tsx` — Configure/Advanced disclosure.
- `resources/js/editor/EditorToolbar.tsx`, `EditorNotices.tsx`, and `EditorChrome.test.tsx` — package chrome and persistence feedback.
- `resources/js/editor/CanvasHud.tsx` — compact canvas counts and readiness overlay.
- `resources/js/editor/EditorShell.tsx` and `.test.tsx` — workspace/embedded layout, drawers, and resizable panels.
- `resources/js/editor/useEditorController.ts` and `.test.tsx` — graph/history plus autosave, Validate, and Publish orchestration.
- `docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign-execution-record.md` — measured evidence.

### Modify

- `src/Http/routes.php`, `src/Http/Controllers/FlowEditorController.php`, `tests/Feature/EditorRoutesTest.php` — Validate route, URL, policy, and response.
- `resources/js/graph/types.ts`, `toCanvas.ts`, and tests — additive validation URL/types and topology hydration.
- `resources/js/canvas/layout.ts`, `Canvas.tsx`, `NodeCard.tsx`, `context.ts`, and tests — geometry, controls, cards, ports, paths, and minimap.
- `resources/js/editor/ConfigPanel.tsx` and tests — Configure-tab field content only.
- `resources/js/editor/FlowEditor.tsx` and tests — controller/shell composition and interactions.
- `resources/js/index.ts` and tests — additive public props/types.
- `resources/js/run/FlowRun.tsx` and tests — improved shared canvas with no edit affordances.
- GitBook editor, custom appearance, route/Inertia, route reference, graph format, and testing pages.
- Demo `resources/js/pages/nodeflow/editor.tsx` — remove host sizing that fights workspace mode and use the supported navigation slot.

### Delete after replacement

- `resources/js/editor/Palette.tsx`
- `resources/js/editor/Palette.test.tsx`

---

## Phase 0: isolated worktree and baseline

- [ ] **Step 1: verify source and demo state**

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow
git branch --show-current
git rev-parse HEAD
git status --short
git worktree list --porcelain

git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo branch --show-current
git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo rev-parse HEAD
git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo status --short
realpath /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo/vendor/atram/laravel-nodeflow
```

Expected: package `main` contains the approved design and plan, both trees have no unexplained changes, the demo remains on `feature/nodeflow-demo`, and the package link target is recorded.

- [ ] **Step 2: create the isolated worktree**

Invoke `superpowers:using-git-worktrees` with:

```text
branch: workflow-studio-editor
path: /Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor
base: local main
```

- [ ] **Step 3: expose locked dependencies without manifest churn**

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor
test ! -e vendor && ln -s /Users/mikelmao/Projects/laravel-nodeflow/vendor vendor || true
test ! -e node_modules && ln -s /Users/mikelmao/Projects/laravel-nodeflow/node_modules node_modules || true
test -x vendor/bin/pest
test -x node_modules/.bin/vitest
git status --short
```

Expected: ignored symlinks only. If targets are absent, install from existing lockfiles in package `main`; do not change manifests.

- [ ] **Step 4: measure the source baseline**

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
```

Expected: existing PHP/frontend suites pass, TypeScript is silent, Composer is valid, and diff check is clean. Record actual counts.

- [ ] **Step 5: create and commit the execution record**

Use these headings:

```markdown
# Workflow Studio editor redesign execution record

## Starting state
## Task 1 — validation endpoint
## Task 2 — client validation contract
## Task 3 — topology layout
## Task 4 — document history
## Task 5 — cards and edges
## Task 6 — canvas controls
## Task 7 — node library
## Task 8 — inspector
## Task 9 — toolbar, notices, and shell
## Task 10 — controller integration
## Documentation and demo verification
## Reviews and final gates
```

```bash
git add docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign-execution-record.md
git diff --cached --check
git commit -m "docs: start Workflow Studio execution record"
```

---

## Task 1: add the authoritative non-mutating validation endpoint

**Files:**

- Modify: `src/Http/routes.php`
- Modify: `src/Http/Controllers/FlowEditorController.php`
- Modify: `tests/Feature/EditorRoutesTest.php`
- Update: execution record

- [ ] **Step 1: write failing HTTP contract tests**

Add tests using the existing `beforeEach`, `allowEverything()`, and `exitGraph()` helpers:

```php
it('validates a graph without saving or publishing it', function () {
    allowEverything();
    $before = $this->flow->only(['draft_graph', 'draft_revision', 'current_version_id']);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => exitGraph()])
        ->assertOk()
        ->assertExactJson(['valid' => true, 'warnings' => []]);

    expect($this->flow->fresh()->only(array_keys($before)))->toBe($before)
        ->and($this->flow->versions()->count())->toBe(0);
});

it('returns structured semantic validation errors', function () {
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", [
            'graph' => ['start' => '', 'nodes' => [], 'edges' => []],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('valid', false)
        ->assertJsonPath('message', 'The flow is not ready to publish.')
        ->assertJsonStructure(['errors', 'node_errors', 'warnings']);
});

it('requires publish authorization to validate publish readiness', function () {
    Gate::define('nodeflow.publish', fn () => false);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/validate", ['graph' => exitGraph()])
        ->assertForbidden();
});
```

Extend edit-props coverage to require `urls.validate`. Add a foreign-tenant POST case returning `404` before policy authorization and a warning-producing graph proving warnings appear on success/failure without mutation.

- [ ] **Step 2: run focused tests and prove RED**

```bash
vendor/bin/pest tests/Feature/EditorRoutesTest.php --filter='validat|editor props' --compact
```

Expected: FAIL because the route and URL do not exist.

```bash
git add tests/Feature/EditorRoutesTest.php
git commit -m "test: define editor validation contract"
```

- [ ] **Step 3: register and implement validation**

Add between draft and publish routes:

```php
Route::post('flows/{flow}/validate', [FlowEditorController::class, 'validate'])
    ->name('nodeflow.flows.validate');
```

Import `Nodeflow\Graph\Graph` and `Nodeflow\Graph\GraphValidator`. Add the URL to edit props:

```php
'validate' => route(
    $this->routeName($request, 'nodeflow.flows.validate', 'nodeflow.flows.edit'),
    ['flow' => $flow],
),
```

Add before `publish()`:

```php
public function validate(Request $request, Flow $flow, GraphValidator $validator): JsonResponse
{
    $this->authorize('publish', $flow);
    $request->validate($this->graphRules());

    $result = $validator->validate(Graph::fromArray($request->input('graph')));
    $body = [
        'valid' => $result->passes(),
        'warnings' => $result->warnings(),
    ];

    if ($result->passes()) {
        return response()->json($body);
    }

    return response()->json($body + [
        'message' => 'The flow is not ready to publish.',
        'errors' => $result->errors(),
        'node_errors' => $result->nodeErrors(),
    ], 422);
}
```

Do not call `SaveDraft` or `PublishFlow`.

- [ ] **Step 4: prove GREEN and prefix compatibility**

```bash
vendor/bin/pest tests/Feature/EditorRoutesTest.php tests/Feature/StructuredPublishErrorsTest.php --compact
php artisan route:list --name=nodeflow.flows.validate
```

Expected: focused tests pass and the route is listed. Ensure the existing prefixed-route test also asserts the generated validation URL.

- [ ] **Step 5: commit**

```bash
git add src/Http/routes.php src/Http/Controllers/FlowEditorController.php tests/Feature/EditorRoutesTest.php docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign-execution-record.md
git diff --cached --check
git commit -m "feat: add non-mutating flow validation"
```

---

## Task 2: parse validation outcomes on the client

**Files:**

- Create: `resources/js/editor/validation.ts`
- Create: `resources/js/editor/validation.test.ts`
- Modify: `resources/js/graph/types.ts`
- Modify: `resources/js/index.ts`
- Modify: `resources/js/index.test.ts`

- [ ] **Step 1: write failing parser tests**

Use this public vocabulary:

```ts
export type ValidationOutcome =
    | { kind: 'valid'; warnings: string[] }
    | { kind: 'invalid'; errors: string[]; warnings: string[]; byNode: Record<string, NodeErrorEntry[]>; unplaceable: string[] }
    | { kind: 'structural'; developer: string[] }
    | { kind: 'failed'; message: string }
```

Cover valid-with-warnings, semantic `422`, structural Laravel `422`, malformed `node_errors`, session expiry, and generic status failure. Representative assertion:

```ts
it('groups known node errors and retains graph-wide errors', () => {
    const result = interpretValidation({
        ok: false,
        status: 422,
        data: {
            valid: false,
            errors: ['invalid graph'],
            warnings: ['sequential waits'],
            node_errors: [
                { node: 'send1', field: 'template', message: 'Required.' },
                { node: null, field: null, message: 'Cycle.' },
            ],
        },
    }, new Set(['send1']))

    expect(result).toMatchObject({
        kind: 'invalid',
        warnings: ['sequential waits'],
        unplaceable: ['Cycle.'],
        byNode: { send1: [{ field: 'template', message: 'Required.' }] },
    })
})
```

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/editor/validation.test.ts
git add resources/js/editor/validation.test.ts
git commit -m "test: define editor validation outcomes"
```

Expected: test fails because the module does not exist before the RED commit.

- [ ] **Step 3: implement strict parsing and additive types**

Change only this wire type:

```ts
export type EditorUrls = {
    draft: string
    publish: string
    options: string
    validate?: string
}
```

Mirror `publish.ts` own-property and shape checks. The top-level decision must be:

```ts
if (result.ok) {
    return result.data?.valid === true && isStringArray(result.data.warnings)
        ? { kind: 'valid', warnings: result.data.warnings }
        : { kind: 'failed', message: 'The validation response had an invalid success shape.' }
}
if (result.status === 419) {
    return { kind: 'failed', message: 'Your session expired before this flow could be validated. Reload the page and try again.' }
}
if (result.status === 422 && Object.prototype.hasOwnProperty.call(result.data ?? {}, 'node_errors')) {
    return semanticOutcome(result.data ?? {}, knownNodeIds)
}
if (result.status === 422) return structuralOutcome(result.data?.errors)
return {
    kind: 'failed',
    message: typeof result.data?.message === 'string'
        ? result.data.message
        : `The flow could not be validated (HTTP ${result.status}).`,
}
```

Implement local `isNodeErrorEntry`, `isStringArray`, `semanticOutcome`, and structural-error guards without loosening `publish.ts`. Export `ValidationOutcome` from `index.ts` and assert that export.

- [ ] **Step 4: prove GREEN and commit**

```bash
npx vitest run resources/js/editor/validation.test.ts resources/js/editor/publish.test.ts resources/js/index.test.ts
npx tsc --noEmit
git add resources/js/editor/validation.ts resources/js/editor/validation.test.ts resources/js/graph/types.ts resources/js/index.ts resources/js/index.test.ts
git diff --cached --check
git commit -m "feat: interpret editor validation results"
```

---

## Task 3: replace index-grid placement with deterministic topology layout

**Files:**

- Create: `resources/js/graph/layout.ts`
- Create: `resources/js/graph/layout.test.ts`
- Modify: `resources/js/graph/toCanvas.ts`
- Modify: `resources/js/graph/toCanvas.test.ts`
- Modify: `resources/js/canvas/layout.ts`

- [ ] **Step 1: write failing layout tests**

Use this boundary:

```ts
export type Point = { x: number; y: number }
export function hierarchicalLayout(
    nodeIds: string[],
    edges: Array<{ from: string; to: string }>,
    startId: string,
): Record<string, Point>
export function positionsForGraph(graph: Graph): Record<string, Point>
```

Prove sequence x-order, branch y-separation, deterministic cycle handling, disconnected components below the primary component, and own-key safety for `constructor`, `toString`, and `__proto__`. Add `toCanvas` tests proving all stored coordinates survive exactly, an unpositioned flood graph is layered, and a partial graph preserves stored nodes while nudging missing nodes away.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/graph/layout.test.ts resources/js/graph/toCanvas.test.ts
git add resources/js/graph/layout.test.ts resources/js/graph/toCanvas.test.ts
git commit -m "test: define workflow topology layout"
```

- [ ] **Step 3: centralize geometry**

Replace the old grid with:

```ts
export const NODE_WIDTH = 256
export const NODE_MIN_HEIGHT = 112
export const LAYER_GAP = 152
export const ROW_GAP = 48
export const COMPONENT_GAP = 96
export const CANVAS_ORIGIN = { x: 72, y: 88 }
export const HANDLE_ROW_HEIGHT = 28

export function outputHandleTop(index: number, count: number): number {
    return count === 1 ? NODE_MIN_HEIGHT / 2 : 64 + index * HANDLE_ROW_HEIGHT
}
```

- [ ] **Step 4: implement SCC-aware hierarchical layout**

Implement these concrete internals:

```ts
function adjacency(nodeIds: string[], edges: Array<{ from: string; to: string }>): Map<string, string[]>
function stronglyConnectedComponents(nodeIds: string[], next: Map<string, string[]>): string[][]
function componentDag(components: string[][], edges: Array<{ from: string; to: string }>): ComponentGraph
function componentLayers(graph: ComponentGraph, startId: string): Map<number, number>
function orderLayer(layer: number[], previous: number[], graph: ComponentGraph): number[]
function placeComponents(components: string[][], layers: Map<number, number>, order: number[][]): Record<string, Point>
function nudgePastOccupied(point: Point, occupied: Point[]): Point
```

Define the condensation shape once in the same module:

```ts
type ComponentGraph = {
    components: string[][]
    componentByNode: Map<string, number>
    incoming: Map<number, number[]>
    outgoing: Map<number, number[]>
}
```

Tarjan traversal follows input order. Condensation edges are unique. Layers use longest predecessor distance with the start component first. Apply stable forward/backward barycentric sweeps; original order breaks ties. Stack members of a cyclic component in one x-layer. Place disconnected roots below the start component. Return an `Object.create(null)` record. `positionsForGraph` keeps every valid stored coordinate and nudges only missing nodes.

- [ ] **Step 5: migrate `toCanvas`**

```ts
const positions = positionsForGraph(graph)
const nodes = (graph.nodes ?? []).map((node): CanvasNode => ({
    id: node.id,
    type: 'nodeflowNode',
    position: positions[node.id] ?? CANVAS_ORIGIN,
    data: {
        id: node.id,
        type: node.type,
        config: toConfig(node.config),
        isStart: node.id === graph.start,
    },
}))
```

- [ ] **Step 6: prove GREEN, no dependency change, and commit**

```bash
npx vitest run resources/js/graph/layout.test.ts resources/js/graph/toCanvas.test.ts resources/js/graph/toGraph.test.ts
npx tsc --noEmit
git diff -- package.json package-lock.json
git add resources/js/graph/layout.ts resources/js/graph/layout.test.ts resources/js/graph/toCanvas.ts resources/js/graph/toCanvas.test.ts resources/js/canvas/layout.ts
git diff --cached --check
git commit -m "feat: add deterministic workflow layout"
```

---

## Task 4: add graph-only undo and redo history

**Files:**

- Create: `resources/js/editor/history.ts`
- Create: `resources/js/editor/history.test.ts`

- [ ] **Step 1: write failing history tests**

Define and test:

```ts
export type History<T> = {
    past: T[]
    present: T
    future: T[]
    transaction: string | null
}
export function createHistory<T>(initial: T): History<T>
export function commitHistory<T>(history: History<T>, next: T, transaction?: string): History<T>
export function closeTransaction<T>(history: History<T>): History<T>
export function undoHistory<T>(history: History<T>): History<T>
export function redoHistory<T>(history: History<T>): History<T>
export function resetHistory<T>(next: T): History<T>
```

Prove the first commit pushes prior state, repeated `config:node:key` replaces present without growing past, another transaction pushes, undo creates redo, commit-after-undo clears future, reset clears both, empty undo/redo preserves identity, and history caps at 100.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/editor/history.test.ts
git add resources/js/editor/history.test.ts
git commit -m "test: define workflow document history"
```

- [ ] **Step 3: implement immutable transitions**

```ts
export function commitHistory<T>(history: History<T>, next: T, transaction: string | null = null): History<T> {
    if (Object.is(history.present, next)) return history
    if (transaction !== null && transaction === history.transaction) {
        return { ...history, present: next, future: [] }
    }
    return {
        past: [...history.past, history.present].slice(-100),
        present: next,
        future: [],
        transaction,
    }
}
```

Undo/redo move exactly one document between stacks and close the active transaction. Empty operations return the original object. Reset creates empty stacks around the authoritative document.

- [ ] **Step 4: prove GREEN and commit**

```bash
npx vitest run resources/js/editor/history.test.ts
npx tsc --noEmit
git add resources/js/editor/history.ts resources/js/editor/history.test.ts
git diff --cached --check
git commit -m "feat: add workflow document history"
```

---

## Task 5: redesign node presentation and connection paths

**Files:**

- Create: `resources/js/presentation/icons.tsx`
- Create: `resources/js/presentation/node.ts`
- Create: `resources/js/presentation/node.test.tsx`
- Create: `resources/js/canvas/WorkflowEdge.tsx`
- Modify: `resources/js/canvas/NodeCard.tsx`
- Modify: `resources/js/canvas/canvas.test.tsx`
- Regression: `resources/js/run/FlowRun.test.tsx`

- [ ] **Step 1: write failing helper/card tests**

Test:

```ts
export type CategoryPresentation = {
    accent: 'sky' | 'emerald' | 'amber' | 'violet' | 'rose' | 'slate'
    icon: NodeIconName
}
export function categoryPresentation(group: string): CategoryPresentation
export function nodeSummary(data: NodeCardData, def?: NodeTypePayload): string
```

Required cases: field definition order beats object-key order; booleans become Yes/No; arrays show two items plus count; long text truncates; absent required value says “Needs configuration”; optional empty nodes use description; category result is deterministic. Update card tests to require the wider wrapper, human label and one summary, no raw ID/type in the normal body, separate output rows, issue/start badges, loud unknown type, retained host body, run badges, and package-owned full errors.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/presentation/node.test.tsx resources/js/canvas/canvas.test.tsx
git add resources/js/presentation/node.test.tsx resources/js/canvas/canvas.test.tsx
git commit -m "test: define workflow node presentation"
```

- [ ] **Step 3: implement dependency-free presentation**

`icons.tsx` exports `NodeflowIcon` for category and toolbar names. Every SVG uses `viewBox="0 0 24 24"`, `fill="none"`, `stroke="currentColor"`, `aria-hidden="true"`, and accepts `className`.

Use a stable string hash modulo six. Declare complete literal class variants:

```ts
export const categoryClasses = {
    sky: 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-300',
    emerald: 'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    amber: 'border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300',
    violet: 'border-violet-500/40 bg-violet-500/10 text-violet-700 dark:text-violet-300',
    rose: 'border-rose-500/40 bg-rose-500/10 text-rose-700 dark:text-rose-300',
    slate: 'border-slate-500/40 bg-slate-500/10 text-slate-700 dark:text-slate-300',
} as const
```

Prefer `def.icon` text and use the internal category icon only as fallback.

- [ ] **Step 4: implement card and edge wrappers**

Use this ownership structure:

```tsx
<article className={cardClass} style={{ width: NODE_WIDTH }} aria-label={def?.label ?? data.type}>
    <Handle type="target" position={Position.Left} isConnectable={isConnectable} />
    <header>{/* icon, human label, start/issue badges */}</header>
    <Body data={data} def={def} selected={selected} errors={errors} />
    {/* run decoration badges */}
    {/* mandatory full error list */}
    <div aria-label="Outputs">{/* labelled rows plus owned handles */}</div>
</article>
```

The default renderer emits only `nodeSummary` or unknown-type diagnosis; it does not repeat the header. `WorkflowEdge` uses `getSmoothStepPath` and `EdgeLabelRenderer`, placing `edge.label` in a non-interactive rounded chip away from ports.

- [ ] **Step 5: prove GREEN and commit**

```bash
npx vitest run resources/js/presentation/node.test.tsx resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx
npx tsc --noEmit
git add resources/js/presentation resources/js/canvas/WorkflowEdge.tsx resources/js/canvas/NodeCard.tsx resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx
git diff --cached --check
git commit -m "feat: redesign workflow nodes and paths"
```

---

## Task 6: expose controlled canvas workspace actions

**Files:**

- Modify: `resources/js/canvas/Canvas.tsx`
- Modify: `resources/js/canvas/canvas.test.tsx`
- Modify: `resources/js/graph/toCanvas.ts`
- Modify: `resources/js/graph/types.ts`
- Modify: `resources/js/run/FlowRun.tsx`
- Modify: `resources/js/run/FlowRun.test.tsx`

- [ ] **Step 1: write failing canvas capability tests**

Extend the boundary:

```ts
export type CanvasActions = {
    fit: () => void
    centerNode: (id: string) => void
    screenToFlowPosition: (point: { x: number; y: number }) => { x: number; y: number }
}

export type CanvasProps = {
    // existing props unchanged
    onPaneClick?: () => void
    onEdgeClick?: (id: string) => void
    onDropNodeType?: (type: string, position: { x: number; y: number }) => void
    onReady?: (actions: CanvasActions) => void
    showMinimap?: boolean
}
```

Prove pane callback, edge-selection callback, exact `application/x-nodeflow-node-type` drop conversion,
Fit instance call, conditional minimap, custom edge registration, and no edit/drop callbacks in
read-only `FlowRun`.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx
git add resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx
git commit -m "test: define workflow canvas controls"
```

- [ ] **Step 3: implement the controlled React Flow boundary**

Capture the instance through `onInit` and expose memoized actions:

```ts
const actions: CanvasActions = {
    fit: () => void instance.fitView({ padding: 0.22, duration: reducedMotion ? 0 : 220 }),
    centerNode: (id) => {
        const node = instance.getNode(id)
        if (node) void instance.setCenter(
            node.position.x + NODE_WIDTH / 2,
            node.position.y + NODE_MIN_HEIGHT / 2,
            { zoom: Math.max(instance.getZoom(), 0.85), duration: reducedMotion ? 0 : 220 },
        )
    },
    screenToFlowPosition: (point) => instance.screenToFlowPosition(point),
}
```

Derive `reducedMotion` without breaking SSR:

```ts
const reducedMotion = typeof window !== 'undefined'
    && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches
```

Register `edgeTypes={{ nodeflowEdge: WorkflowEdge }}` at module scope and make `toCanvas` set `type: 'nodeflowEdge'`. Add `<MiniMap pannable zoomable />` only when requested. Theme Background/Controls via semantic tokens. Drop accepts only the exact MIME type and does nothing when `interactive` is false.

- [ ] **Step 4: prove GREEN and commit**

```bash
npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx resources/js/graph/toCanvas.test.ts
npx tsc --noEmit
git add resources/js/canvas/Canvas.tsx resources/js/canvas/canvas.test.tsx resources/js/graph/toCanvas.ts resources/js/graph/types.ts resources/js/run/FlowRun.tsx resources/js/run/FlowRun.test.tsx
git diff --cached --check
git commit -m "feat: add workflow canvas controls"
```

---

## Task 7: replace Palette with the searchable Node Library

**Files:**

- Create: `resources/js/editor/NodeLibrary.tsx`
- Create: `resources/js/editor/NodeLibrary.test.tsx`
- Delete after GREEN: `resources/js/editor/Palette.tsx`
- Delete after GREEN: `resources/js/editor/Palette.test.tsx`

- [ ] **Step 1: write failing library tests**

Use:

```ts
export type NodeLibraryProps = {
    palette: NodeTypePayload[]
    onAdd: (definition: NodeTypePayload) => void
    onRequestClose?: () => void
    searchInputRef?: Ref<HTMLInputElement>
}
```

Prove stable group/label sorting, filtering by label/group/description/type, result count, no-results and no-registered states, click/Enter add, and drag payload:

```ts
fireEvent.dragStart(screen.getByRole('button', { name: /add send message/i }), { dataTransfer })
expect(dataTransfer.setData).toHaveBeenCalledWith(
    'application/x-nodeflow-node-type',
    'app.send',
)
```

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/editor/NodeLibrary.test.tsx
git add resources/js/editor/NodeLibrary.test.tsx
git commit -m "test: define searchable node library"
```

- [ ] **Step 3: implement accessible search/click/drag**

Render `<aside aria-label="Node Library">`, labelled search, polite count, group sections, and real buttons. Buttons show icon/fallback, label, and concise description; technical type is secondary but searchable. Export pure `filterNodeDefinitions`; normalize with `trim().toLocaleLowerCase()` across all four fields. Desktop dragging never disables button behavior.

- [ ] **Step 4: prove GREEN, delete Palette, and commit**

```bash
npx vitest run resources/js/editor/NodeLibrary.test.tsx
rg "Palette" resources/js || true
npx tsc --noEmit
git add resources/js/editor/NodeLibrary.tsx resources/js/editor/NodeLibrary.test.tsx resources/js/editor/Palette.tsx resources/js/editor/Palette.test.tsx
git diff --cached --check
git commit -m "feat: add searchable node library"
```

---

## Task 8: build Flow Overview and progressive node inspector

**Files:**

- Create: `resources/js/editor/FlowOverview.tsx`
- Create: `resources/js/editor/NodeInspector.tsx`
- Create: `resources/js/editor/Inspector.test.tsx`
- Modify: `resources/js/editor/ConfigPanel.tsx`
- Modify: `resources/js/editor/ConfigPanel.test.tsx`

- [ ] **Step 1: write failing inspector tests**

Use view props rather than passing the controller object. Prove no-selection Flow Overview, counts/version/trigger/start, clickable issues, Configure fields without raw metadata, Advanced ID/type/group/cardinality/outputs, disabled current-start action, Delete only in Advanced, and loud but deletable unknown types.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/editor/Inspector.test.tsx resources/js/editor/ConfigPanel.test.tsx
git add resources/js/editor/Inspector.test.tsx
git commit -m "test: define workflow inspector"
```

- [ ] **Step 3: make ConfigPanel field-content only**

Preserve `FieldRow`, dynamic options, control lookup, and field error mapping. Replace its owning sidebar/metadata/actions with:

```tsx
<div className="space-y-5" aria-label="Node configuration">
    {nodeErrors.length > 0 && <NodeIssueList entries={nodeErrors} />}
    {def === undefined
        ? <UnknownNodeNotice type={node.type} />
        : def.fields.map((field) => <FieldRow key={field.key} {...fieldRowProps(field)} />)}
</div>
```

Define `NodeIssueList`, `UnknownNodeNotice`, and `fieldRowProps` as local helpers using the existing
node/error/control values. Remove `isStart`, `onMakeStart`, and `onDelete` from `ConfigPanelProps`; do
not change the six-key host `FieldControlProps`.

- [ ] **Step 4: implement overview and tabs**

Use a WAI-ARIA two-tab pattern. Configure is initial and resets for a new selection; focusing a field issue opens Configure. Advanced owns developer metadata, Make start, and Delete. Flow Overview distinguishes unchecked/checking/valid/warning/invalid/failed and always exposes unknown types/unresolved outputs before server validation.

- [ ] **Step 5: prove GREEN and commit**

```bash
npx vitest run resources/js/editor/Inspector.test.tsx resources/js/editor/ConfigPanel.test.tsx resources/js/controls
npx tsc --noEmit
git add resources/js/editor/FlowOverview.tsx resources/js/editor/NodeInspector.tsx resources/js/editor/Inspector.test.tsx resources/js/editor/ConfigPanel.tsx resources/js/editor/ConfigPanel.test.tsx
git diff --cached --check
git commit -m "feat: add workflow inspector"
```

---

## Task 9: build package-owned toolbar, notices, and responsive shell

**Files:**

- Create: `resources/js/editor/EditorToolbar.tsx`
- Create: `resources/js/editor/EditorNotices.tsx`
- Create: `resources/js/editor/CanvasHud.tsx`
- Create: `resources/js/editor/EditorChrome.test.tsx`
- Create: `resources/js/editor/EditorShell.tsx`
- Create: `resources/js/editor/EditorShell.test.tsx`

- [ ] **Step 1: write failing toolbar/notice tests**

Use explicit view models:

```ts
export type SaveIndicator = {
    status: 'idle' | 'saving' | 'saved' | 'error' | 'conflict'
    message?: string
}
export type ValidationIndicator = {
    status: 'unchecked' | 'checking' | 'valid' | 'warning' | 'invalid' | 'failed'
    count?: number
}
```

Prove human flow/trigger/version hierarchy, leading/trailing slots, disabled undo/redo, contextual
Delete selected, Fit/Auto layout,
live save state, Validate result/action, Publish state, and named narrow overflow. Notices prove Keep
mine/Use theirs, persistent save failure, structural client defect, semantic graph failure, and
published success use correct alert/status roles. `CanvasHud` tests prove node/connection counts plus
unchecked, ready, warning, and invalid labels without intercepting canvas pointer events.

- [ ] **Step 2: write failing shell tests**

```ts
export type EditorMode = 'workspace' | 'embedded'
export type EditorShellProps = {
    mode: EditorMode
    toolbar: ReactNode
    library: ReactNode
    canvas: ReactNode
    inspector: ReactNode
    notices?: ReactNode
    className?: string
    libraryOpen: boolean
    inspectorOpen: boolean
    onLibraryOpenChange: (open: boolean) => void
    onInspectorOpenChange: (open: boolean) => void
}
```

Prove workspace viewport class, embedded bounded flow, named panel toggles, overlay close/focus return, Escape close, and ArrowLeft/ArrowRight separator resizing within bounds.

- [ ] **Step 3: prove RED**

```bash
npx vitest run resources/js/editor/EditorChrome.test.tsx resources/js/editor/EditorShell.test.tsx
git add resources/js/editor/EditorChrome.test.tsx resources/js/editor/EditorShell.test.tsx
git commit -m "test: define Workflow Studio chrome"
```

- [ ] **Step 4: implement toolbar and notices**

Use internal icons with `aria-label` and visible tooltip/title. Keep Save, Validate, and Publish directly
visible. Auto layout, Fit, undo, and redo appear in full and overflow toolbars. `EditorNotices` accepts
plain autosave/publish/validation props and callbacks; it initiates no request. Routine Saved remains
toolbar-only, while failure/conflict notices persist until state or resolution changes. `CanvasHud` is
a `pointer-events-none` overlay receiving counts and `ValidationIndicator` only.

- [ ] **Step 5: implement shell, drawers, and resizing**

Use one DOM instance per region: grid columns at `lg`, fixed overlay drawers below `lg`. Do not duplicate controls or IDs. Set bounded widths through CSS properties:

```tsx
style={{
    '--nodeflow-library-width': `${libraryWidth}px`,
    '--nodeflow-inspector-width': `${inspectorWidth}px`,
} as React.CSSProperties}
```

Library bounds are 240–400 px; inspector 288–480 px. Separators use pointer capture, `role="separator"`, `aria-orientation="vertical"`, value attributes, and 16 px keyboard increments. Drawers focus their heading on open, close with Escape, and return focus to trigger. Respect reduced motion.

```ts
const modeClass = mode === 'workspace'
    ? 'h-dvh min-h-[42rem] overflow-hidden'
    : 'min-h-[42rem] overflow-hidden rounded-xl border bg-background'
```

- [ ] **Step 6: prove GREEN and commit**

```bash
npx vitest run resources/js/editor/EditorChrome.test.tsx resources/js/editor/EditorShell.test.tsx
npx tsc --noEmit
git add resources/js/editor/EditorToolbar.tsx resources/js/editor/EditorNotices.tsx resources/js/editor/CanvasHud.tsx resources/js/editor/EditorChrome.test.tsx resources/js/editor/EditorShell.tsx resources/js/editor/EditorShell.test.tsx
git diff --cached --check
git commit -m "feat: build Workflow Studio shell"
```

---

## Task 10: integrate document controller and complete interactions

**Files:**

- Create: `resources/js/editor/useEditorController.ts`
- Create: `resources/js/editor/useEditorController.test.tsx`
- Modify: `resources/js/editor/FlowEditor.tsx`
- Modify: `resources/js/editor/FlowEditor.test.tsx`
- Modify: `resources/js/index.ts`
- Modify: `resources/js/index.test.ts`

- [ ] **Step 1: write failing controller tests**

Use:

```ts
export type EditorDocument = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    startId: string
}
```

Prove unique add-at-point and first-start, viewport-center add, collision avoidance, one-step drag undo,
same-field edit coalescing, connection output enforcement, delete cleanup, graph-only undo/autosave,
one-step Auto layout, server-winner history reset, Keep mine revision behavior, Validate-only request,
stale validation suppression, optional-URL fallback, issue focus/centering, unchanged Publish save
barrier, session identity remount, node-versus-edge selection, visible/keyboard deletion of a selected
edge, and an empty-canvas prompt that opens and focuses the Node Library.

- [ ] **Step 2: prove RED**

```bash
npx vitest run resources/js/editor/useEditorController.test.tsx
git add resources/js/editor/useEditorController.test.tsx
git commit -m "test: define Workflow Studio controller"
```

- [ ] **Step 3: implement state and orchestration**

Build initial document through `toCanvas`, store it in `History<EditorDocument>`, and keep selection/panels/validation/publish outside history. Continue deriving the canonical graph through `toGraph` and saving through `useAutosave`.

Expose:

```ts
type EditorActions = {
    addNode: (definition: NodeTypePayload, point?: Point) => void
    addAtViewportCenter: (definition: NodeTypePayload) => void
    nodesChange: (changes: NodeChange<NodeflowNode>[]) => void
    edgesChange: (changes: EdgeChange<NodeflowEdge>[]) => void
    connect: (connection: Connection) => void
    selectNode: (id: string | null) => void
    selectEdge: (id: string | null) => void
    configure: (id: string, key: string, value: unknown) => void
    closeConfigTransaction: () => void
    makeStart: (id: string) => void
    deleteNode: (id: string) => void
    deleteSelection: () => void
    undo: () => void
    redo: () => void
    autoLayout: () => void
    validate: () => Promise<void>
    publish: () => Promise<void>
    resolveConflict: (choice: 'mine' | 'theirs') => void
    registerCanvas: (actions: CanvasActions) => void
    focusIssue: (node: string | null, field: string | null) => void
    setLibraryOpen: (open: boolean) => void
    setInspectorOpen: (open: boolean) => void
}
```

Return an explicit component-facing shape:

```ts
export type UseEditorControllerResult = {
    document: EditorDocument
    selected: NodeflowNode | undefined
    view: { libraryOpen: boolean; inspectorOpen: boolean; selectedEdgeId: string | null }
    actions: EditorActions
    optionsSource: FieldOptionsSource
    canvasProps: CanvasProps
    canvasHudProps: React.ComponentProps<typeof CanvasHud>
    toolbarProps: Omit<React.ComponentProps<typeof EditorToolbar>, 'slots'>
    noticeProps: React.ComponentProps<typeof EditorNotices>
    flowOverviewProps: React.ComponentProps<typeof FlowOverview>
    nodeInspectorProps: React.ComponentProps<typeof NodeInspector>
}
```

Increment document generation once per committed graph action, including undo/redo. Validate and
Publish use separate monotonic request IDs. A graph edit marks prior validation unchecked; late
responses for older generations are ignored. Auto layout calls `hierarchicalLayout` and commits every
position once. Drag uses `move:${id}` through `dragging:false`; fields use
`config:${nodeId}:${key}` until focus leaves the field container or another graph action starts.
Keep node and edge selection outside `EditorDocument`; derive `selected` flags only in canvas view
data. Set React Flow's built-in `deleteKeyCode` to `null` in editor mode and route both the contextual
toolbar button and scoped Delete/Backspace shortcut through `deleteSelection` so an edge cannot be
deleted twice.

- [ ] **Step 4: refactor FlowEditor into composition**

Retain the public remount boundary:

```tsx
export function FlowEditor(props: FlowEditorProps) {
    return <FlowEditorSession key={sessionKey(props)} {...props} />
}
```

Add only:

```ts
export type EditorMode = 'workspace' | 'embedded'
export type ToolbarSlots = {
    leading?: ReactNode
    trailing?: ReactNode
}

mode?: EditorMode
toolbarSlots?: ToolbarSlots
```

Export `EditorMode` and `ToolbarSlots` beside `FlowEditorProps` from `resources/js/index.ts` and add
compile-time export assertions in `index.test.ts`.

Default `mode = 'workspace'`, create `librarySearchRef`, then compose:

```tsx
<FieldOptionsContext.Provider value={controller.optionsSource}>
    <EditorShell
        mode={mode}
        className={className}
        libraryOpen={controller.view.libraryOpen}
        inspectorOpen={controller.view.inspectorOpen}
        onLibraryOpenChange={controller.actions.setLibraryOpen}
        onInspectorOpenChange={controller.actions.setInspectorOpen}
        toolbar={<EditorToolbar {...controller.toolbarProps} slots={toolbarSlots} />}
        notices={<EditorNotices {...controller.noticeProps} />}
        library={(
            <NodeLibrary
                palette={palette}
                onAdd={controller.actions.addAtViewportCenter}
                searchInputRef={librarySearchRef}
            />
        )}
        canvas={(
            <div className="relative h-full">
                <Canvas {...controller.canvasProps} showMinimap />
                <CanvasHud {...controller.canvasHudProps} />
                {controller.document.nodes.length === 0 && (
                    <button type="button" onClick={() => controller.actions.setLibraryOpen(true)}>
                        Add your first node
                    </button>
                )}
            </div>
        )}
        inspector={controller.selected
            ? <NodeInspector {...controller.nodeInspectorProps} />
            : <FlowOverview {...controller.flowOverviewProps} />}
    />
</FieldOptionsContext.Provider>
```

- [ ] **Step 5: add scoped shortcuts and drop behavior**

```ts
function isEditableTarget(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && (
        target.matches('input, textarea, select, [contenteditable="true"]')
        || target.closest('[data-nodeflow-shortcuts="off"]') !== null
    )
}
```

Handle Cmd/Ctrl+Z, Cmd/Ctrl+Shift+Z, Delete/Backspace, Cmd/Ctrl+K, Shift+L, and F only outside editable targets. Search opens the library drawer before focus. Drop looks up the MIME type through an own-key-safe definitions record before adding.

Selecting a node clears edge selection and opens the inspector drawer; selecting an edge clears node
selection; clicking the pane clears both and shows Flow Overview. Map validation `byNode` entries to
Canvas `nodeErrors` and the selected inspector's structured field errors. Map graph-wide and unknown
node entries to Flow Overview/notices so no server message disappears.

- [ ] **Step 6: migrate and extend full editor tests**

Preserve counterfactual coverage for autosave, conflicts, structural errors, publish generation, custom controls/renderers, unsafe property keys, and session remount. Add an accessible full-flow case:

```ts
await user.click(screen.getByRole('button', { name: /add send message/i }))
await user.click(screen.getByRole('tab', { name: 'Configure' }))
await user.type(screen.getByLabelText('Template'), 'welcome')
await user.click(screen.getByRole('button', { name: 'Validate' }))
expect(await screen.findByText('Ready to publish')).toBeInTheDocument()
await user.click(screen.getByRole('button', { name: 'Publish' }))
expect(await screen.findByText('Published v4')).toBeInTheDocument()
```

```bash
npx vitest run resources/js/editor resources/js/canvas resources/js/graph resources/js/run
npx tsc --noEmit
```

- [ ] **Step 7: commit integration**

```bash
git add resources/js/editor/useEditorController.ts resources/js/editor/useEditorController.test.tsx resources/js/editor/FlowEditor.tsx resources/js/editor/FlowEditor.test.tsx resources/js/index.ts resources/js/index.test.ts
git diff --cached --check
git commit -m "feat: integrate Workflow Studio editor"
```

---

## Task 11: document the default and verify the representative demo

**Files:**

- Modify: `docs/gitbook/editor-and-run-view/editor.md`
- Modify: `docs/gitbook/editor-and-run-view/custom-node-appearance.md`
- Modify: `docs/gitbook/integration/routes-and-inertia.md`
- Modify: `docs/gitbook/reference/routes.md`
- Modify: `docs/gitbook/reference/graph-format.md`
- Modify: `docs/gitbook/contributing/testing.md`
- Modify: demo `resources/js/pages/nodeflow/editor.tsx`

- [ ] **Step 1: write failing documentation assertions**

Extend the nearest documentation test to require: eight routes including Validate; exact success/failure JSON; workspace and `mode="embedded"`; `toolbarSlots`; add/search/undo/Auto layout/Fit/minimap/shortcuts; distinct save/validation/publish semantics; unchanged renderer wrapper ownership. Run the focused test and commit RED.

- [ ] **Step 2: write exact GitBook guidance**

Include:

```tsx
import { Link } from '@inertiajs/react'
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'

export default function Editor(props: FlowEditorProps) {
    return (
        <FlowEditor
            {...props}
            toolbarSlots={{ leading: <Link href="/admin/flows">All flows</Link> }}
        />
    )
}
```

Also show `<FlowEditor {...props} mode="embedded" />`. Document that default mode is full-height, theme is inherited, no dependency/CSS step is added, Validate uses publish authorization without mutation, and Publish revalidates.

- [ ] **Step 3: safely point the demo link to the feature**

```bash
demo=/Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo
backup_link="$demo/vendor/atram/laravel-nodeflow.workflow-studio-backup"
test -L "$demo/vendor/atram/laravel-nodeflow"
test ! -e "$backup_link"
mv "$demo/vendor/atram/laravel-nodeflow" "$backup_link"
ln -s /Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor "$demo/vendor/atram/laravel-nodeflow"
test "$(realpath "$demo/vendor/atram/laravel-nodeflow")" = "/Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor"
test -L "$backup_link"
```

- [ ] **Step 4: make only the necessary demo adapter change**

Replace host sizing classes that fight workspace mode:

```tsx
import { Head, Link } from '@inertiajs/react';
import { FlowEditor } from '@nodeflow/editor';
import type { FlowEditorProps } from '@nodeflow/editor';

export default function Editor(props: FlowEditorProps) {
    return (
        <>
            <Head title={`Edit ${props.flow.name}`} />
            <FlowEditor
                {...props}
                toolbarSlots={{
                    leading: <Link href="/dashboard">Demo control room</Link>,
                }}
            />
        </>
    );
}
```

Use the demo's generated route helper instead of the literal URL if one exists; do not invent a route.

- [ ] **Step 5: run package and demo gates**

```bash
vendor/bin/pest tests/Feature/EditorRoutesTest.php --compact
npx vitest run
npx tsc --noEmit

cd /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo
npx prettier --check resources/js/pages/nodeflow/editor.tsx
npx eslint resources/js/pages/nodeflow/editor.tsx
npx tsc --noEmit
npm run build
vendor/bin/pest --compact
```

- [ ] **Step 6: perform real-browser acceptance**

Invoke the applicable browser-control skill and verify: wide dark and light themes; flood graph readability; library search/click/drag add; connect/configure and Configure/Advanced; Flow Overview; undo/redo; Auto layout then undo; Fit/zoom/minimap; invalid Validate, issue focus, correction, successful Validate and Publish; narrow drawers/Escape/focus return; and run view cards without edit controls. Console has no new error.

- [ ] **Step 7: restore link and commit docs/demo**

```bash
demo=/Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo
backup_link="$demo/vendor/atram/laravel-nodeflow.workflow-studio-backup"
test -L "$backup_link"
unlink "$demo/vendor/atram/laravel-nodeflow"
mv "$backup_link" "$demo/vendor/atram/laravel-nodeflow"
test -L "$demo/vendor/atram/laravel-nodeflow"
test ! -e "$backup_link"

cd /Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor
git add docs/gitbook docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign-execution-record.md
git diff --cached --check
git commit -m "docs: document Workflow Studio editor"

git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo diff --check
git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo add resources/js/pages/nodeflow/editor.tsx
git -C /Users/mikelmao/Sites/nodeflow-demo/.worktrees/nodeflow-demo commit -m "feat: adopt Workflow Studio editor"
```

---

## Task 12: reviews, remediation, and final gates

- [ ] **Step 1: request spec compliance review**

Invoke `superpowers:requesting-code-review` on the full feature range. Map every design section and all twelve acceptance criteria to code/tests/evidence. Explicitly inspect validation non-mutation, position preservation, cyclic drafts, extension contracts, responsive behavior, and no new peers. Fix Critical/Important findings with RED/GREEN commits and re-review.

- [ ] **Step 2: request quality/accessibility review**

Focus on request races, transaction boundaries, own-key safety, React Flow remounts, keyboard collisions, focus management, semantic HTML, theme contrast, host extensions, and run-view regressions. No Critical/Important finding remains.

- [ ] **Step 3: run final package gates from clean processes**

Invoke `superpowers:verification-before-completion`, then:

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
git status --short
```

Run repository-established Pint and PHPStan commands with sufficient memory. Record exact tool versions, counts, and outputs.

- [ ] **Step 4: repeat the representative host gate at feature HEAD**

Repoint only the exact demo symlink as in Task 11, run full demo Pest, Prettier, ESLint, TypeScript, and Vite build, then restore the exact original target immediately. Prove both worktrees contain only expected changes.

- [ ] **Step 5: finalize and commit evidence**

Record feature/demo hashes, RED/GREEN evidence, reviewer findings/remediation, final counts, browser widths/themes, acceptance results, symlink restoration, and any genuinely pre-existing warning.

```bash
git add docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign-execution-record.md
git diff --cached --check
git commit -m "docs: record Workflow Studio verification"
git status --short
```

Expected: clean feature worktree. Do not merge, push, or remove it until the user chooses an integration action through `superpowers:finishing-a-development-branch`.
