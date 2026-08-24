import type { CanvasEdge, CanvasNode, Graph, GraphComponentPayload } from './types'
import { cloneGraphConfig } from './json'

/** The palette as a lookup. One place builds it, so one place decides what a missing type means. */
export function defsByType(palette: GraphComponentPayload[]): Record<string, GraphComponentPayload> {
  const defs: Record<string, GraphComponentPayload> = Object.create(null)

  for (const entry of palette) {
    defs[entry.type] = entry
  }

  return defs
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
export function resolveOutput(
  sourceHandle: string | null | undefined,
  def: GraphComponentPayload | undefined,
): string | null {
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
  defs: Record<string, GraphComponentPayload>,
): { graph: Graph; unresolved: CanvasEdge[] } {
  const typeOf = new Map(canvas.nodes.map((node) => [node.id, node.data.type]))
  const unresolved: CanvasEdge[] = []

  const edges = canvas.edges.map((edge) => {
    const sourceType = typeOf.get(edge.source)
    const definition = sourceType !== undefined && Object.prototype.hasOwnProperty.call(defs, sourceType)
      ? defs[sourceType]
      : undefined
    const output = resolveOutput(edge.sourceHandle, definition)

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
        config: cloneGraphConfig(node.data.config),
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
