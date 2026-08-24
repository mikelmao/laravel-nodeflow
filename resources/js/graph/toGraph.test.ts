import { describe, expect, it } from 'vitest'
import { toCanvas } from './toCanvas'
import { defsByType, resolveOutput, toGraph } from './toGraph'
import type { Graph, GraphComponentPayload, NodeTypePayload } from './types'

function def(type: string, outputs: string[]): NodeTypePayload {
  return {
    kind: 'executable',
    type,
    label: type,
    group: 'General',
    icon: null,
    description: null,
    outputs,
    fields: [],
    default_config: {},
    cardinality: ['subject'],
  }
}

const defs = defsByType([
  def('app.send', ['sent', 'failed']),
  def('core.exit', []),
  def('one.out', ['default']),
  def('__proto__', ['own-proto']),
  def('constructor', ['own-constructor']),
  def('toString', ['own-string']),
])

const graph = {
  start: 'n1',
  nodes: [
    { id: 'n1', type: 'app.send', config: { template: 'welcome', count: 3 }, position: { x: 40, y: 80 } },
    { id: 'n2', type: 'core.exit', config: {}, position: { x: 300, y: 80 } },
  ],
  edges: [{ from: 'n1', to: 'n2', output: 'sent' }],
}

describe('toGraph', () => {
  it('round-trips a custom trigger with its started edge and canvas kind', () => {
    const customTrigger = {
      kind: 'trigger',
      type: 'custom.trigger',
      driver: 'custom',
      label: 'Custom trigger',
      icon: null,
      description: 'Starts custom work.',
      outputs: ['started'],
      fields: [],
      default_config: { source: 'orders' },
      compatible_source_keys: ['orders'],
    } as const satisfies GraphComponentPayload
    const customDefs = defsByType([customTrigger, def('app.send', ['sent'])])
    const triggerGraph: Graph = {
      start: 'trigger1',
      nodes: [
        { id: 'trigger1', type: 'custom.trigger', config: { source: 'vip' }, position: { x: 12.5, y: -4 } },
        { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 20 } },
      ],
      edges: [{ from: 'trigger1', to: 'send1', output: 'started' }],
    }

    const canvas = toCanvas(triggerGraph, customDefs)
    const converted = toGraph(canvas, 'trigger1', customDefs)

    expect(canvas.nodes.map((node) => node.data.kind)).toEqual(['trigger', 'executable'])
    expect(converted.graph).toEqual(triggerGraph)
    expect(converted.unresolved).toEqual([])
  })

  it('emits nested config references independently from the canvas', () => {
    const canvas = toCanvas({
      start: 'n1',
      nodes: [{ id: 'n1', type: 'app.send', config: { routing: { tags: ['vip'] } }, position: { x: 0, y: 0 } }],
      edges: [],
    })

    const converted = toGraph(canvas, 'n1', defs).graph
    const emitted = converted.nodes![0]!.config as { routing: { tags: string[] } }
    emitted.routing.tags.push('new')

    expect(canvas.nodes[0]!.data.config).toEqual({ routing: { tags: ['vip'] } })
    expect(emitted).not.toBe(canvas.nodes[0]!.data.config)
  })

  // The round-trip case §9 asks for, with positions present on every node so
  // the assertion is identity rather than "close enough".
  // Counterfactual: drop `position` from the emitted node, or drop `config`, or
  // emit `start` from anywhere but the argument, and this fails.
  it('round-trips start, ids, config, position and edge outputs', () => {
    const { graph: out, unresolved } = toGraph(toCanvas(graph), graph.start ?? '', defs)

    expect(out).toEqual(graph)
    expect(unresolved).toEqual([])
    expect(Object.getPrototypeOf(defs)).toBeNull()
    expect(defs['__proto__']?.outputs).toEqual(['own-proto'])
    expect(defs['constructor']?.outputs).toEqual(['own-constructor'])
    expect(defs['toString']?.outputs).toEqual(['own-string'])
  })

  // The binding contract says canvas positions round-trip untouched.
  // Counterfactual: round here and a fractional position is silently changed
  // just by loading and serialising the graph.
  it('preserves fractional positions exactly', () => {
    const canvas = toCanvas(graph)
    canvas.nodes[0]!.position = { x: 40.4, y: 80.6 }

    expect(toGraph(canvas, 'n1', defs).graph.nodes?.[0]?.position).toEqual({ x: 40.4, y: 80.6 })
  })

  // THE PROTOTYPE'S BUG, pinned. `~/Sites/test-workflow`'s editor.tsx did
  // `output: e.sourceHandle ?? 'default'`, so a dropped handle published an
  // edge naming an output app.send never declared, and the server rejected it
  // with a message about an output the author never chose.
  // Counterfactual: restore `?? 'default'` and this fails on both assertions.
  it('never invents an output for a handle it cannot resolve', () => {
    const canvas = toCanvas(graph)
    canvas.edges[0]!.sourceHandle = null

    const { graph: out, unresolved } = toGraph(canvas, 'n1', defs)

    expect(out.edges?.[0]?.output).toBeNull()
    expect(unresolved).toHaveLength(1)
    expect(unresolved[0]?.id).toBe(canvas.edges[0]!.id)
  })

  // Counterfactual: return null for every unhandled edge and a node with a
  // single output — the common case — needs a handle click it has no reason to
  // need, and every such draft blocks publish.
  it('resolves a missing handle when the node declares exactly one output', () => {
    const single: Graph = {
      start: 'a',
      nodes: [
        { id: 'a', type: 'one.out', config: {}, position: { x: 0, y: 0 } },
        { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } },
      ],
      edges: [{ from: 'a', to: 'b', output: null }],
    }

    const { graph: out, unresolved } = toGraph(toCanvas(single), 'a', defs)

    expect(out.edges?.[0]?.output).toBe('default')
    expect(unresolved).toEqual([])
  })

  // A draft may reference a type the host has not registered — that is legal,
  // and publish is where it is caught. Counterfactual: substitute a known
  // definition (or its first output) for a missing lookup and this emits a
  // plausible output instead of preserving a savable unresolved edge.
  it('leaves an edge unresolved when the source node type is not in the palette', () => {
    const unknown: Graph = {
      start: 'a',
      nodes: [
        { id: 'a', type: 'not.registered', config: {}, position: { x: 0, y: 0 } },
        { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } },
      ],
      edges: [{ from: 'a', to: 'b', output: null }],
    }

    const { graph: out, unresolved } = toGraph(toCanvas(unknown), 'a', defs)

    expect(out.edges?.[0]?.output).toBeNull()
    expect(unresolved).toHaveLength(1)

    const inheritedDefs = Object.create({ constructor: def('constructor', ['inherited']) })
    const special: Graph = {
      start: 'a',
      nodes: [
        { id: 'a', type: 'constructor', config: {}, position: { x: 0, y: 0 } },
        { id: 'b', type: 'core.exit', config: {}, position: { x: 0, y: 0 } },
      ],
      edges: [{ from: 'a', to: 'b', output: null }],
    }
    const inherited = toGraph(toCanvas(special), 'a', inheritedDefs)

    expect(inherited.graph.edges?.[0]?.output).toBeNull()
    expect(inherited.unresolved).toHaveLength(1)
  })

  // The draft endpoint accepts an omitted output as well as null. toCanvas is
  // the normalisation boundary. Counterfactual: assign `sourceHandle:
  // edge.output` without `?? null` and the first assertion sees undefined;
  // the canvas no longer has the one documented representation for an
  // unresolved handle.
  it('normalises an omitted output to null and reports it unresolved', () => {
    const omitted: Graph = {
      start: 'a',
      nodes: [{ id: 'a', type: 'app.send', config: {} }, { id: 'b', type: 'core.exit', config: {} }],
      edges: [{ from: 'a', to: 'b' }],
    }

    const canvas = toCanvas(omitted)
    expect(canvas.edges[0]?.sourceHandle).toBeNull()

    const { graph: out, unresolved } = toGraph(canvas, 'a', defs)

    expect(out.edges?.[0]?.output).toBeNull()
    expect(unresolved).toHaveLength(1)
  })
})

describe('resolveOutput', () => {
  // Counterfactual: treat '' as a handle name and the edge publishes with an
  // empty output, which GraphValidator reports as an unknown output ''.
  it('treats an empty handle as no handle', () => {
    expect(resolveOutput('', def('t', ['a', 'b']))).toBeNull()
  })

  // Counterfactual: ignore the actual handle and choose outputs[0], and a
  // connection drawn from `failed` silently becomes `sent`.
  it('prefers the handle the author actually used', () => {
    expect(resolveOutput('failed', def('t', ['sent', 'failed']))).toBe('failed')
  })

  // Counterfactual: fall through to outputs[0] and a terminal node's stray
  // edge publishes an output that does not exist.
  it('resolves nothing for a node that declares no outputs', () => {
    expect(resolveOutput(null, def('t', []))).toBeNull()
  })
})
