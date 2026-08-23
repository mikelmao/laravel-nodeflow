import { describe, expect, it } from 'vitest'

import { NODE_MIN_HEIGHT, NODE_WIDTH } from '../canvas/layout'
import { hierarchicalLayout, positionsForGraph } from './layout'
import type { Graph, GraphNode } from './types'

function overlaps(a: { x: number; y: number }, b: { x: number; y: number }): boolean {
  return a.x < b.x + NODE_WIDTH
    && a.x + NODE_WIDTH > b.x
    && a.y < b.y + NODE_MIN_HEIGHT
    && a.y + NODE_MIN_HEIGHT > b.y
}

describe('hierarchicalLayout', () => {
  it('places a simple sequence strictly left to right', () => {
    const positions = hierarchicalLayout(
      ['start', 'prepare', 'finish'],
      [{ from: 'start', to: 'prepare' }, { from: 'prepare', to: 'finish' }],
      'start',
    )

    expect(positions.start!.x).toBeLessThan(positions.prepare!.x)
    expect(positions.prepare!.x).toBeLessThan(positions.finish!.x)
  })

  it('separates branches vertically while preserving downstream layering', () => {
    const positions = hierarchicalLayout(
      ['start', 'yes', 'no', 'finish'],
      [
        { from: 'start', to: 'yes' },
        { from: 'start', to: 'no' },
        { from: 'yes', to: 'finish' },
        { from: 'no', to: 'finish' },
      ],
      'start',
    )

    expect(positions.yes!.y).not.toBe(positions.no!.y)
    expect(positions.start!.x).toBeLessThan(positions.yes!.x)
    expect(positions.start!.x).toBeLessThan(positions.no!.x)
    expect(positions.yes!.x).toBeLessThan(positions.finish!.x)
    expect(positions.no!.x).toBeLessThan(positions.finish!.x)
  })

  it('is deterministic and finite for cycles', () => {
    const nodeIds = ['start', 'cycle-a', 'cycle-b', 'finish']
    const edges = [
      { from: 'start', to: 'cycle-a' },
      { from: 'cycle-a', to: 'cycle-b' },
      { from: 'cycle-b', to: 'cycle-a' },
      { from: 'cycle-b', to: 'finish' },
    ]

    const first = hierarchicalLayout(nodeIds, edges, 'start')
    const second = hierarchicalLayout(nodeIds, edges, 'start')

    expect(second).toEqual(first)
    expect(Object.values(first).every((point) => Number.isFinite(point.x) && Number.isFinite(point.y))).toBe(true)
    expect(first['cycle-a']!.x).toBe(first['cycle-b']!.x)
    expect(first['cycle-a']!.y).not.toBe(first['cycle-b']!.y)
  })

  it('places disconnected components below the start component without overlap', () => {
    const positions = hierarchicalLayout(
      ['start', 'primary', 'other-root', 'other-leaf'],
      [
        { from: 'start', to: 'primary' },
        { from: 'other-root', to: 'other-leaf' },
      ],
      'start',
    )

    const primaryBottom = Math.max(positions.start!.y, positions.primary!.y) + NODE_MIN_HEIGHT
    const disconnectedTop = Math.min(positions['other-root']!.y, positions['other-leaf']!.y)

    expect(disconnectedTop).toBeGreaterThan(primaryBottom)
    expect(overlaps(positions.start!, positions['other-root']!)).toBe(false)
    expect(overlaps(positions.primary!, positions['other-leaf']!)).toBe(false)
  })

  it('owns prototype-like IDs without prototype pollution', () => {
    const positions = hierarchicalLayout(
      ['constructor', 'toString', '__proto__'],
      [{ from: 'constructor', to: 'toString' }, { from: 'toString', to: '__proto__' }],
      'constructor',
    )

    expect(Object.getPrototypeOf(positions)).toBeNull()
    expect(Object.hasOwn(positions, 'constructor')).toBe(true)
    expect(Object.hasOwn(positions, 'toString')).toBe(true)
    expect(Object.hasOwn(positions, '__proto__')).toBe(true)
    expect(({} as Record<string, unknown>).polluted).toBeUndefined()
  })
})

describe('positionsForGraph', () => {
  it('keeps every valid stored coordinate exactly', () => {
    const graph: Graph = {
      start: 'start',
      nodes: [
        { id: 'start', type: 'trigger', position: { x: 0, y: -0.5 } },
        { id: 'finish', type: 'exit', position: { x: 322.75, y: 901.25 } },
      ],
      edges: [{ from: 'start', to: 'finish' }],
    }

    expect(positionsForGraph(graph)).toMatchObject({
      start: { x: 0, y: -0.5 },
      finish: { x: 322.75, y: 901.25 },
    })
  })

  it('layers an unpositioned flood graph from left to right with readable branches', () => {
    const graph: Graph = {
      start: 'start',
      nodes: ['start', 'alpha', 'beta', 'gamma', 'finish'].map((id): GraphNode => ({ id, type: 'node' })),
      edges: [
        { from: 'start', to: 'alpha' },
        { from: 'start', to: 'beta' },
        { from: 'start', to: 'gamma' },
        { from: 'alpha', to: 'finish' },
        { from: 'beta', to: 'finish' },
        { from: 'gamma', to: 'finish' },
      ],
    }

    const positions = positionsForGraph(graph)

    expect(positions.start!.x).toBeLessThan(positions.alpha!.x)
    expect(positions.alpha!.x).toBe(positions.beta!.x)
    expect(positions.beta!.x).toBe(positions.gamma!.x)
    expect(positions.gamma!.x).toBeLessThan(positions.finish!.x)
    expect(new Set([positions.alpha!.y, positions.beta!.y, positions.gamma!.y]).size).toBe(3)
  })

  it('preserves partial stored positions and nudges missing nodes beyond occupied node boxes', () => {
    const graph: Graph = {
      start: 'start',
      nodes: [
        { id: 'start', type: 'node', position: { x: 72, y: 88 } },
        { id: 'middle', type: 'node' },
        { id: 'finish', type: 'node', position: { x: 480, y: 88 } },
      ],
      edges: [{ from: 'start', to: 'middle' }, { from: 'middle', to: 'finish' }],
    }

    const positions = positionsForGraph(graph)

    expect(positions.start).toEqual({ x: 72, y: 88 })
    expect(positions.finish).toEqual({ x: 480, y: 88 })
    expect(overlaps(positions.middle!, positions.start!)).toBe(false)
    expect(overlaps(positions.middle!, positions.finish!)).toBe(false)
  })
})
