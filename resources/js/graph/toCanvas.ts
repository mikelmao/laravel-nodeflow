import { gridPosition } from '../canvas/layout'
import type { CanvasEdge, CanvasNode, Graph, GraphNode } from './types'

function toConfig(config: GraphNode['config']): Record<string, unknown> {
  return config !== null && config !== undefined && !Array.isArray(config) ? config : {}
}

/** A pure graph adapter: the same stored draft always produces the same canvas. */
export function toCanvas(graph: Graph): { nodes: CanvasNode[]; edges: CanvasEdge[] } {
  const nodes = (graph.nodes ?? []).map((node, index): CanvasNode => ({
    id: node.id,
    type: 'nodeflowNode',
    position: node.position ?? gridPosition(index),
    data: {
      id: node.id,
      type: node.type,
      config: toConfig(node.config),
      isStart: node.id === graph.start,
    },
  }))

  const edges = (graph.edges ?? []).map((edge, index): CanvasEdge => ({
    // The index makes even parallel, otherwise-identical draft edges collision-safe.
    id: `nf${index}-${edge.from}-${edge.output ?? ''}-${edge.to}`,
    source: edge.from,
    sourceHandle: edge.output ?? null,
    target: edge.to,
    label: edge.output ?? undefined,
  }))

  return { nodes, edges }
}
