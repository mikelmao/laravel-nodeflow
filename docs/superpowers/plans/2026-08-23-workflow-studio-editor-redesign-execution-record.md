# Workflow Studio editor redesign execution record

## Starting state

- Package feature worktree: `/Users/mikelmao/Projects/laravel-nodeflow/.worktrees/workflow-studio-editor`
- Branch: `workflow-studio-editor`
- Starting commit: `75c51bd` (`chore: ignore feature worktrees`)
- Binding implementation plan: `docs/superpowers/plans/2026-08-23-workflow-studio-editor-redesign.md`
- Binding design: `docs/superpowers/specs/2026-08-23-workflow-studio-editor-redesign-design.md`
- The worktree uses an ignored local Composer install and the main checkout's ignored
  `node_modules` symlink.
- A whole-`vendor` symlink was rejected during baseline: its generated Composer metadata mapped
  `Tests\` and `Nodeflow\` to the main checkout, so Pest discovered worktree files without applying
  the worktree's Testbench base class. The focused editor route test failed with “A facade root has
  not been set”; the same test passed on main. A worktree-local `composer install` mapped `Tests\`
  to this worktree and made the focused test pass.
- Baseline package verification:
  - Pest: 959 tests, 7,616 assertions, all passing.
  - Vitest: 17 files, 160 tests, all passing.
  - TypeScript: `npx tsc --noEmit` passed silently.
  - `composer validate --no-check-publish` passed.
  - `git diff --check` passed and the tracked worktree was clean.
- The demo feature worktree named in the plan had already been integrated and removed before
  execution. The representative checkout is now `/Users/mikelmao/Sites/nodeflow-demo`, branch
  `main`, commit `bc57ac9`. Its package link resolves to package main. Preserve its pre-existing
  untracked `config/nodeflow.php` during later host verification.

## Task 1 — validation endpoint

- RED: `vendor/bin/pest tests/Feature/EditorRoutesTest.php --filter='validat|editor props' --compact`
  produced 4 expected failures and 1 passing test (15 assertions): `urls.validate` was absent,
  and POSTs to `/flows/{flow}/validate` returned 404. This proves both the server-authored URL
  prop and route were genuinely missing before implementation.
- GREEN (focused): the same command passed 5 tests with 32 assertions.
- GREEN (required regression set):
  `vendor/bin/pest tests/Feature/EditorRoutesTest.php tests/Feature/StructuredPublishErrorsTest.php --compact`
  passed 31 tests with 113 assertions.
- Added the tenant-bound `POST flows/{flow}/validate` route between draft and publish. The
  controller authorizes `publish`, applies the existing structural graph rules, then calls the
  authoritative `GraphValidator` directly. It returns `{valid, warnings}` on success and adds
  the semantic `message`, `errors`, and `node_errors` on 422; it does not call `SaveDraft` or
  `PublishFlow`.
- Tests demonstrate no draft, revision, current-version, or version-count mutation; tenant route
  binding returns 404 before authorization; warnings survive both a valid response and a semantic
  error response; and prefixed host route names resolve `urls.validate` alongside the sibling
  editor URLs. Counterfactuals: skipping `publish` authorization makes an update-only editor
  receive 200, and routing validation through draft/publish would change the state assertions.

## Task 2 — client validation contract

- RED: `npx vitest run resources/js/editor/validation.test.ts` failed as expected because
  `resources/js/editor/validation.ts` did not yet exist (Vite could not resolve `./validation`).
- GREEN: added the strict validation-result interpreter, including valid warnings, semantic
  node-error grouping, structural developer errors, session-expiry recovery, and stable malformed
  response failures. `EditorUrls.validate` is optional for backwards-compatible server props, and
  `ValidationOutcome` is exported from the package root.
- GREEN verification: `npx vitest run resources/js/editor/validation.test.ts
  resources/js/editor/publish.test.ts resources/js/index.test.ts` passed 3 files / 16 tests;
  `npx tsc --noEmit` and `git diff --check` passed silently.
- Review fix: a focused RED expectation proved grouped semantic entries had incorrectly stripped
  their node id. The GREEN parser now reuses the shared `NodeErrorEntry` graph contract and
  preserves the complete entry, matching publish-result handling.

## Task 3 — topology layout

- RED: `npx vitest run resources/js/graph/layout.test.ts resources/js/graph/toCanvas.test.ts` failed as
  expected because the new `./layout` module did not yet exist; the pre-existing canvas adapter
  tests continued to pass. The RED suite specifies strict sequence layers, readable branch rows,
  deterministic finite cyclic layouts, disconnected placement below the start component, null-
  prototype output for `constructor`, `toString`, and `__proto__`, stored-coordinate preservation,
  and collision-free placement for partial drafts.
- GREEN: added SCC-aware, deterministic topology placement. Tarjan condensation removes cycles
  before longest-predecessor layers and stable barycentric sweeps order each layer. Reachable
  start components are placed first; weakly disconnected component groups begin below them.
  Stored finite positions remain exact, while only missing positions are nudged below occupied
  node rectangles. Layout records have a null prototype so persisted prototype-like IDs are safe.
- The canvas adapter now gets all positions from `positionsForGraph`, retaining its data and edge
  transformations. Shared node dimensions, gaps, origin, and handle geometry live in
  `canvas/layout.ts`; the node card passes its output count to the count-aware handle placement.
- GREEN verification: `npx vitest run resources/js/graph/layout.test.ts
  resources/js/graph/toCanvas.test.ts resources/js/graph/toGraph.test.ts` passed 3 files / 23
  tests; `npx tsc --noEmit`, `git diff -- package.json package-lock.json`, and `git diff --check`
  passed.
- Review follow-up (scalability): RED added 5,000-node chain and cycle regressions. Both failed
  with `RangeError: Maximum call stack size exceeded` in recursive Tarjan traversal. GREEN uses
  explicit DFS frames, preserving the input-order traversal and SCC pop semantics without using
  the JavaScript call stack. The same regressions now pass.
- Review follow-up (sweep complexity): the original sweep rebuilt a position map for every
  component on every layer sort. It now caches positions per row and reads only the already-swept
  adjacent row, invalidating just the row it reorders. A chain therefore has linear row-map work
  instead of quadratic global-map rebuilding; barycentric ties still use original input order.
  Focused GREEN verification passed 3 files / 25 tests, TypeScript and diff checks passed, and a
  direct 10,000-node chain sanity layout completed with finite output in 45.1 ms locally.

## Task 4 — document history

- RED: `npx vitest run resources/js/editor/history.test.ts` failed as expected because
  `resources/js/editor/history.ts` did not yet exist and Vite could not resolve `./history`.
- GREEN: added generic, immutable document history transitions with bounded chronological past,
  front-ordered redo entries, authoritative reset, transaction coalescing, and identity-preserving
  no-ops. Undo and redo each move one document and close any active transaction.
- GREEN verification: `npx vitest run resources/js/editor/history.test.ts` passed 1 file / 12
  tests; `npx tsc --noEmit` and `git diff --check` passed silently.
- Quality-review coverage addition: a multi-step `initial → 1 → 2 → 3` regression undoes twice
  and proves redo order is `2` then `3`, including the final chronological past stack. This is a
  test-only guard for the established history implementation. Its focused Vitest run passed 1 file
  / 13 tests; TypeScript and diff checks also passed.

## Task 5 — cards and edges

- RED: `npx vitest run resources/js/presentation/node.test.tsx resources/js/canvas/canvas.test.tsx`
  failed in the expected way: Vite could not resolve the deliberately absent
  `presentation/node` helpers or `canvas/WorkflowEdge` component. The test-only
  contract was committed as `feef36b` (`test: define workflow node presentation`).
- GREEN: added deterministic category presentation, concise field-order-aware
  node summaries, and dependency-free SVG icons. `NodeCard` now owns the
  accessible human header, start/issue badges, target and labelled source port
  rows, run decorations, and the complete error list while retaining the host
  renderer exclusively as its body. Known cards no longer expose raw node IDs
  or technical types; unknown cards remain explicitly diagnosable.
- Added `WorkflowEdge` with smooth-step routing, preserved edge marker/style,
  and a non-interactive label chip. Edge-type registration is intentionally
  deferred to Task 6, where the canvas registry and adapter edge type change
  together.
- Regression adjustment: FlowRun interaction tests now click the stable React
  Flow node wrapper (`rf__node-*`) rather than an ID printed in card content.
- GREEN verification: `npx vitest run resources/js/presentation/node.test.tsx
  resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx` passed
  3 files / 32 tests; `npx tsc --noEmit` passed silently.
- Spec-review follow-up RED: the focused `NodeCard` regression failed because
  each output row had `h-6` with no positioning context while its child handle
  used the whole-card `outputHandleTop` offset. The failing test was committed
  as `85e92cb` (`test: cover output row handle alignment`).
- Spec-review GREEN: output rows are now relative, fixed 28px (`h-7`) owners;
  their `Position.Right` source handles are vertically centered with
  `top: 50%` and `translateY(-50%)`. The shared layout API remains unchanged,
  and NodeCard no longer imports the card-global output offset helper.
- Follow-up verification: `npx vitest run resources/js/presentation/node.test.tsx
  resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx` passed
  3 files / 33 tests; `npx tsc --noEmit` and `git diff --check` passed silently.
- Alignment precision follow-up RED: the row-ownership regression was tightened
  to require `translate(50%, -50%)`, and failed because the handle still used
  only `translateY(-50%)`.
- Alignment precision GREEN: source handles retain their relative row and
  `top: 50%`, and now use the exact right-edge centered transform
  `translate(50%, -50%)`. Focused verification again passed 3 files / 33 tests;
  TypeScript and diff checks passed silently.
- Quality-review RED: a locale simulation made `toLocaleLowerCase()` return a
  Turkish-like distinct form for `INTEGRATION`; category presentation then
  disagreed with `integration`. The edge test was also upgraded to initialise a
  real React Flow store `domNode` with its label-renderer portal, proving the
  chip itself renders rather than merely testing the path component in isolation.
- Quality-review GREEN: category hashing now uses locale-independent
  `toLowerCase()`. The portal-backed edge regression asserts label text,
  transform placement, rounded styling, and `pointer-events-none`, `nodrag`,
  and `nopan` classes. Focused verification passed 3 files / 34 tests;
  TypeScript and diff checks passed silently.

## Task 6 — canvas controls

- RED: `npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx resources/js/graph/toCanvas.test.ts` produced six expected failures: the custom edge type was absent from the adapter/renderer, drag payloads were not accepted, no instance actions were reported, and the minimap was absent. The unaffected read-only run coverage passed. The contract was committed as `ec3c66c` (`test: define workflow canvas controls`).
- GREEN: Canvas now exposes stable instance-backed `fit`, `centerNode`, and screen-coordinate actions after initialization; both animations respect the SSR-safe reduced-motion preference. It bridges pane and edge selection callbacks, accepts only the exact node-type MIME payload while editable, and suppresses drag/drop semantics in read-only mode.
- The canvas registers `WorkflowEdge` at module scope under `nodeflowEdge`, `toCanvas` assigns that edge type, and an opt-in semantic-token minimap is pannable and zoomable. `FlowRun` remains intentionally callback-free and `interactive={false}`.
- GREEN verification: `npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx resources/js/graph/toCanvas.test.ts` passed 3 files / 42 tests. `npx tsc --noEmit` and `git diff --check` passed silently.
- Quality follow-up RED: a protected-mode `dragover` test failed because the canvas called `getData()` before a browser exposes the payload; a measured nested-node action test failed because centering used the stored relative position; the minimap lacked semantic host-theme tokens. The package-root type test also correctly failed TypeScript because `CanvasActions` was not re-exported.
- Quality follow-up GREEN: dragover now recognizes the exact MIME through `Array.from(dataTransfer.types)`, while drop alone reads the non-empty payload. `centerNode` uses React Flow's public `getNodesBounds([node])` absolute measured rectangle and falls back to the shared card dimensions only for zero-size bounds. The minimap has semantic classes and CSS-variable background/border colors, and `CanvasActions` is exported from the package root.
- A real mounted, measured React Flow edge regression fired exactly one edge callback, zero pane callbacks, and then a genuine pane click. React Flow's own pane listener guards on the event target, so this did not reproduce bubbling and no `stopPropagation` was needed.
- Quality follow-up verification: `npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx resources/js/graph/toCanvas.test.ts resources/js/index.test.ts` passed 4 files / 45 tests; `npx tsc --noEmit` and `git diff --check` passed silently.
- Final review RED: a nested node with absolute bounds `{x: 500, y: 600}` and zero dimensions was incorrectly centered from its parent-relative stored position; a partial zero-width bound had the same flaw. The minimap assertion also exposed bare HSL-channel variables as invalid color values.
- Final review GREEN: `centerNode` now always uses the bounds origin and independently replaces only non-positive dimensions with the shared node width/height. The minimap uses `hsl(var(--background))` for its inline SVG-compatible background, while its semantic `border-border` Tailwind class supplies the border without an ineffective SVG inline border override.
- Final review verification: `npx vitest run resources/js/canvas/canvas.test.tsx resources/js/run/FlowRun.test.tsx resources/js/graph/toCanvas.test.ts resources/js/index.test.ts` passed 4 files / 45 tests; `npx tsc --noEmit` and `git diff --check` passed silently.

## Task 7 — node library

- RED: `npx vitest run resources/js/editor/NodeLibrary.test.tsx` failed as expected because
  `resources/js/editor/NodeLibrary.tsx` did not exist. The test-only contract was committed as
  `2e8e042` (`test: define searchable node library`).
- GREEN: replaced `Palette` with an accessible, responsive `NodeLibrary`: deterministic stable
  group/label sorting without mutating registrations; trimmed locale-aware search across human and
  technical metadata; no-registry and no-match states; polite result count; native click/keyboard
  add actions; exact node-type drag payload; safe icon fallback; and optional close/search-ref
  controls. Definitions are grouped with a `Map` to keep prototype-like group keys safe.
- Compatibility: `FlowEditor` now imports `NodeLibrary` and its affected interaction assertions use
  the new accessible add-button names. No controller behavior was integrated; that remains Task 10.
- GREEN verification: `npx vitest run resources/js/editor/NodeLibrary.test.tsx` passed 1 file / 12
  tests. `rg "Palette" resources/js` has no results, `npx tsc --noEmit` passed silently, and
  `git diff --check` passed.
- Quality follow-up RED: a description whose 119th code unit was the first surrogate of the
  multi-code-point `👩‍💻` grapheme rendered a replacement character before the ellipsis.
- Quality follow-up GREEN: concise descriptions now segment graphemes with a fixed-locale
  `Intl.Segmenter` when available, retaining 119 visible units plus the ellipsis. Older SSR/runtime
  environments use deterministic `Array.from()` code-point segmentation, which still avoids broken
  astral characters. Focused verification passed 1 file / 13 tests; TypeScript and diff checks
  passed silently.
- Fallback correction RED: simulating an unavailable `Intl.Segmenter` showed the code-point fallback
  truncated a boundary-crossing `👩‍💻` ZWJ cluster to `👩…`, and would likewise be unsafe for a
  combining cluster.
- Fallback correction GREEN: the unavailable-Segmenter path now returns the full normalized
  description without an ellipsis. The regression restores the global descriptor in `finally` and
  proves both the ZWJ and `é` combining cluster stay intact. Focused verification passed 1 file /
  14 tests; TypeScript and diff checks passed silently.

## Task 8 — inspector

- RED: `npx vitest run resources/js/editor/Inspector.test.tsx resources/js/editor/ConfigPanel.test.tsx`
  failed as intended: the new Flow Overview and Node Inspector modules were absent, while the old
  ConfigPanel still owned the surrounding aside and node actions. The committed specification is
  `3fc1427` (`test: define workflow inspector`).
- GREEN: added explicit-view-prop `FlowOverview` and `NodeInspector` components. The no-selection
  overview reports the human trigger plus type, publication/start/count facts, all six validation
  states, graph messages, ordered navigable issues, and local unknown-type/unresolved-output
  diagnostics independently of validation. The inspector keeps human label/category and editable
  fields in Configure; WAI-ARIA tabs, directional/Home/End keys, selection reset, and optional
  field-issue focusing are built in. Advanced alone contains exact node metadata, start action,
  and deletion. Unregistered nodes remain loud, non-configurable, and deletable.
- ConfigPanel is now field-content only. It retains prototype-safe config lookup, defaults,
  per-field errors, dynamic options, and the unchanged six-key host control contract; node-wide
  errors and unknown-type notices have local helpers without duplicating field errors.
- Compatibility: the legacy selected-node callsite in FlowEditor now renders NodeInspector with the
  already available view values. The two directly affected tests select Advanced before using
  Make start/Delete. This intentionally adds no FlowOverview, shell, or controller state; Task 10
  remains the owner of controller composition.
- GREEN verification: `npx vitest run resources/js/editor/Inspector.test.tsx
  resources/js/editor/ConfigPanel.test.tsx resources/js/controls` passed 4 files / 50 tests;
  `npx tsc --noEmit` and `git diff --check` passed silently. The broader focused FlowEditor suite
  has exactly two known pre-existing stale expectations: server-graph fallback positions and old
  straight-quote matching for an unknown-card notice. Its 17 remaining tests, including all new
  inspector compatibility behavior, pass.
- Follow-up RED: a non-null `deleted-node` issue was rendered as a button solely because it had a
  node ID, so it could ask the controller to select a node that no longer exists. The stricter
  regression also pins the complete Issues-list DOM order and ArrowRight/ArrowLeft tab focus,
  selection, and labelled-panel behavior.
- Follow-up GREEN: `FlowOverviewIssue` now carries an explicit `placeable` boolean. Only a
  placeable issue with a non-null node invokes `onIssueSelect`; unresolved IDs remain plain visible
  text. The tab keyboard assertions confirm both directional keys alongside Home/End.
- Quality RED: mounting two inspectors for the same node/field made the second inspector's issue
  focus use the first matching document ID. The inactive tabpanel was also unmounted, leaving its
  tab's `aria-controls` target absent. The regressions use a field key containing `constructor` and
  a quote, then prove second-instance focus, both panel targets, their labels, and hidden-state
  swaps through keyboard navigation and selection reset.
- Quality GREEN: ConfigPanel gives each row an instance-unique ID and a React-escaped field-key
  data attribute. NodeInspector now searches those rows only within its own root and compares the
  dataset value rather than interpolating a field key in a selector. Both tabpanels remain mounted;
  native `hidden` removes the inactive one from the accessibility tree while retaining valid
  `aria-controls` targets and stable panel identity.
- Control-ID RED: although inspector row IDs were unique, two built-in Text controls still rendered
  the same `nf-<field key>` input ID. The second label therefore resolved to the first input. A
  shared standalone regression demonstrated the same collision across text, number, boolean,
  select, multiselect, and duration controls.
- Control-ID GREEN: a private `FieldControlId` context lets ConfigPanel provide one React
  `useId`-based ID per field row without changing the six-key `FieldControlProps` contract. FieldShell
  and every built-in consume that shared value; direct control rendering falls back to its own stable
  React ID. Custom host controls remain context-optional and receive their unchanged six props.

## Task 9 — toolbar, notices, and shell

- RED: `npx vitest run resources/js/editor/EditorChrome.test.tsx resources/js/editor/EditorShell.test.tsx` failed as expected because `CanvasHud`, `EditorToolbar`, and `EditorShell` did not yet exist. The focused contract was committed in `12b53e7` (`test: define Workflow Studio chrome`).
- GREEN: the same focused suite passed with 17 tests. `npx tsc --noEmit` and `git diff --check` passed after adding the pure toolbar/notices/HUD view components and one-DOM responsive shell. The shell owns only view-state widths (library 240–400 px, inspector 288–480 px); no controller or `FlowEditor` integration changed.
- Follow-up RED: stable multi-move drawer tests failed at 350 px after moves from x=100 to x=110 then x=120, proving the old drag path added each total delta to already-updated state. The required-callback type assertion also failed while the notice callbacks were optional. Recorded in `4cd4179`.
- Follow-up GREEN: pointer drags now retain a session with immutable start X/width and derive every move from it; both directions clamp and clean up on pointer completion. The HUD suite covers unchecked, checking, valid, warning, invalid, and failed labels. `onKeepMine` and `onUseTheirs` are required notice props. The focused suite passed 24 tests; TypeScript and diff checks passed.
- Pointer-identity RED/GREEN: a drag held by pointer 1 used to end when pointer 2 emitted `pointerup` or `pointercancel`; its subsequent movement stayed at 330 px instead of reaching 340 px. `610c79f` records the counterexample. Cleanup now verifies the matching pointer ID before releasing capture or removing listeners. The focused suite passed 25 tests, with TypeScript and diff checks clean.
- Shell-hardening RED: `1a9b6d9` adds regressions for the overlapping three-track desktop grid, `h-full` body sizing below natural chrome, unconditional drawer ARIA/focus/Escape behavior, competing narrow drawers, and a second pointer taking over an active drag. The focused run produced the expected five failures: no flex shell/five tracks, two dialogs for conflicting state, focus theft on desktop/narrow mismatch, and a second pointer driving the width to its clamp.
- Shell-hardening GREEN: the shell uses five non-overlapping desktop tracks and a `flex-1 min-h-0` body. An SSR-safe media-query hook gates non-modal dialogs, inert/aria-hidden closed drawers, focus and Escape behavior to narrow viewports; inspector deterministically wins a conflicting controlled state. Active pointer sessions reject non-primary, non-left, and competing pointer starts. The focused suite passed 30 tests; TypeScript and diff checks passed. React's current typings support the boolean `inert` attribute without a cast.

## Task 10 — controller integration

- RED: committed `1c04973` (`test: define Workflow Studio controller`).
  `npx vitest run resources/js/editor/useEditorController.test.tsx` failed at import time as
  intended because `./useEditorController` did not yet exist. The discriminator covers exact-point
  collision-safe insertion and first-start selection, valid connection handling and non-history
  selection, one-history-step node drag plus viewport conversion, and Validate's own request and
  stale-generation suppression.
- GREEN: `useEditorController` owns canonical `History<EditorDocument>` while selection, panels,
  validation, publishing, and autosave concurrency remain outside history. Every accepted graph
  edit (including undo/redo) advances a generation and invalidates validation; drag/configuration
  transactions coalesce through their closing event. It uses null-prototype definition lookup,
  cleans node-edge/start invariants, safely resets server-winner conflicts, preserves Keep-mine's
  accepted revision, lays out all nodes in one commit, and keeps Validate and Publish request IDs
  independent.
- FlowEditor is now the public session-remount wrapper around the controller and Workflow Studio
  shell. It supplies the field-options context; composes toolbar/notices/library/canvas+HUD/empty
  prompt/inspector-or-overview; scopes all keyboard actions away from editable targets; disables
  React Flow deletion so contextual and keyboard deletion share one controller action; and focuses
  the library search after opening. Canvas adds a narrow `deleteKeyCode` override solely for that
  controller boundary; NodeInspector closes configuration transactions on blur.
- Public surface: `FlowEditorProps` adds optional `mode` and `toolbarSlots`; `EditorMode`,
  `ToolbarSlots`, controller types, and `useEditorController` are exported from the package root
  with root compile assertions. The two known stale FlowEditor assertions now use topology's
  intentional `{72,88}`/`{480,88}` fallback placement and the intentional curly-quote unknown-type
  message; the retained counterfactual coverage uses the new composed controls and overview.
- GREEN verification: `npx vitest run resources/js/editor resources/js/canvas resources/js/graph
  resources/js/run --reporter=dot` passed **20 files / 223 tests**. `npx tsc --noEmit` and
  `git diff --check` passed silently.

### Task 10 review follow-up — controller boundaries

- RED: the new focused controller/inspector suite produced the three intended failures: a valid
  Validate response masked a later semantic Publish 422 in notices, overview, canvas badges, and
  inspector fields; a deferred successful Publish response incorrectly advanced version 3 to 4
  after a graph edit; and panel-level Configure blur closed a compound field transaction while
  focus moved to its sibling control. A mocked React Flow component also proved the old nullish
  Canvas merge forwarded `['Backspace', 'Delete']` rather than an explicit `null`; the compound
  FlowEditor regression left the first edit (`one`) after one undo under the old panel-blur path.
- GREEN: Canvas now defaults only when `deleteKeyCode === undefined`, preserving controller-owned
  `null` while still retaining the editable/default and run/read-only defaults. Publish outcomes
  use the latest non-success publish result for error surfaces; semantic banner and unplaceable
  errors reach notices/overview, known-node diagnostics reach canvas/inspector, and prior valid
  validation warnings remain visible. Issue focus uses that same selected source, so a server field
  message survives from overview selection into the inspector. Every publish outcome, including
  success/version state, is gated by its active request and captured generation; autosave still
  finishes its barrier even for a stale response. A graph commit clears stale publish outcomes.
- Configuration transaction closure now lives on each ConfigPanel field row. Its bubbling blur
  handler keeps the transaction open when `currentTarget.contains(relatedTarget)`, then closes it
  only when focus leaves the row. The six-prop host field-control contract is unchanged.
- Follow-up verification: `npx vitest run resources/js/editor resources/js/canvas
  resources/js/graph resources/js/run` passed **21 files / 230 tests**; `npx tsc --noEmit` and
  `git diff --check` passed silently.

### Task 10 quality follow-up — ownership, lifecycle, and viewport

- RED: controller regressions demonstrated that React Flow `select:false` did not clear controller
  selection and that a pending valid response could overwrite the explicit no-validate-URL failure.
  The new Canvas regressions pin wrapper-relative viewport centering and disposal identity; the
  library regression mounts two drawers and requires distinct label/input identities.
- GREEN: each FlowEditor session now claims a module-local shortcut owner on captured pointer or
  focus input; only the owner installs behavior for document shortcuts and unmount releases only
  its own claim. Editable suppression recognizes all enabled `contenteditable` forms and descendants
  in a disabled-shortcuts subtree. Controller node/edge selection changes are filtered before
  canonical graph application, including `select:false`, and canvas selection is derived for both
  node cards and edges.
- Validate increments its request token before checking the optional URL, while controller unmount
  invalidates both validate and publish sequences. Async state application requires mounted,
  request-owner, and generation checks. Canvas actions now include `viewportCenter`, derived from
  the actual wrapper rect before flow conversion, with SSR/JSDOM fallback; lifecycle disposal clears
  the controller ref only when it still identifies that same action object. NodeLibrary search IDs
  use `useId` rather than a document-global literal.
- Quality verification: `npx vitest run` passed **26 files / 292 tests**; the requested editor,
  canvas, graph, and run suite passed **21 files / 237 tests**. TypeScript and diff checks passed.

### Task 10 final quality follow-up — root scope and compatibility

- RED: the original singleton shortcut token permitted a claimed editor to react to document-level
  events without an editor-root focus boundary, and URL props did not invalidate in-flight requests
  until a new request started. A legacy CanvasActions object also no longer type-checked after
  viewport centering became mandatory.
- GREEN: shortcut activity is now a `WeakMap<Document, registry>` of root/token entries. Pointer
  and focus claim an editor, non-interactive canvas input focuses its programmatically focusable
  root, and document key handling requires both current ownership and a target/active-element
  containment check. Active-root unmount promotes the most recently active remaining editor only
  when focus had been inside the removed root. URL effects invalidate validate/publish tokens on
  any prop change before old responses can apply. `viewportCenter` is optional again for host
  compatibility; controller fallback uses the old screen midpoint conversion or pure layout when
  no canvas is registered.

### Task 10 final blocker follow-up — pre-removal focus and publish lease

- RED: passive shortcut-registration cleanup ran after an active root had already been detached, so
  `contains(document.activeElement)` was false and no fallback root received focus. A pending old
  publish endpoint also retained its session lease across an endpoint prop replacement.
- GREEN: registration/removal uses an isomorphic layout effect, preserving active-root focus long
  enough to promote and focus the fallback before DOM removal; SSR still selects `useEffect`.
  `sessionKey` now includes the publish URL, so an endpoint replacement remounts the complete
  controller/autosave session. Regressions cover focused active-editor unmount and old/new publish
  endpoint isolation.

### Task 10 final blocker follow-up — autosave-owned publish identity

- RED: directly rerendering `useAutosave` with the same draft URL but a new publish owner identity
  left the old publish barrier active, so the new `preparePublish()` returned false. The direct
  controller regression verifies a new publish endpoint can start after an old pending request and
  that the stale response cannot alter the new result.
- GREEN: `useAutosave` accepts optional `sessionIdentity` (stable empty default for existing
  callers) and folds it into its epoch comparison with the real draft URL. Either identity change
  now uses the existing authoritative reset path: settles old requests, clears publish lease and
  queue state, resets the supplied revision/state, and preserves PUT routing to the actual draft
  URL. The controller supplies its publish URL as that identity; FlowEditor's publish-url session
  key remains a defensive public remount boundary.

## Documentation and demo verification

## Reviews and final gates
