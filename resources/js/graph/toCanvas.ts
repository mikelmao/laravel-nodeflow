import { CANVAS_ORIGIN } from '../canvas/layout'
import { positionsForGraph } from './layout'
import type { CanvasEdge, CanvasNode, Graph, GraphComponentPayload, GraphNode } from './types'

function toConfig(config: GraphNode['config']): Record<string, unknown> {
  return config !== null && config !== undefined && !Array.isArray(config) ? config : {}
}

/** A pure graph adapter: the same stored draft always produces the same canvas. */
export function toCanvas(
  graph: Graph,
  definitions: Record<string, GraphComponentPayload> = Object.create(null),
): { nodes: CanvasNode[]; edges: CanvasEdge[] } {
  const positions = positionsForGraph(graph)
  const nodes = (graph.nodes ?? []).map((node): CanvasNode => ({
    id: node.id,
    type: 'nodeflowNode',
    position: positions[node.id] ?? CANVAS_ORIGIN,
    data: {
      id: node.id,
      type: node.type,
      kind: Object.prototype.hasOwnProperty.call(definitions, node.type) ? definitions[node.type]!.kind : null,
      config: toConfig(node.config),
      isStart: node.id === graph.start,
    },
  }))

  const edges = (graph.edges ?? []).map((edge, index): CanvasEdge => ({
    // The index makes even parallel, otherwise-identical draft edges collision-safe.
    id: `nf${index}-${edge.from}-${edge.output ?? ''}-${edge.to}`,
    type: 'nodeflowEdge',
    source: edge.from,
    sourceHandle: edge.output ?? null,
    target: edge.to,
    label: edge.output ?? undefined,
  }))

  return { nodes, edges }
}
