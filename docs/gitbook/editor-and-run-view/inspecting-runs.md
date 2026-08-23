# Inspecting runs

Render the immutable graph a run actually executed, then let the package poll live counts and page through subjects that are active at a selected node.

Begin with the route and thin Inertia adapter in [Routes and Inertia](../integration/routes-and-inertia.md). `FlowRun` has no editor imports or save path, so the read-only boundary is structural rather than a convention.

## Use the run props

`FlowRun` accepts this exact contract:

```tsx
import type {
    Graph,
    NodeRendererMap,
    NodeTypePayload,
    OverlaySnapshot,
    RunSummary,
    RunUrls,
} from '@nodeflow/editor'

export type FlowRunProps = {
    run: RunSummary
    graph: Graph
    palette: NodeTypePayload[]
    overlay: OverlaySnapshot
    urls: RunUrls
    nodeRenderers?: NodeRendererMap
    pollIntervalMs?: number
    className?: string
}
```

The controller takes `graph` from the run's own pinned flow version, not from the flow's current version or its draft. A flow can change after a run starts; the run page continues to describe the frozen version that executed. It has no autosave, publish, or graph-write endpoint.

```tsx
import { FlowRun, type FlowRunProps } from '@nodeflow/editor'

export default function Run(props: FlowRunProps) {
    return <FlowRun {...props} />
}
```

Put this page behind a host error boundary. At mount time, `FlowRun` throws when the initial overlay is not an object with a boolean `terminal` and an object `nodes`; that top-level contract failure is intentionally not rendered as false run data. A malformed `status` or per-node field does not throw: the package normalizes it to an empty string or safe `false`/zero/`null` values, respectively.

## Read overlay badges

The overlay shape is:

```tsx
export type NodeOverlay = {
    reached: boolean
    byOutput: Record<string, number>
    waiting: number
    failed: number
    error: string | null
}

export type OverlaySnapshot = {
    status: string
    terminal: boolean
    nodes: Record<string, NodeOverlay>
}
```

For every node in the graph, a never-reached node is dimmed and shows no badge. A reached node is not inferred from a count: it always shows at least one badge. Each key in `byOutput` renders its named output badge even at zero (for example, `unmatched 0`); the fallback `subjects 0` badge appears only when `byOutput` has no entries and both `waiting` and `failed` are zero. This keeps “reached but released nobody” separate from “never reached.”

`byOutput` produces one badge per output name. Positive `waiting` produces a `waiting` badge, and positive `failed` produces an `errors` badge. An `error` string becomes a mandatory node error on the card. Counts describe the snapshot; they are not a complete subject history.

## Inspect active subjects

Selecting a node opens the subjects panel. It requests the server-authored `urls.subjects` template after replacing `__NODEFLOW_NODE__` with the encoded node ID. The response is exactly:

```json
{
  "node": "wait-for-review",
  "data": [
    {
      "id": 42,
      "subject_type": "user",
      "subject_id": "18",
      "status": "active",
      "current_node_id": "wait-for-review",
      "last_error": null,
      "exited_at": null
    }
  ],
  "next_cursor": "..."
}
```

The first request has no cursor. When `next_cursor` is a string, the next request appends `?cursor=` with that value URL-encoded; `null` ends pagination. The endpoint lists only active subjects whose `current_node_id` is the selected node. It does not provide a historical list of everyone who passed through or failed there.

An empty panel uses the overlay's `reached` flag to distinguish “no subjects are here now; this node was reached earlier” from “this run has no record of a subject being here.” The latter is not proof that no subject ever passed through—some nodes can leave no row-level record.

## Poll live overlays

`useOverlayPolling` uses a **5000 ms** default interval. It does not poll a terminal initial snapshot, stops when a successful response becomes terminal, avoids overlapping requests, and cleans up when the view unmounts.

HTTP statuses resolve as responses so the view can render errors. Polling halts for **401, 403, 404, and 419**, because the run is unavailable, no longer visible, or the session has expired. Other HTTP failures remain visible and polling continues. Network failures also remain visible but are retried on the next interval; a later successful snapshot clears the error. A successful HTTP response with a malformed top-level overlay records a visible contract error and polling continues; it does not halt or reach the host error boundary.

> **Note:** The client trusts the server's `terminal` boolean instead of hard-coding a list of terminal run statuses.

## Next step

Provide host-specific card bodies with [Custom node appearance](custom-node-appearance.md), or return to [Routes and Inertia](../integration/routes-and-inertia.md) to review the authorization boundary.
