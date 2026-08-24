import { describe, expect, it } from 'vitest'

import { toCanvas } from './toCanvas'
import type { Graph, GraphComponentPayload } from './types'

const definitions: Record<string, GraphComponentPayload> = {
  'custom.trigger': {
    kind: 'trigger',
    type: 'custom.trigger',
    driver: 'custom',
    label: 'Custom trigger',
    icon: null,
    description: null,
    outputs: ['started'],
    fields: [],
    default_config: { source: 'orders' },
    compatible_source_keys: ['orders'],
  },
  'app.send': {
    kind: 'executable',
    type: 'app.send',
    label: 'Send',
    group: 'Messaging',
    icon: null,
    description: null,
    outputs: ['sent'],
    fields: [],
    default_config: {},
    cardinality: ['subject'],
  },
}

const baseGraph: Graph = {
  start: 'n1',
  nodes: [
    { id: 'n1', type: 'app.send', config: { subject: 'Hello' }, position: { x: 40, y: 80 } },
    { id: 'n2', type: 'core.exit', config: {} },
  ],
  edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
}

describe('toCanvas', () => {
  it('resolves custom trigger and executable kinds from server definitions', () => {
    const graph: Graph = {
      start: 'trigger1',
      nodes: [
        { id: 'trigger1', type: 'custom.trigger', config: { source: 'orders' }, position: { x: 1, y: 2 } },
        { id: 'send1', type: 'app.send', config: {}, position: { x: 3, y: 4 } },
        { id: 'unknown1', type: 'missing.type', config: {}, position: { x: 5, y: 6 } },
      ],
      edges: [{ from: 'trigger1', to: 'send1', output: 'started' }],
    }

    const canvas = toCanvas(graph, definitions)

    expect(canvas.nodes.map((node) => node.data.kind)).toEqual(['trigger', 'executable', null])
    expect(canvas.nodes[0]).toMatchObject({
      position: { x: 1, y: 2 },
      data: { type: 'custom.trigger', config: { source: 'orders' }, isStart: true },
    })
    expect(canvas.edges[0]).toMatchObject({ source: 'trigger1', sourceHandle: 'started', target: 'send1' })
  })

  it('keeps every valid stored node position exactly', () => {
    // Counterfactual: regenerating stored positions would move a saved canvas on reload.
    const graph: Graph = {
      ...baseGraph,
      nodes: [
        { id: 'n1', type: 'app.send', position: { x: 40.5, y: -80.25 } },
        { id: 'n2', type: 'core.exit', position: { x: 0, y: 900.75 } },
      ],
    }

    expect(toCanvas(graph).nodes.map((node) => node.position)).toEqual([
      { x: 40.5, y: -80.25 },
      { x: 0, y: 900.75 },
    ])
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
      type: 'nodeflowEdge',
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
