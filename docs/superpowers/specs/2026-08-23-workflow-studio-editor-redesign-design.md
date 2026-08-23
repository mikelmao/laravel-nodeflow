# Workflow Studio editor redesign

**Date:** 2026-08-23
**Status:** Draft for written review; implementation has not started

## 1. Goal

Replace Nodeflow's raw three-column graph editor with a polished, package-owned workflow studio that
is immediately usable in a host application without custom styling.

The new default must balance two audiences:

- operations and product users should be able to understand, assemble, validate and publish a flow
  without learning Nodeflow's internal identifiers; and
- developers should retain progressive access to node types, IDs, outputs, cardinality and other
  implementation details when diagnosing or extending a flow.

This is a presentation and authoring-workflow redesign, not a new graph model. Existing graph JSON,
stored positions, autosave concurrency, publishing rules, custom controls and custom node renderers
remain authoritative.

## 2. Verified starting state

The design session inspected the editor implementation, its tests, the graph contract and the live
flood-alert workflow in the demo application.

### Current implementation facts

- `FlowEditor.tsx` combines graph mutation, selection, autosave, publishing and almost the entire UI
  in one component.
- The editor renders a fixed `16rem / canvas / 20rem` grid with no responsive or embedded behavior.
- The header, status messages and buttons receive almost no visual treatment or hierarchy.
- The palette concatenates labels and technical types, making entries difficult to scan.
- The inspector's empty state is only “Select a node to configure it.”
- Node cards are fixed at 208 px, use 9–10 px text, and place output labels directly over connection
  handles. At realistic zoom levels, content and edge labels become unreadable.
- Initial placement is an index-based four-column grid, not a topology-aware workflow layout.
- `Canvas` already provides the correct shared boundary between the editable view and read-only run
  view, and `NodeCard` retains handles and mandatory errors even when a host renderer owns the body.
- Graph positions are presentation metadata preserved by draft and publish round-tripping; the
  runtime does not interpret them.
- Publishable graphs are acyclic, although incomplete or cyclic drafts may exist before validation.
- Tailwind scans the package's `resources/js` directory and the editor already uses host semantic
  theme tokens. No separate compiled package theme is currently required.
- No `almanac/` directory exists, so there was no CodeAlmanac context to consult.

### Why the live editor looked broken

The screenshot was not primarily a host-theme defect. It exposed package defaults that treated the
editor as a functional scaffold rather than a finished authoring environment:

1. weak hierarchy made flow metadata compete with primary actions;
2. permanently open sidebars reduced the canvas without adding useful context;
3. tiny node content and overlapping ports made the graph illegible;
4. grid placement obscured the actual sequence and branch structure;
5. technical names appeared before human labels; and
6. empty, error and save states lacked clear next actions.

The redesign therefore belongs in the package default rather than in the demo application's skin.

## 3. Experience principles

### 3.1 Workflow first

The graph is the primary surface. Chrome supports the canvas instead of squeezing it into the space
left over after sidebars.

### 3.2 Human language first, technical truth available

Cards and library items lead with definition labels, category treatment and one meaningful
configuration summary. IDs, registered types, cardinality and raw output contracts live in the
Advanced inspector. Unknown types and validation/runtime failures remain prominent because hiding
them would make the editor misleading.

### 3.3 Progressive disclosure

Common actions are visible. Infrequent details, destructive actions and developer metadata are
grouped in the inspector. A deselected graph shows useful flow-level information rather than an
instructional dead end.

### 3.4 Package quality by default

The package owns the complete toolbar, panels, graph cards, states and responsive behavior. A host
can inject navigation and adjacent actions, inherit its theme and opt into an embedded layout without
having to reconstruct the editor.

### 3.5 Safe, observable persistence

Saving, conflicts, validation and publishing are separate states. The interface must never imply
that “saved” means “publishable” or that a failed network request discarded local work.

## 4. Chosen direction: Workflow Studio refactor

Retain the graph adapters, React Flow canvas, autosave hook, publish semantics and extension
contracts. Refactor the presentation around explicit workspace components and a focused editor state
controller.

This provides the approved experience without making a headless editor architecture a prerequisite.
The component seams should nevertheless allow a future headless controller or alternative shell.

### Rejected alternatives

#### Demo-only styling

This would make the showcase attractive while leaving every package consumer with the current raw
editor. It also duplicates package behavior in the host and makes documentation screenshots
unrepresentative.

#### Styling the existing monolith in place

It would improve color and spacing quickly, but responsive drawers, undo history, drag-to-add,
validation focus and panel resizing would further entangle graph state with view state.

#### Complete headless editor rewrite first

A headless state machine is a valuable future capability, but requiring it now expands scope and
delays the visible usability improvement. Clean controller/component boundaries provide most of the
future migration seam at lower risk.

## 5. Workspace anatomy

### 5.1 Application toolbar

The top toolbar remains visible across the workspace and contains:

- an optional host-provided leading slot for back navigation or breadcrumbs;
- flow name, trigger label and published-version context;
- undo and redo;
- Auto layout and Fit view;
- a compact save-state indicator: Saving, Saved, Save failed or Conflict;
- Validate with the last validation result; and
- the primary Publish action.

Host content is supplementary. It cannot replace the package's save, validation or publish controls.
An optional trailing slot supports a host help link or adjacent domain action.

On narrow screens, secondary toolbar actions collapse behind a labelled overflow menu while save
state, Validate and Publish remain directly reachable.

### 5.2 Node Library

The left panel becomes a searchable Node Library:

- node definitions are grouped by human category;
- each item shows an icon, label and concise description;
- technical type appears only in a secondary detail or accessible tooltip;
- typing filters labels, descriptions, groups and registered types;
- clicking adds a node to the center of the current viewport and selects it; and
- dragging places a node at the drop position on desktop pointer devices.

Click-to-add is the keyboard, touch and universal fallback. Drag-and-drop is an enhancement, not the
only way to create a node.

The panel is collapsible and resizable on wide screens. At smaller breakpoints it becomes an overlay
drawer so the canvas retains usable width.

### 5.3 Canvas

The canvas is one tonal step deeper than surrounding panels and carries the strongest visual weight.
It includes:

- a subtle dot grid;
- a compact canvas HUD with node/connection counts and current readiness state;
- topology-aware left-to-right placement;
- readable category-accented node cards;
- labelled output paths with labels clear of ports;
- zoom controls, fit view and a minimap;
- an empty-flow prompt that opens the Node Library; and
- visible focus and selection states.

Manual positions are preserved. Auto layout runs automatically only when positions are absent and is
also available as an explicit toolbar action. It never silently rearranges an already-positioned
workflow.

### 5.4 Inspector

The right panel has two node tabs:

- **Configure** contains the definition-backed fields, help text, field validation and start-node
  action.
- **Advanced** contains node ID, registered type, group, cardinality and declared outputs, followed by
  the destructive delete action.

When no node is selected, the panel becomes a Flow Overview showing:

- trigger and published version;
- nodes and connections;
- current start node;
- unresolved connections and unknown node types;
- last validation result and graph-level messages; and
- a short publish-readiness summary.

The panel is collapsible and resizable on wide screens and an overlay drawer at smaller breakpoints.
Selecting a node opens it automatically when the drawer form is active.

## 6. Node-card visual contract

The package wrapper continues to own all ports, mandatory errors and run decorations. Default cards
change to a wider, calmer structure:

1. category icon and category accent;
2. human node label;
3. one meaningful configuration summary;
4. compact start, invalid, warning and runtime badges when applicable; and
5. explicit output port rows aligned to the right edge.

The default summary selects the first non-empty configured field in definition order and uses its
human field label. Values receive compact formatting for booleans, arrays and long text. If no value
is configured, the card shows either “Needs configuration” when a required value is absent or the
definition description as subdued supporting text.

Node IDs, registered types and category names do not occupy the default card body. Unknown node types
remain a loud error state with their raw type because no human definition exists.

Category accents use a fixed, contrast-tested palette assigned deterministically by group. The
meaning is also conveyed by icon and text; color is never the only distinction. A definition's
existing `icon` remains preferred. An internal category icon is the fallback, so hosts do not need an
icon library.

Host `NodeRendererMap` entries still own only the card body. The redesigned wrapper, ports, errors,
selection and run badges remain mandatory. The same wrapper remains usable in `FlowRun` so authors
and operators see consistent graph language.

## 7. Interaction model

### 7.1 Selection and configuration

- Clicking a node selects it and opens Configure.
- Clicking empty canvas clears selection and returns the inspector to Flow Overview.
- Adding a node selects it immediately.
- Validation results can focus and select the affected node; field errors open Configure.
- Deleting a selected node clears selection, removes connected edges and clears the start when
  necessary, matching current graph semantics.

### 7.2 Connections

Connections retain exact registered output names as source-handle IDs. A source output may still
have at most one edge. Invalid or unresolved draft connections remain saveable where the existing
draft contract permits them, but are visibly incomplete and block validation/publishing.

Edge labels render as compact chips positioned on the path rather than on top of the card's handle.
The default left-to-right graph uses smooth stepped paths to make branches easier to trace.

### 7.3 Undo and redo

Undo history covers graph mutations only:

- add/delete node;
- connect/delete/reconnect edge;
- move node;
- change configuration;
- change start node; and
- apply auto layout.

Selection, panel size, search, zoom and viewport changes are ephemeral UI state and do not enter
history or autosave.

Continuous drag changes are coalesced into one history entry. Consecutive edits to the same field are
coalesced while the field remains the active edit transaction, including host controls that emit
multiple changes. A new graph action closes the transaction. Undoing or redoing creates a normal
graph change and therefore autosaves.

### 7.4 Keyboard behavior

The first release supports:

| Action | Shortcut |
|---|---|
| Undo | `Cmd/Ctrl+Z` |
| Redo | `Cmd/Ctrl+Shift+Z` |
| Delete selected node/edge | `Delete` or `Backspace` |
| Focus Node Library search | `Cmd/Ctrl+K` |
| Auto layout | `Shift+L` |
| Fit graph | `F` |

Canvas shortcuts do not fire while the user is typing in an input, textarea, select, editable region
or host field control. Every shortcut has a visible button and accessible name.

## 8. Layout engine

Implement a deterministic package-owned hierarchical layout rather than adding a peer dependency.
This avoids making every host install and align another JavaScript package.

### 8.1 Algorithm

1. Normalize known nodes and known-node edges in stable graph order.
2. Identify strongly connected components so cyclic drafts cannot crash the layout.
3. Condense components into a DAG.
4. Assign left-to-right layers using longest distance from a root component.
5. Apply stable barycentric ordering inside layers to reduce crossings while retaining graph order as
   the tie-breaker.
6. Place disconnected components below the primary start component with consistent gaps.
7. Place members of a cyclic component vertically in the same layer and leave cycle validation
   visible.

The layout is pure and deterministic: equal graph input and dimensions produce equal positions.

### 8.2 Position policy

- A graph with no stored positions receives the suggested topology layout on first hydration.
- A graph with stored positions retains all of them.
- In a partially positioned graph, stored nodes remain fixed; missing nodes use suggested slots and
  are nudged deterministically to the nearest non-overlapping slot.
- Click-to-add uses the viewport center with collision avoidance.
- Drag-to-add uses the pointer's flow coordinate with collision avoidance.
- Auto layout explicitly repositions every node and is one undoable operation.

Layout geometry is centralized so card width, port placement, collision detection and tests do not
drift.

## 9. Component architecture

`FlowEditor` remains the public session boundary and remount key. Internally it becomes a composition
of focused components:

```text
FlowEditor
└── FlowEditorSession
    ├── useEditorController
    │   ├── graph/history reducer
    │   ├── autosave orchestration
    │   ├── validation orchestration
    │   └── publish orchestration
    └── EditorShell
        ├── EditorToolbar
        ├── NodeLibrary
        ├── WorkflowCanvas
        │   └── NodeCard
        ├── Inspector
        │   ├── FlowOverview
        │   └── NodeInspector
        └── EditorNotices
```

### 9.1 State boundaries

The controller separates three kinds of state:

- **document state:** nodes, edges and start ID, with past/future history;
- **server state:** draft revision, save/conflict state, validation outcome, publish outcome and
  published version; and
- **view state:** selection, panel visibility/size, active inspector tab, library query and viewport.

Only document state is converted through `toGraph` and sent to autosave. Decorations, definitions,
errors, selection and view state remain contextual and never look like graph edits.

Existing generation and request-sequence guards remain: stale publish or validation responses cannot
replace feedback for a newer graph. Conflict resolution still provides Keep mine and Use theirs, and
using the server graph resets history because it establishes a new authoritative document baseline.

### 9.2 Canvas boundary

`Canvas` remains state-free and shared with the run view. It gains controlled hooks for viewport
actions, canvas clicks, drag/drop coordinates and minimap visibility without learning autosave,
publishing or run vocabulary.

## 10. Validation and publishing

### 10.1 Meaningful Validate action

Add a tenant-authorized, non-mutating validation endpoint that accepts a graph and returns the same
semantic error vocabulary as publishing without creating a flow version or clearing the draft.

`EditorUrls` gains an optional `validate` URL. The package controller always supplies it. Keeping the
field optional preserves manually constructed editor props from older hosts; without it, Validate
performs the client-known structural readiness checks and explains that full server validation occurs
on Publish.

The server endpoint reuses the authoritative graph validator and node validation path rather than
reimplementing PHP rules in TypeScript. Its response contract is:

```json
{
    "valid": true,
    "warnings": []
}
```

or, with HTTP `422`:

```json
{
    "valid": false,
    "message": "The flow is not ready to publish.",
    "errors": ["Human-readable graph or node error"],
    "node_errors": [{ "node": "node-id", "field": "field-key", "message": "Field message" }],
    "warnings": []
}
```

`node` and `field` retain their existing nullable semantics for graph-wide and node-wide errors.
Warnings are returned on both success and failure so the UI does not conceal a valid warning merely
because the same graph also has an error.

Validation does not require an autosave first and does not change the draft revision. A document edit
marks the previous result stale. A late response for an older graph is ignored.

### 10.2 Feedback placement

- toolbar: aggregate valid, warning, invalid or unchecked status;
- Flow Overview: graph-wide messages and ordered issue list;
- node cards: issue badge without dumping full messages into the card;
- Configure: field-specific errors beside the correct control; and
- unknown/unplaceable errors: persistent overview notice.

Selecting an issue focuses the node on canvas and opens the relevant inspector context.

### 10.3 Publish behavior

Publishing preserves the current safety sequence:

1. refuse unresolved connections locally;
2. wait for an accepted draft save;
3. publish the exact current graph;
4. adopt returned version and draft revision on success; and
5. retain the local graph and actionable feedback on failure.

Validate is advisory convenience; Publish still performs authoritative validation to prevent a
time-of-check/time-of-use gap.

## 11. Save, conflict and error states

Save state is compact in the toolbar but expands into an actionable notice when intervention is
needed:

- **Saving:** spinner and “Saving changes”.
- **Saved:** checkmark and latest stable state.
- **Save failed:** persistent alert explaining that autosave is halted and local changes remain
  visible.
- **Conflict:** persistent conflict surface with server revision, Keep mine and Use theirs.

Structural `422` responses remain labelled as client/payload defects rather than ordinary author
validation. Session expiry names the reload requirement. Network and server errors never clear the
canvas.

Notices are announced through appropriate live regions without repeatedly announcing routine Saved
states on every keystroke.

## 12. Responsive and embedded behavior

Add an optional `mode` prop:

```ts
mode?: 'workspace' | 'embedded'
```

`workspace` is the default and occupies the viewport as a self-contained application surface.
`embedded` uses a bounded minimum height and participates in the host page's normal document flow.
The existing `className` remains the final host sizing/placement escape hatch.

Responsive behavior:

- **wide:** persistent resizable library, canvas and inspector;
- **medium:** canvas remains full width while one side panel can be open as a drawer;
- **small:** library and inspector are labelled overlay sheets, toolbar actions condense, and the
  canvas retains touch-capable pan/zoom.

Panel widths are view preferences only. They are not stored in the graph or sent to the server.
Resize handles use separator semantics, keyboard adjustment and sensible min/max widths.

## 13. Theme and host integration

The default uses the host's existing semantic Tailwind tokens: `background`, `foreground`, `card`,
`muted`, `border`, `primary`, `destructive` and their foreground variants. The canvas uses a muted
surface so it is one tonal step deeper in both light and dark themes.

All Tailwind class strings remain statically discoverable under the package `resources/js` source
already configured by `nodeflow:install`. Category accent variants are a finite literal palette, so
no dynamic Tailwind class generation or new CSS installation step is introduced.

Add optional host slots without surrendering package-owned workflow controls:

```ts
toolbarSlots?: {
    leading?: ReactNode
    trailing?: ReactNode
}
```

The existing `controls`, `nodeRenderers`, `autosaveDebounceMs` and `className` contracts remain. The
new `mode`, `toolbarSlots` and optional `urls.validate` fields are additive.

## 14. Accessibility

- Panels, toolbar groups, notices and inspector tabs use appropriate landmarks and labels.
- Node Library search has an explicit label and result count.
- Library items are real buttons for click-to-add; desktop dragging does not remove their keyboard
  behavior.
- Icon-only toolbar controls have names and tooltips.
- Selection, validity and categories do not rely on color alone.
- Text and interactive states meet contrast expectations in host light and dark themes.
- Focus remains visible on cards, ports, fields, resize handles and toolbar controls.
- Opening a drawer moves focus into it; closing returns focus to its trigger.
- Validation issue navigation moves focus predictably without trapping it in the canvas.
- Reduced-motion preferences disable nonessential panel and viewport animation.

## 15. Compatibility and migration

### Preserved behavior

- graph wire format and semantic round-tripping;
- stored position meaning;
- server-authored URLs;
- autosave debounce and revision conflict boundary;
- publish sequencing and error categories;
- custom field controls and dynamic option loading;
- custom node renderer body ownership;
- mandatory card handles/errors/decorations; and
- shared editor/run canvas primitives.

### Additive behavior

- optional validation URL and endpoint;
- optional workspace/embedded mode;
- optional toolbar slots;
- undo/redo history;
- deterministic topology layout;
- library search, drag/drop and click placement;
- responsive panels and richer flow overview; and
- minimap and validation navigation.

No host should need to install an icon, drag/drop or graph-layout dependency. `@xyflow/react`, React
and React DOM remain the only frontend peer dependencies.

## 16. Testing strategy

### Pure unit tests

- topology layers, disconnected components, crossing order and cyclic draft handling;
- stored/missing position policy and collision avoidance;
- deterministic category accents and card summaries;
- reducer history, transaction coalescing and history reset;
- library filtering and next-node placement;
- local readiness checks and validation-result mapping; and
- shortcut suppression inside editable controls.

### Component tests

- package toolbar actions and status states;
- click-to-add and drop-to-add coordinates;
- selection, deselection and inspector transitions;
- Configure/Advanced disclosure and Flow Overview;
- validation issue focus and field error placement;
- panel drawers, resizing and keyboard controls;
- accessible names, landmarks and focus return;
- existing custom controls and renderers inside the new shell; and
- read-only run canvas behavior after the card redesign.

### HTTP and integration tests

- validation route authentication, authorization and tenant isolation;
- validation success, warnings and structured semantic failures;
- validation performs no draft/version mutation;
- stale validation responses do not replace newer feedback;
- autosave conflict, publish barrier and revision behavior remain unchanged; and
- installer/frontend checks remain valid without a new dependency or stylesheet step.

### Representative host verification

Build and exercise the editor through the local Laravel demo in both light and dark themes:

- wide workspace and embedded mode;
- small responsive viewport;
- add, connect, configure, undo, redo and auto layout;
- save/conflict/validation/publish states;
- flood-alert graph readability and inspector content; and
- run-view card rendering.

The package's Vitest/TypeScript/PHP/Pint/PHPStan suites and the demo's frontend/full test gates must
pass before completion.

## 17. Release boundary

### Included in the first redesign release

- full package-owned workspace shell and responsive panels;
- toolbar, save state, Validate and Publish;
- searchable grouped library;
- click-to-add and desktop drag-to-add;
- redesigned cards, ports, paths and graph states;
- node Configure/Advanced inspector and Flow Overview;
- undo/redo;
- topology-aware initial and explicit auto layout;
- fit, zoom and minimap;
- validation issue navigation;
- host theme inheritance, embedded mode and toolbar slots; and
- documentation for the new default and integration props.

### Deferred

- multi-select and bulk actions;
- copy/paste and node duplication;
- subflow drill-in navigation;
- collaborative presence or cursors;
- a general command palette beyond node search;
- persisted per-user panel preferences;
- host-supplied layout engines; and
- a separately consumable headless editor controller.

These are valuable follow-ups but are not required to make the default editor showcase-ready.

## 18. Acceptance criteria

The redesign is complete when:

1. the flood-alert workflow reads clearly left to right at normal zoom without overlapping labels;
2. a new user can find, add, connect, configure, validate and publish nodes from visible controls;
3. technical metadata is available in Advanced without dominating the normal workflow;
4. deselecting a node yields useful flow-level readiness information;
5. manually stored positions survive reload and explicit Auto layout is undoable;
6. save failure, conflict, invalid flow and publish success are visually distinct and actionable;
7. the editor works as a full-height workspace and in embedded/responsive layouts;
8. light and dark themes are legible using host semantic tokens;
9. existing custom controls, node renderers, graph serialization, autosave and publishing tests remain
   green;
10. validation is tenant-authorized, non-mutating and uses the same semantic rules as publishing;
11. no new frontend peer dependency or host CSS installation step is required; and
12. the representative demo passes automated verification and a real browser workflow smoke test.
