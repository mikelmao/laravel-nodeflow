import {
  CANVAS_ORIGIN,
  COMPONENT_GAP,
  LAYER_GAP,
  NODE_MIN_HEIGHT,
  NODE_WIDTH,
  ROW_GAP,
} from '../canvas/layout'
import type { Graph, GraphNode } from './types'

export type Point = { x: number; y: number }

type ComponentGraph = {
  components: string[][]
  componentByNode: Map<string, number>
  incoming: Map<number, number[]>
  outgoing: Map<number, number[]>
}

function uniqueNodeIds(nodeIds: string[]): string[] {
  const ids: string[] = []
  const known = new Set<string>()

  for (const id of nodeIds) {
    if (!known.has(id)) {
      known.add(id)
      ids.push(id)
    }
  }

  return ids
}

function adjacency(nodeIds: string[], edges: Array<{ from: string; to: string }>): Map<string, string[]> {
  const known = new Set(nodeIds)
  const graph = new Map(nodeIds.map((id) => [id, [] as string[]]))

  for (const { from, to } of edges) {
    if (!known.has(from) || !known.has(to)) {
      continue
    }

    const neighbors = graph.get(from)!
    if (!neighbors.includes(to)) {
      neighbors.push(to)
    }
  }

  return graph
}

/** Tarjan's traversal follows the persisted node and edge order. */
function stronglyConnectedComponents(nodeIds: string[], graph: Map<string, string[]>): string[][] {
  const indexByNode = new Map<string, number>()
  const lowlinkByNode = new Map<string, number>()
  const stack: string[] = []
  const onStack = new Set<string>()
  const components: string[][] = []
  let index = 0

  const visit = (node: string): void => {
    indexByNode.set(node, index)
    lowlinkByNode.set(node, index)
    index += 1
    stack.push(node)
    onStack.add(node)

    for (const neighbor of graph.get(node) ?? []) {
      if (!indexByNode.has(neighbor)) {
        visit(neighbor)
        lowlinkByNode.set(node, Math.min(lowlinkByNode.get(node)!, lowlinkByNode.get(neighbor)!))
      } else if (onStack.has(neighbor)) {
        lowlinkByNode.set(node, Math.min(lowlinkByNode.get(node)!, indexByNode.get(neighbor)!))
      }
    }

    if (lowlinkByNode.get(node) !== indexByNode.get(node)) {
      return
    }

    const component: string[] = []
    let member: string | undefined
    do {
      member = stack.pop()
      if (member !== undefined) {
        onStack.delete(member)
        component.push(member)
      }
    } while (member !== node)
    components.push(component)
  }

  for (const node of nodeIds) {
    if (!indexByNode.has(node)) {
      visit(node)
    }
  }

  return components
}

function componentDag(nodeIds: string[], graph: Map<string, string[]>): ComponentGraph {
  const inputOrder = new Map(nodeIds.map((id, index) => [id, index]))
  const components = stronglyConnectedComponents(nodeIds, graph)
    .map((component) => component.sort((left, right) => inputOrder.get(left)! - inputOrder.get(right)!))
  const componentByNode = new Map<string, number>()
  const incoming = new Map<number, number[]>()
  const outgoing = new Map<number, number[]>()

  components.forEach((component, componentId) => {
    incoming.set(componentId, [])
    outgoing.set(componentId, [])
    component.forEach((node) => componentByNode.set(node, componentId))
  })

  for (const node of nodeIds) {
    const fromComponent = componentByNode.get(node)!
    for (const target of graph.get(node) ?? []) {
      const toComponent = componentByNode.get(target)!
      if (fromComponent === toComponent || outgoing.get(fromComponent)!.includes(toComponent)) {
        continue
      }
      outgoing.get(fromComponent)!.push(toComponent)
      incoming.get(toComponent)!.push(fromComponent)
    }
  }

  return { components, componentByNode, incoming, outgoing }
}

function componentRank(component: number, graph: ComponentGraph, inputOrder: Map<string, number>): number {
  return Math.min(...graph.components[component]!.map((node) => inputOrder.get(node)!))
}

/** Longest predecessor distance on the acyclic component graph. */
function componentLayers(
  graph: ComponentGraph,
  inputOrder: Map<string, number>,
  included: Set<number>,
  preferredStart?: number,
): Map<number, number> {
  const indegree = new Map<number, number>()
  const layers = new Map<number, number>()
  const ready: number[] = []

  for (const component of included) {
    const count = (graph.incoming.get(component) ?? []).filter((source) => included.has(source)).length
    indegree.set(component, count)
    if (count === 0) {
      ready.push(component)
      layers.set(component, 0)
    }
  }

  ready.sort((left, right) => componentRank(left, graph, inputOrder) - componentRank(right, graph, inputOrder))
  for (let cursor = 0; cursor < ready.length; cursor += 1) {
    const component = ready[cursor]!
    const layer = layers.get(component) ?? 0
    for (const target of graph.outgoing.get(component) ?? []) {
      if (!included.has(target) || target === preferredStart) {
        continue
      }
      layers.set(target, Math.max(layers.get(target) ?? 0, layer + 1))
      const remaining = indegree.get(target)! - 1
      indegree.set(target, remaining)
      if (remaining === 0) {
        ready.push(target)
      }
    }
  }

  if (preferredStart !== undefined && included.has(preferredStart)) {
    layers.set(preferredStart, 0)
  }
  for (const component of included) {
    if (!layers.has(component)) {
      layers.set(component, 0)
    }
  }

  return layers
}

/** Stable barycentric sweeps keep parallel branches readable without randomness. */
function orderLayer(
  layers: Map<number, number>,
  graph: ComponentGraph,
  inputOrder: Map<string, number>,
): Map<number, number[]> {
  const ordered = new Map<number, number[]>()
  for (const [component, layer] of layers) {
    const row = ordered.get(layer) ?? []
    row.push(component)
    ordered.set(layer, row)
  }
  for (const row of ordered.values()) {
    row.sort((left, right) => componentRank(left, graph, inputOrder) - componentRank(right, graph, inputOrder))
  }

  const layerNumbers = [...ordered.keys()].sort((left, right) => left - right)
  const positionByComponent = (): Map<number, number> => {
    const positions = new Map<number, number>()
    for (const layer of layerNumbers) {
      ordered.get(layer)!.forEach((component, index) => positions.set(component, index))
    }
    return positions
  }
  const sortByNeighbors = (layer: number, neighbors: (component: number) => number[]): void => {
    const positions = positionByComponent()
    ordered.get(layer)!.sort((left, right) => {
      const barycenter = (component: number): number | undefined => {
        const related = neighbors(component).filter((neighbor) => layers.get(neighbor) !== layer)
        if (related.length === 0) {
          return undefined
        }
        return related.reduce((total, neighbor) => total + (positions.get(neighbor) ?? 0), 0) / related.length
      }
      const leftCenter = barycenter(left)
      const rightCenter = barycenter(right)
      if (leftCenter !== undefined && rightCenter !== undefined && leftCenter !== rightCenter) {
        return leftCenter - rightCenter
      }
      if (leftCenter !== undefined && rightCenter === undefined) return -1
      if (leftCenter === undefined && rightCenter !== undefined) return 1
      return componentRank(left, graph, inputOrder) - componentRank(right, graph, inputOrder)
    })
  }

  for (let sweep = 0; sweep < 2; sweep += 1) {
    for (const layer of layerNumbers.slice(1)) {
      sortByNeighbors(layer, (component) => graph.incoming.get(component) ?? [])
    }
    for (const layer of [...layerNumbers].reverse().slice(0, -1)) {
      sortByNeighbors(layer, (component) => graph.outgoing.get(component) ?? [])
    }
  }

  return ordered
}

function placeComponents(
  graph: ComponentGraph,
  layers: Map<number, number>,
  inputOrder: Map<string, number>,
  top: number,
): { positions: Map<string, Point>; bottom: number } {
  const positions = new Map<string, Point>()
  const ordered = orderLayer(layers, graph, inputOrder)
  let bottom = top

  for (const [layer, components] of ordered) {
    let row = 0
    for (const component of components) {
      for (const node of graph.components[component]!) {
        const point = {
          x: CANVAS_ORIGIN.x + layer * (NODE_WIDTH + LAYER_GAP),
          y: top + row * (NODE_MIN_HEIGHT + ROW_GAP),
        }
        positions.set(node, point)
        bottom = Math.max(bottom, point.y + NODE_MIN_HEIGHT)
        row += 1
      }
    }
  }

  return { positions, bottom }
}

function reachableComponents(graph: ComponentGraph, start: number): Set<number> {
  const reachable = new Set<number>([start])
  const queue = [start]
  for (let index = 0; index < queue.length; index += 1) {
    for (const target of graph.outgoing.get(queue[index]!) ?? []) {
      if (!reachable.has(target)) {
        reachable.add(target)
        queue.push(target)
      }
    }
  }
  return reachable
}

function disconnectedGroups(graph: ComponentGraph, components: Set<number>): Set<number>[] {
  const remaining = new Set(components)
  const groups: Set<number>[] = []
  while (remaining.size > 0) {
    const root = remaining.values().next().value as number
    const group = new Set<number>([root])
    const queue = [root]
    remaining.delete(root)
    for (let index = 0; index < queue.length; index += 1) {
      const component = queue[index]!
      for (const neighbor of [...(graph.incoming.get(component) ?? []), ...(graph.outgoing.get(component) ?? [])]) {
        if (remaining.delete(neighbor)) {
          group.add(neighbor)
          queue.push(neighbor)
        }
      }
    }
    groups.push(group)
  }
  return groups
}

function rectanglesOverlap(left: Point, right: Point): boolean {
  return Math.abs(left.x - right.x) < NODE_WIDTH && Math.abs(left.y - right.y) < NODE_MIN_HEIGHT
}

function nudgePastOccupied(
  nodeIds: string[],
  topology: Record<string, Point>,
  stored: Map<string, Point>,
): Record<string, Point> {
  const positions: Record<string, Point> = Object.create(null)
  const occupied: Point[] = []

  for (const nodeId of nodeIds) {
    const point = stored.get(nodeId)
    if (point !== undefined) {
      positions[nodeId] = point
      occupied.push(point)
    }
  }

  for (const nodeId of nodeIds) {
    if (stored.has(nodeId)) {
      continue
    }
    let point = topology[nodeId] ?? CANVAS_ORIGIN
    while (occupied.some((other) => rectanglesOverlap(point, other))) {
      point = { x: point.x, y: point.y + NODE_MIN_HEIGHT + ROW_GAP }
    }
    positions[nodeId] = point
    occupied.push(point)
  }

  return positions
}

export function hierarchicalLayout(
  nodeIds: string[],
  edges: Array<{ from: string; to: string }>,
  startId: string,
): Record<string, Point> {
  const ids = uniqueNodeIds(nodeIds)
  const positions: Record<string, Point> = Object.create(null)
  if (ids.length === 0) {
    return positions
  }

  const inputOrder = new Map(ids.map((id, index) => [id, index]))
  const components = componentDag(ids, adjacency(ids, edges))
  const start = components.componentByNode.get(startId) ?? components.componentByNode.get(ids[0]!)!
  const primary = reachableComponents(components, start)
  const primaryLayout = placeComponents(
    components,
    componentLayers(components, inputOrder, primary, start),
    inputOrder,
    CANVAS_ORIGIN.y,
  )

  for (const [node, point] of primaryLayout.positions) {
    positions[node] = point
  }

  const disconnected = new Set<number>()
  components.components.forEach((_, component) => {
    if (!primary.has(component)) {
      disconnected.add(component)
    }
  })
  let top = primaryLayout.bottom + COMPONENT_GAP
  for (const group of disconnectedGroups(components, disconnected)) {
    const placed = placeComponents(components, componentLayers(components, inputOrder, group), inputOrder, top)
    for (const [node, point] of placed.positions) {
      positions[node] = point
    }
    top = placed.bottom + COMPONENT_GAP
  }

  return positions
}

function validPosition(node: GraphNode): Point | undefined {
  const position = node.position
  return position !== undefined && position !== null && Number.isFinite(position.x) && Number.isFinite(position.y)
    ? { x: position.x, y: position.y }
    : undefined
}

/** Stored finite coordinates are authoritative; only new nodes receive topology placement. */
export function positionsForGraph(graph: Graph): Record<string, Point> {
  const nodes = graph.nodes ?? []
  const ids = uniqueNodeIds(nodes.map((node) => node.id))
  const stored = new Map<string, Point>()
  for (const node of nodes) {
    const position = validPosition(node)
    if (position !== undefined && !stored.has(node.id)) {
      stored.set(node.id, position)
    }
  }

  const start = graph.start !== null && graph.start !== undefined ? graph.start : ids[0] ?? ''
  const topology = hierarchicalLayout(ids, graph.edges ?? [], start)
  return nudgePastOccupied(ids, topology, stored)
}
