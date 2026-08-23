import { act, renderHook } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasActions } from '../canvas/Canvas'
import type { Graph, NodeTypePayload } from '../graph/types'
import { useEditorController } from './useEditorController'

const flow = { id: 1, name: 'Studio', trigger_type: 'app.started', status: 'draft', version: 3, draft_revision: 7, draft_updated_at: null }
const urls = { draft: '/draft', publish: '/publish', validate: '/validate', options: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__' }
const send: NodeTypePayload = {
    type: 'app.send', label: 'Send Message', group: 'Messaging', icon: null, description: null,
    outputs: ['sent'], fields: [], default_config: {}, cardinality: ['subject'],
}
const exit: NodeTypePayload = {
    type: 'core.exit', label: 'Exit', group: 'Core', icon: null, description: null,
    outputs: [], fields: [], default_config: {}, cardinality: ['subject'],
}
const graph: Graph = {
    start: 'send1',
    nodes: [{ id: 'send1', type: 'app.send', config: {}, position: { x: 0, y: 0 } }],
    edges: [],
}

function controller(overrides: Partial<Parameters<typeof useEditorController>[0]> = {}) {
    return renderHook(() => useEditorController({
        flow,
        graph,
        palette: [send, exit],
        triggers: [{ type: 'app.started', label: 'Started', description: null, fields: [] }],
        urls,
        autosaveDebounceMs: 1,
        ...overrides,
    }))
}

describe('useEditorController', () => {
    // A regression to node-count ids or a fixed add coordinate makes two rapid adds collide/overlap.
    it('adds collision-safe nodes at the requested point and makes only the first an empty-document start', () => {
        const empty = controller({ graph: { start: null, nodes: [], edges: [] } })
        act(() => empty.result.current.actions.addNode(send, { x: 20, y: 30 }))
        expect(empty.result.current.document).toMatchObject({ startId: 'send1', nodes: [{ id: 'send1', position: { x: 20, y: 30 } }] })
        act(() => empty.result.current.actions.addNode(send, { x: 20, y: 30 }))
        expect(empty.result.current.document.nodes.map((node) => node.id)).toEqual(['send1', 'send2'])
        expect(empty.result.current.document.nodes[1]!.position).not.toEqual({ x: 20, y: 30 })

        const populated = controller()
        act(() => populated.result.current.actions.addNode(exit, { x: 500, y: 75 }))
        expect(populated.result.current.document.startId).toBe('send1')
        expect(populated.result.current.document.nodes.at(-1)).toMatchObject({ position: { x: 500, y: 75 } })
    })

    // Connection gestures without a declared source output must never manufacture a publishable edge.
    it('accepts only declared, non-duplicate output connections and keeps selection outside graph history', () => {
        const view = controller()
        act(() => view.result.current.actions.addNode(exit, { x: 500, y: 0 }))
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: null, targetHandle: null }))
        expect(view.result.current.document.edges).toHaveLength(0)
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: 'sent', targetHandle: null }))
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: 'sent', targetHandle: null }))
        expect(view.result.current.document.edges).toHaveLength(1)
        act(() => view.result.current.actions.selectNode('send1'))
        expect(view.result.current.selected?.id).toBe('send1')
        act(() => view.result.current.actions.undo())
        expect(view.result.current.selected?.id).toBe('send1')
    })

    // A completed drag is one author action even though xyflow emits several position fragments.
    it('coalesces a drag into one undo and maps canvas actions into controller props', () => {
        const view = controller()
        const canvas: CanvasActions = { fit: vi.fn(), centerNode: vi.fn(), screenToFlowPosition: vi.fn(() => ({ x: 321, y: 123 })) }
        act(() => view.result.current.actions.registerCanvas(canvas))
        act(() => view.result.current.actions.nodesChange([
            { id: 'send1', type: 'position', position: { x: 100, y: 20 }, dragging: true },
            { id: 'send1', type: 'position', position: { x: 200, y: 30 }, dragging: false },
        ]))
        expect(view.result.current.document.nodes[0]!.position).toEqual({ x: 200, y: 30 })
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document.nodes[0]!.position).toEqual({ x: 0, y: 0 })
        act(() => view.result.current.actions.addAtViewportCenter(exit))
        expect(view.result.current.document.nodes.at(-1)?.position).toEqual({ x: 321, y: 123 })
        expect(view.result.current.canvasProps.deleteKeyCode).toBeNull()
    })

    // Validate has its own endpoint and sequence; it is not a disguised save or publish operation.
    it('posts only the canonical graph to validate and ignores a late response after an edit', async () => {
        let resolveValidation!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => url === urls.validate
            ? new Promise<Response>((resolve) => { resolveValidation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller()
        act(() => { void view.result.current.actions.validate() })
        act(() => view.result.current.actions.addNode(exit, { x: 300, y: 0 }))
        await act(async () => resolveValidation(Response.json({ valid: true, warnings: [] })))
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.validate)).toHaveLength(1)
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
        expect(view.result.current.toolbarProps.validation.status).toBe('unchecked')
    })
})
