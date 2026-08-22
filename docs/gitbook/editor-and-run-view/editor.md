# Editor

Render a tenant-authorized flow editor with server-authored URLs, debounced draft saving, and publish errors that remain visible where authors need them.

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
    TriggerPayload,
} from '@nodeflow/editor'

export type FlowEditorProps = {
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    triggers: TriggerPayload[]
    urls: EditorUrls
    controls?: ControlMap
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
    className?: string
}
```

The `edit` controller supplies all required properties. A complete minimal page is:

```tsx
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} />
}
```

`urls.draft`, `urls.publish`, and `urls.options` are server-owned. The options URL is a template containing `__NODEFLOW_TYPE__` and `__NODEFLOW_FIELD__`; the package replaces those sentinels with encoded values. Never reconstruct these URLs from a flow ID or a route string in the browser—your host's prefix, domain, and route-name configuration have already been resolved on the server.

The package's HTTP helper sends same-origin requests with JSON, `Accept: application/json`, and `X-Requested-With: XMLHttpRequest`. It reads Laravel's decoded `XSRF-TOKEN` cookie first and falls back to a `meta[name="csrf-token"]` tag. Keep normal Laravel session/CSRF middleware on the containing route group; a 419 stops autosave and tells the author to reload.

## Understand the initial draft

The controller selects the graph in this order:

1. `draft_graph` when one exists, because it is the author's in-progress work.
2. The current published version's graph when there is no draft.
3. `{ start: '', nodes: [], edges: [] }` when neither exists.

The flow's `version` reports the current published version even while the draft wins. Draft payload validation is structural only: an incomplete connection or unregistered node can still be saved, while a malformed node or edge receives Laravel validation errors.

## Save and publish safely

`useAutosave` waits **1000 ms** by default after the last graph change. Pass `autosaveDebounceMs` only when a different debounce is appropriate for the host page.

Each save sends the integer `draft_revision`; do not substitute `draft_updated_at`. The server increments the revision for accepted saves, returns it, and the client sends that returned value on the next save. The value is monotonic and publishing does not reset it.

If a save receives HTTP 409, autosave stops rather than overwriting a newer draft. The response carries the newer `graph` and `draft_revision`. The editor shows a conflict choice: **Keep mine** adopts the server revision and saves the local graph over it; **Use theirs** mounts the returned graph and saves nothing. Other refused saves and network failures leave the graph on screen but stop saving until the author changes context or resolves the problem.

Publishing first waits for an accepted draft save. Its outcomes are intentionally separate:

| Result | Meaning in the editor |
| --- | --- |
| Success | Shows the new version and adopts the returned `draft_revision`. |
| HTTP 422 without `node_errors` | Structural request validation; rendered as a developer-facing client/payload problem. |
| HTTP 422 with `node_errors` | Semantic graph validation; `errors` are banner messages and each `{ node, field, message }` entry is attached to its node when possible. Graph-level or unknown-node entries remain in the banner. |
| HTTP 419 | Shows session-expired guidance. |
| Other HTTP status or network failure | Shows a request failure without discarding the local graph. |

> **Note:** A valid draft is not necessarily publishable. Publishing is the point at which graph meaning, node types, and field values are validated.

## Next step

Add domain-specific field widgets in [Custom controls](custom-controls.md), or theme card bodies in [Custom node appearance](custom-node-appearance.md).
