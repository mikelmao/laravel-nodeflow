import { describe, expect, it } from 'vitest'

import { toCanvas } from './toCanvas'
import type { Graph } from './types'

const baseGraph: Graph = {
  start: 'n1',
  nodes: [
    { id: 'n1', type: 'app.send', config: { subject: 'Hello' }, position: { x: 40, y: 80 } },
    { id: 'n2', type: 'core.exit', config: {} },
  ],
  edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
}

describe('toCanvas', () => {
  it('keeps a stored node position', () => {
    // Counterfactual: regenerating every position would move a saved canvas on reload.
    expect(toCanvas(baseGraph).nodes[0]?.position).toEqual({ x: 40, y: 80 })
  })

  it('places the second positionless node on the deterministic grid', () => {
    // Counterfactual: using a random or first-slot fallback would make layout unstable.
    const first = toCanvas(baseGraph).nodes[1]?.position
    const second = toCanvas(baseGraph).nodes[1]?.position

    expect(first).toEqual({ x: 300, y: 60 })
    expect(second).toEqual(first)
  })

  it('marks only the graph start node as the start', () => {
    // Counterfactual: deriving start state from array order would mislabel reordered drafts.
    expect(toCanvas(baseGraph).nodes.map((node) => node.data.isStart)).toEqual([true, false])
  })

  it('normalizes nullable graph containers, configs, and an omitted edge output', () => {
    // Counterfactual: passing PHP's empty arrays or nulls through would violate canvas contracts.
    const canvas = toCanvas({
      start: null,
      nodes: [
        { id: 'array', type: 'app.send', config: [] },
        { id: 'null', type: 'app.send', config: null },
        { id: 'missing', type: 'core.exit' },
      ],
      edges: [{ from: 'array', to: 'null' }],
    })

    expect(canvas.nodes.map((node) => node.data.config)).toEqual([{}, {}, {}])
    expect(canvas.edges[0]?.sourceHandle).toBeNull()
    expect(toCanvas({ nodes: null, edges: null })).toEqual({ nodes: [], edges: [] })
    expect(toCanvas({})).toEqual({ nodes: [], edges: [] })
  })

  it('maps a named graph output to the canvas edge handle and label', () => {
    // Counterfactual: dropping output names would disconnect multi-output nodes semantically.
    expect(toCanvas(baseGraph).edges[0]).toMatchObject({
      source: 'n1',
      sourceHandle: 'sent',
      target: 'n2',
      label: 'sent',
    })
  })

  it('assigns distinct ids to duplicate identical edges', () => {
    // Counterfactual: content-only ids collide, while random ids make the same draft unstable across conversions.
    const duplicateGraph: Graph = {
      ...baseGraph,
      edges: [baseGraph.edges?.[0] ?? { from: 'n1', to: 'n2' }, baseGraph.edges?.[0] ?? { from: 'n1', to: 'n2' }],
    }
    const first = toCanvas(duplicateGraph)
    const second = toCanvas(duplicateGraph)

    expect(first.edges[0]?.id).not.toBe(first.edges[1]?.id)
    expect(first.edges.map((edge) => edge.id)).toEqual(second.edges.map((edge) => edge.id))
  })

  it('keeps an explicit null output as a null source handle', () => {
    // Counterfactual: inventing a default here would conceal an unresolved draft edge.
    expect(toCanvas({ ...baseGraph, edges: [{ from: 'n1', to: 'n2', output: null }] }).edges[0]?.sourceHandle).toBeNull()
  })
})
