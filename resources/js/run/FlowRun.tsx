import { useMemo, useState } from 'react'
import { Canvas, type NodeflowEdge, type NodeflowNode } from '../canvas/Canvas'
import type { NodeRendererMap } from '../canvas/context'
import { toCanvas } from '../graph/toCanvas'
import { defsByType } from '../graph/toGraph'
import type { Graph, NodeTypePayload, OverlaySnapshot, RunSummary, RunUrls } from '../graph/types'
import { decorationsFor, normalizeOverlay, overlayFor } from './overlay'
import { SubjectPanel } from './SubjectPanel'
import { useOverlayPolling } from './useOverlayPolling'

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

/**
 * A run's frozen graph with live counts painted on it.
 *
 * Reads the graph it is handed, which the server took from the run's own pinned
 * flow_version — never a draft, never the flow's newest version. There is no
 * write path here at all: no autosave, no dirty state, no publish. That is
 * structural rather than disciplined, because this module imports nothing from
 * editor/ and resources/js/run/boundary.test.ts fails if it ever does.
 */
export function FlowRun(props: FlowRunProps) {
    // Authoritative server identity remounts every session-local piece of state
    // together — the polling loop and the open drill-down — so navigating from
    // one run to another cannot leave the previous run's panel on screen.
    return <FlowRunSession key={JSON.stringify([props.run.id, props.urls.overlay])} {...props} />
}

function FlowRunSession({
    run,
    graph,
    palette,
    overlay,
    urls,
    nodeRenderers,
    pollIntervalMs,
    className = 'flex h-full min-h-[32rem] w-full',
}: FlowRunProps) {
    // Typed for the host and still validated at runtime: the type describes the
    // contract a host codes against, while normalizeOverlay defends against the
    // server actually changing shape — which no type can catch — and re-keys the
    // map so a node id of `__proto__` cannot resolve through the prototype.
    const initial = useMemo(() => normalizeOverlay(overlay), [overlay])
    const { snapshot, error } = useOverlayPolling(urls.overlay, initial, pollIntervalMs)

    const canvas = useMemo(() => toCanvas(graph), [graph])
    const defs = useMemo(() => defsByType(palette), [palette])
    const [selectedId, setSelectedId] = useState<string | null>(null)

    const decorations = useMemo(
        () => decorationsFor(canvas.nodes.map((node) => node.id), snapshot),
        [canvas.nodes, snapshot],
    )

    // Overlay errors travel as node errors so they render through the mandatory
    // list NodeCard already owns. A host renderer override cannot remove that
    // list, so a themed run view cannot silently hide a node's failure.
    const nodeErrors = useMemo(() => {
        const errors: Record<string, string[]> = Object.create(null)

        for (const node of canvas.nodes) {
            const entry = overlayFor(snapshot, node.id)

            if (entry?.error != null) {
                errors[node.id] = [entry.error]
            }
        }

        return errors
    }, [canvas.nodes, snapshot])

    return (
        <div className={className}>
            <div className="flex-1">
                <Canvas
                    nodes={canvas.nodes as NodeflowNode[]}
                    edges={canvas.edges as NodeflowEdge[]}
                    defs={defs}
                    renderers={nodeRenderers}
                    nodeErrors={nodeErrors}
                    nodeDecorations={decorations}
                    // Frozen: this graph is immutable and there is no endpoint
                    // to save an edit to. This is also what strips transient
                    // selected/dragging state out of the read-only render.
                    interactive={false}
                    onNodeClick={setSelectedId}
                />
            </div>
            <aside className="w-72 shrink-0 border-l border-border">
                <div className="space-y-1 p-3">
                    <p className="text-xs font-semibold text-foreground">{run.flow.name}</p>
                    <p className="text-[11px] text-muted-foreground">
                        Version {run.version} · {snapshot.status}
                        {run.is_test && ' · test run'}
                    </p>
                    {run.error !== null && <p role="alert" className="text-[11px] text-destructive">{run.error}</p>}
                    {error !== null && <p role="alert" className="text-[11px] text-destructive">{error}</p>}
                </div>
                {selectedId !== null && <SubjectPanel template={urls.subjects} nodeId={selectedId} />}
            </aside>
        </div>
    )
}
