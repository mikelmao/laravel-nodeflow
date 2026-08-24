# Editor

Render a tenant-authorized Workflow Studio with server-authored URLs, debounced draft saving, validation feedback, and publish errors that remain visible where authors need them.

Start by mounting the package route and thin page adapter from [Routes and Inertia](../integration/routes-and-inertia.md). The editor component has no Inertia dependency; it receives plain props from the host adapter.

## Use the editor props

`FlowEditor` accepts this contract:

```tsx
import type {
    ControlMap,
    EditorUrls,
    FlowSummary,
    Graph,
    NodeRendererMap,
    NodeTypePayload,
    ToolbarSlots,
    TriggerNodeTypePayload,
    TriggerSourcesPayload,
    WebhookMetadata,
} from '@nodeflow/editor'

export type FlowEditorProps = {
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    trigger_nodes: TriggerNodeTypePayload[]
    trigger_sources: TriggerSourcesPayload
    webhook: WebhookMetadata | null
    urls: EditorUrls
    controls?: ControlMap
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
    mode?: 'workspace' | 'embedded'
    toolbarSlots?: ToolbarSlots
    className?: string
}
```

The `edit` controller supplies all required properties. By default, `FlowEditor` is a full-height workspace (`workspace` mode): it owns a responsive Node Library, canvas, inspector, toolbar, notices, and minimap. Do not wrap it in host `min-h`, padding, or grid classes that fight that workspace.

A complete minimal page can add host navigation through a toolbar slot while the package retains every editor action:

```tsx
import { Link } from '@inertiajs/react'
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} toolbarSlots={{ leading:<Link href="/admin/flows">All flows</Link> }} />
}
```

`toolbarSlots.leading` appears before package controls and `toolbarSlots.trailing` after them. Use them for supplementary host navigation or status, not as replacements for Save, Validate, or Publish:

```tsx
<FlowEditor
    {...props}
    toolbarSlots={{
        leading: <Link href="/admin/flows">All flows</Link>,
        trailing: <span>Internal automation</span>,
    }}
/>
```

For a page, dialog, or panel that provides its own constrained frame, choose the responsive embedded shell explicitly:

```tsx
<FlowEditor {...props} mode="embedded" />
```

The editor inherits the host's semantic theme tokens in either mode. It requires no new dependency and no CSS installation step.

`urls.draft`, `urls.validate`, `urls.publish`, and `urls.options` are server-owned, as are `rotate_webhook_secret`, `trigger_options`, and `trigger_source_options`. Option URLs are templates containing encoded type/source/field sentinels. Never reconstruct these URLs from a flow ID or route string in the browser—your host's prefix, domain, and route-name configuration have already been resolved on the server.

The package's HTTP helper sends same-origin requests with JSON, `Accept: application/json`, and `X-Requested-With: XMLHttpRequest`. It reads Laravel's decoded `XSRF-TOKEN` cookie first and falls back to a `meta[name="csrf-token"]` tag. Keep normal Laravel session/CSRF middleware on the containing route group; a 419 from a draft save stops autosave and tells the author to reload. The later publish-results table describes the separate behavior of a publish `POST` 419.

## Understand the initial draft

The controller selects the graph in this order:

1. `draft_graph` when one exists, because it is the author's in-progress work.
2. The current published version's graph when there is no draft.
3. `{ start: '', nodes: [], edges: [] }` when neither exists.

The flow's `version` reports the current published version even while the draft wins. Draft payload validation is structural only: an incomplete connection or unregistered node can still be saved, while a malformed node or edge receives Laravel validation errors.

## Build and navigate a graph

Use the **Node Library** to search executable labels, groups, descriptions, and type names. Its **Trigger Library** lists the server-authored trigger palette separately. A graph can contain only one trigger: choosing another asks for confirmation and replaces the existing trigger while preserving the one-trigger invariant. A trigger is disabled with “No compatible trigger source is registered” until the host registers an allowlisted source for its driver.

Click an executable result to add it at a sensible open canvas position, or drag it onto the canvas to place it at the drop point, nudged only when needed to avoid overlap. Select a card to configure fields in **Configure**; source fields are merged into the trigger's flat config after `source` is selected. With no selection, the inspector shows **Flow Overview**, trigger readiness, and webhook details when applicable.

The package toolbar and canvas expose the same actions for pointer and keyboard users:

| Action | Control and shortcut |
| --- | --- |
| Undo / Redo | Toolbar buttons; `Cmd/Ctrl+Z` and `Cmd/Ctrl+Shift+Z`. |
| Delete selection | Toolbar button; `Delete` / `Backspace`. |
| Focus Node Library search | `Cmd/Ctrl+K`. |
| Auto layout | **Auto layout** arranges every node; it is an ordinary graph edit and can be undone. |
| Auto layout shortcut | `Shift+L`. |
| Frame the graph | **Fit** frames all nodes; `F` frames the graph. Use canvas zoom controls or gestures for closer inspection. |
| Orientation | The minimap shows the current viewport and supports pan/zoom navigation. |

Shortcuts are suppressed while typing in inputs, textareas, selects, contenteditable elements, or host controls. On narrow viewports, the Node Library and inspector become drawers; `Escape` closes an open drawer and returns focus to its trigger.

## Save, validate, and publish safely

`useAutosave` waits **1000 ms** by default after the last graph change. Pass `autosaveDebounceMs` only when a different debounce is appropriate for the host page.

Each save sends the integer `draft_revision`; do not substitute `draft_updated_at`. The server increments the revision for accepted saves, returns it, and the client sends that returned value on the next save. The value is monotonic and publishing does not reset it.

If a save receives HTTP 409, autosave stops rather than overwriting a newer draft. The response carries the newer `graph` and `draft_revision`. The editor shows a conflict choice: **Keep mine** adopts the server revision and saves the local graph over it; **Use theirs** mounts the returned graph and saves nothing. Resolving that conflict is the explicit autosave restart path.

After any other refused save, including HTTP 419, or a network failure, the hook remains halted for that mounted editor session and offers no retry control. The local graph stays visible, but preserve the changes elsewhere before reloading. A reload or remount with fresh server props, or changing to another flow context, is required before autosave can run again.

Saving, validation, and publishing are distinct operations:

- Autosave persists the current draft and advances `draft_revision`; incomplete-but-structural drafts remain valid saves.
- **Validate** posts the current graph to `urls.validate` and reports the authoritative readiness result. Validate does not save a draft, create a version, or publish.
- **Publish** first waits for its required draft save, then creates the next version only if its own authoritative validation passes. Publish always validates again, even after a successful Validate result.

Validation succeeds with `{"valid":true,"warnings":[]}` (warnings may be non-empty). Semantic validation returns HTTP 422 with `valid: false`, `message: "The flow is not ready to publish."`, `errors`, `node_errors`, and `warnings`; structural request failures use Laravel's normal validation-error response. The endpoint uses the same `publish` authorization as Publish and is non-mutating.

Publishing first waits for an accepted draft save. If that prerequisite draft `PUT` conflicts or fails, no publish `POST` is made and the autosave hook remains halted; resolve the conflict or preserve the visible changes and reload/remount before trying again. Once the prerequisite draft save succeeds, an ordinary failed publish `POST` releases the publish barrier without changing the draft revision, so later graph changes can autosave.

The publish results are intentionally separate:

| Result | Meaning in the editor |
| --- | --- |
| Success | Shows the new version and adopts the returned `draft_revision`. |
| HTTP 422 without `node_errors` | Structural request validation; rendered as a developer-facing client/payload problem. |
| HTTP 422 with `node_errors` | Semantic graph validation; `errors` are banner messages and each `{ node, field, message }` entry is attached to its node when possible. Graph-level or unknown-node entries remain in the banner. |
| HTTP 419 | Shows session-expired publish guidance. The failed `POST` releases the barrier; later graph changes can autosave. |
| Other HTTP status or network failure | Shows a publish-request failure without discarding the local graph. The failed `POST` releases the barrier; later graph changes can autosave. |

> **Note:** A valid draft is not necessarily publishable. Publishing is the point at which graph meaning, node types, and field values are validated.

## Handle webhook credentials

Publishing a webhook flow for the first time can return an endpoint and one-time signing secret. The editor keeps that plaintext only in component state, offers copy controls, and requires explicit acknowledgement before hiding it. It is not present when the editor is reloaded and cannot be recovered; rotate it if it was not stored safely.

Rotation uses `urls.rotate_webhook_secret`, asks for confirmation, and replaces the secret immediately. The old secret stops authenticating as soon as rotation succeeds. The page displays endpoint availability, activation status, and the last rotation timestamp, but server props never contain the encrypted or plaintext stored secret.

## Next step

Add domain-specific field widgets in [Custom controls](custom-controls.md), or theme card bodies in [Custom node appearance](custom-node-appearance.md).
