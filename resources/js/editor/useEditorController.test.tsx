import { act, renderHook, waitFor } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasActions } from '../canvas/Canvas'
import type { EditorUrls, Graph, NodeTypePayload } from '../graph/types'
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
        const canvas: CanvasActions = { fit: vi.fn(), centerNode: vi.fn(), screenToFlowPosition: vi.fn(() => ({ x: 321, y: 123 })), viewportCenter: vi.fn(() => ({ x: 321, y: 123 })) }
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

    it('keeps legacy canvas actions usable when viewportCenter is absent', () => {
        const view = controller()
        const screenToFlowPosition = vi.fn(() => ({ x: 777, y: 333 }))
        const legacyCanvas: CanvasActions = { fit: vi.fn(), centerNode: vi.fn(), screenToFlowPosition }
        act(() => view.result.current.actions.registerCanvas(legacyCanvas))
        act(() => view.result.current.actions.addAtViewportCenter(exit))

        expect(screenToFlowPosition).toHaveBeenCalledOnce()
        expect(view.result.current.document.nodes.at(-1)?.position).toEqual({ x: 777, y: 333 })
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

    // A publish 422 is newer and more actionable than a preceding valid validate response.
    it('maps a semantic publish failure across notices, overview, canvas and the selected field', async () => {
        const sendWithTemplate: NodeTypePayload = {
            ...send,
            fields: [{ key: 'template', type: 'text', label: 'Template', help: null, default: null, required: true, options: {}, dynamic_options: false }],
        }
        const fetchMock = vi.fn((url: string) => {
            if (url === urls.validate) return Promise.resolve(Response.json({ valid: true, warnings: ['The exit path is slow.'] }))
            if (url === urls.publish) return Promise.resolve(Response.json({
                errors: ['The flow cannot be published.'],
                node_errors: [
                    { node: 'send1', field: 'template', message: 'Template is required.' },
                    { node: 'send1', field: null, message: 'Send message is incomplete.' },
                    { node: null, field: null, message: 'A removed branch is invalid.' },
                ],
            }, { status: 422 }))
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({ palette: [sendWithTemplate, exit] })

        await act(async () => view.result.current.actions.validate())
        act(() => view.result.current.actions.selectNode('send1'))
        await act(async () => view.result.current.actions.publish())

        expect(view.result.current.noticeProps.graphMessages).toEqual(expect.arrayContaining([
            'The flow cannot be published.',
            'A removed branch is invalid.',
        ]))
        expect(view.result.current.flowOverviewProps.errors).toEqual(expect.arrayContaining([
            'The flow cannot be published.',
            'A removed branch is invalid.',
        ]))
        expect(view.result.current.flowOverviewProps.warnings).toEqual(['The exit path is slow.'])
        expect(view.result.current.canvasProps.nodeErrors?.send1).toEqual(expect.arrayContaining([
            'template: Template is required.',
            'Send message is incomplete.',
        ]))
        expect(view.result.current.nodeInspectorProps?.errors).toEqual(expect.arrayContaining([
            expect.objectContaining({ field: 'template', message: 'Template is required.' }),
            expect.objectContaining({ field: null, message: 'Send message is incomplete.' }),
        ]))
        expect(view.result.current.flowOverviewProps.issues).toEqual(expect.arrayContaining([
            expect.objectContaining({ message: 'Template is required.', placeable: true }),
            expect.objectContaining({ message: 'A removed branch is invalid.', placeable: false }),
        ]))
        act(() => view.result.current.actions.focusIssue('send1', 'template'))
        expect(view.result.current.nodeInspectorProps?.issueToFocus).toEqual(expect.objectContaining({
            message: 'Template is required.',
        }))
    })

    // A response for an older document must not announce a version it did not publish.
    it('suppresses a successful publish response after the document generation changes', async () => {
        let resolvePublish!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => url === urls.publish
            ? new Promise<Response>((resolve) => { resolvePublish = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller()

        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        act(() => view.result.current.actions.addNode(exit, { x: 300, y: 0 }))
        await act(async () => resolvePublish(Response.json({ version: 4, draft_revision: 8 })))

        expect(view.result.current.toolbarProps.publishedVersion).toBe(3)
        expect(view.result.current.toolbarProps.publish.status).not.toBe('published')
        expect(view.result.current.noticeProps.publish?.status).not.toBe('published')
    })

    it('keeps React Flow select changes out of graph history while projecting node and edge selection', () => {
        const connected: Graph = { ...graph, edges: [{ from: 'send1', to: 'exit1', output: 'sent' }], nodes: [...(graph.nodes ?? []), { id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }] }
        const view = controller({ graph: connected })
        const edgeId = view.result.current.document.edges[0]!.id

        act(() => view.result.current.actions.nodesChange([{ id: 'send1', type: 'select', selected: true }]))
        expect(view.result.current.selected?.id).toBe('send1')
        expect(view.result.current.document.nodes[0]).not.toHaveProperty('selected')
        act(() => view.result.current.actions.nodesChange([{ id: 'send1', type: 'select', selected: false }]))
        expect(view.result.current.selected).toBeUndefined()
        expect(view.result.current.view.inspectorOpen).toBe(false)

        act(() => view.result.current.actions.edgesChange([{ id: edgeId, type: 'select', selected: true }]))
        expect(view.result.current.view.selectedEdgeId).toBe(edgeId)
        expect(view.result.current.canvasProps.edges[0]).toMatchObject({ id: edgeId, selected: true })
        expect(view.result.current.document.edges[0]).not.toHaveProperty('selected')
        act(() => view.result.current.actions.edgesChange([{ id: edgeId, type: 'remove' }]))
        expect(view.result.current.view.selectedEdgeId).toBeNull()
    })

    it('invalidates a pending validation when validation becomes unavailable', async () => {
        let resolveValidation!: (response: Response) => void
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { resolveValidation = resolve })))
        let currentUrls: EditorUrls = urls
        const view = renderHook(() => useEditorController({
            flow,
            graph,
            palette: [send, exit],
            triggers: [{ type: 'app.started', label: 'Started', description: null, fields: [] }],
            urls: currentUrls,
            autosaveDebounceMs: 1,
        }))
        act(() => { void view.result.current.actions.validate() })
        currentUrls = { ...urls, validate: undefined }
        view.rerender()
        await act(async () => view.result.current.actions.validate())
        await act(async () => resolveValidation(Response.json({ valid: true, warnings: [] })))
        expect(view.result.current.toolbarProps.validation.status).toBe('failed')
        expect(view.result.current.noticeProps.validationMessage).toMatch(/unavailable/i)
    })

    it('invalidates pending validation and publish responses when their URL props change', async () => {
        let resolveValidation!: (response: Response) => void
        let resolvePublish!: (response: Response) => void
        let resolveNewPublish!: (response: Response) => void
        let currentUrls: EditorUrls = urls
        vi.stubGlobal('fetch', vi.fn((url: string) => {
            if (url === urls.validate) return new Promise<Response>((resolve) => { resolveValidation = resolve })
            if (url === urls.publish) return new Promise<Response>((resolve) => { resolvePublish = resolve })
            if (url === '/new-publish') return new Promise<Response>((resolve) => { resolveNewPublish = resolve })
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        }))
        const view = renderHook(() => useEditorController({ flow, graph, palette: [send, exit], triggers: [{ type: 'app.started', label: 'Started', description: null, fields: [] }], urls: currentUrls, autosaveDebounceMs: 1 }))
        act(() => { void view.result.current.actions.validate(); void view.result.current.actions.publish() })
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))
        currentUrls = { ...urls, validate: '/new-validate', publish: '/new-publish' }
        view.rerender()
        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolveNewPublish).toBeTypeOf('function'))
        await act(async () => { resolveValidation(Response.json({ valid: true, warnings: [] })); resolvePublish(Response.json({ version: 4, draft_revision: 8 })) })

        expect(view.result.current.toolbarProps.validation.status).toBe('unchecked')
        expect(view.result.current.toolbarProps.publishedVersion).toBe(3)
        await act(async () => resolveNewPublish(Response.json({ version: 5, draft_revision: 9 })))
        expect(view.result.current.toolbarProps.publishedVersion).toBe(5)
    })
})
