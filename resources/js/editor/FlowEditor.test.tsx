import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { StrictMode } from 'react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { CanvasProps } from '../canvas/Canvas'
import type { FieldControlProps } from '../controls/types'
import type { Graph, NodeTypePayload, TriggerNodeTypePayload } from '../graph/types'
import { FlowEditor } from './FlowEditor'

const canvasProbe = vi.hoisted(() => ({ current: null as CanvasProps | null }))

vi.mock('../canvas/Canvas', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../canvas/Canvas')>()

    return {
        ...actual,
        Canvas: (props: CanvasProps) => {
            canvasProbe.current = props
            return <actual.Canvas {...props} />
        },
    }
})

const flow = {
    id: 12,
    name: 'Welcome journey',
    status: 'draft',
    version: 3,
    draft_revision: 7,
    draft_updated_at: null,
}
const urls = {
    draft: '/flows/12/draft',
    publish: '/flows/12/publish',
    options: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
    trigger_options: '/trigger-options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
    trigger_source_options: '/trigger-source-options/__NODEFLOW_TYPE__/__NODEFLOW_SOURCE__/__NODEFLOW_FIELD__',
    rotate_webhook_secret: '/flows/12/webhook-secret/rotate',
}
const sendDefinition: NodeTypePayload = {
    kind: 'executable',
    type: 'app.send',
    label: 'Send message',
    group: 'Messaging',
    icon: null,
    description: 'Send one message.',
    outputs: ['sent', 'failed'],
    fields: [{
        key: 'template',
        type: 'text',
        label: 'Template',
        help: null,
        default: null,
        required: true,
        options: {},
        dynamic_options: false,
    }],
    default_config: { template: null },
    cardinality: ['subject'],
}
const exitDefinition: NodeTypePayload = {
    kind: 'executable',
    type: 'core.exit',
    label: 'Exit',
    group: 'Core',
    icon: null,
    description: 'Stop the flow.',
    outputs: [],
    fields: [],
    default_config: {},
    cardinality: ['subject'],
}
const palette = [sendDefinition, exitDefinition]
const triggerNodes: TriggerNodeTypePayload[] = [{
    kind: 'trigger',
    type: 'app.order_placed',
    driver: 'event',
    label: 'Order placed',
    icon: null,
    description: 'When a customer places an order.',
    outputs: ['started'],
    fields: [],
    default_config: {},
    compatible_source_keys: [],
}]
const webhookTrigger: TriggerNodeTypePayload = {
    kind: 'trigger',
    type: 'host.webhook',
    driver: 'webhook',
    label: 'Webhook',
    icon: null,
    description: 'Starts from a signed webhook.',
    outputs: ['started'],
    fields: [{ key: 'source', type: 'select', label: 'Source', help: null, default: null, required: true, options: {}, dynamic_options: false }],
    default_config: { source: null },
    compatible_source_keys: ['orders.webhook'],
}
const eventTrigger: TriggerNodeTypePayload = {
    ...webhookTrigger,
    type: 'host.event',
    driver: 'event',
    label: 'Laravel event',
    description: 'Starts from an event.',
    compatible_source_keys: ['orders.event'],
}
const authorableSources = {
    webhook: [{ key: 'orders.webhook', driver: 'webhook', label: 'Order webhook', icon: null, description: null, fields: [], default_config: {} }],
    event: [{ key: 'orders.event', driver: 'event', label: 'Order event', icon: null, description: null, fields: [], default_config: {} }],
}
const triggeredGraph: Graph = {
    start: 'trigger',
    nodes: [
        { id: 'trigger', type: webhookTrigger.type, config: { source: 'orders.webhook' }, position: { x: 0, y: 0 } },
        { id: 'send1', type: 'app.send', config: { template: 'welcome' }, position: { x: 300, y: 0 } },
    ],
    edges: [{ from: 'trigger', to: 'send1', output: 'started' }],
}
const graph: Graph = {
    start: 'send1',
    nodes: [
        { id: 'send1', type: 'app.send', config: { template: 'welcome' }, position: { x: 0, y: 0 } },
        { id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } },
    ],
    edges: [{ from: 'send1', to: 'exit1', output: 'sent' }],
}

function renderEditor(overrides: Partial<React.ComponentProps<typeof FlowEditor>> = {}) {
    return render(
        <FlowEditor
            flow={flow}
            graph={graph}
            palette={palette}
            trigger_nodes={[]}
            trigger_sources={{ event: [] }}
            webhook={null}
            urls={urls}
            autosaveDebounceMs={5}
            {...overrides}
        />,
    )
}

function canvasNode(id: string): HTMLElement {
    const result = document.querySelector(`.react-flow__node[data-id="${id}"]`)
    if (!(result instanceof HTMLElement)) {
        throw new Error(`Could not find canvas node ${id}.`)
    }
    return result
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function requestBody(fetchMock: ReturnType<typeof vi.fn>, url: string, occurrence = 0): Record<string, unknown> {
    const call = fetchMock.mock.calls.filter(([calledUrl]) => calledUrl === url)[occurrence]
    if (call === undefined) {
        throw new Error(`No ${url} request at occurrence ${occurrence}.`)
    }
    const body = call[1]?.body
    if (typeof body !== 'string') {
        throw new Error(`The ${url} request did not have a JSON string body.`)
    }
    const parsed: unknown = JSON.parse(body)
    if (!isRecord(parsed)) {
        throw new Error(`The ${url} request body was not a JSON object.`)
    }
    return parsed
}

function successfulFetch(publishRevision = 8) {
    return vi.fn(async (url: string, init: RequestInit) => {
        if (url === urls.publish && init.method === 'POST') {
            return Response.json({ version: 4, draft_revision: publishRevision })
        }
        return Response.json({ draft_revision: 8 })
    })
}

function installMediaQuery(initiallyNarrow: boolean) {
    const media = {
        matches: initiallyNarrow,
        media: '(max-width: 1023px)',
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }
    vi.stubGlobal('matchMedia', vi.fn(() => media))
}

beforeEach(() => {
    canvasProbe.current = null
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 8 })))
})

afterEach(() => vi.unstubAllGlobals())

describe('FlowEditor', () => {
    it('accepts the exact Task 11 trigger source, webhook, and URL wire shapes', () => {
        const props = {
            flow,
            graph,
            palette,
            trigger_nodes: triggerNodes,
            trigger_sources: {
                event: [{
                    key: 'orders.placed',
                    driver: 'event',
                    label: 'Order placed source',
                    icon: null,
                    description: 'An allowlisted Laravel event.',
                    fields: [],
                    default_config: { channel: 'orders' },
                }],
            },
            webhook: {
                endpoint_url: 'https://example.test/hooks/token',
                active: true,
                secret_rotated_at: '2026-08-24T10:00:00+00:00',
            },
            urls,
        } satisfies React.ComponentProps<typeof FlowEditor>

        expect(props.trigger_nodes[0]?.compatible_source_keys).toEqual([])
        expect(props.trigger_sources.event[0]?.driver).toBe('event')
        expect(props.webhook.endpoint_url).toContain('/hooks/')
        expect(props.urls).toMatchObject({
            rotate_webhook_secret: '/flows/12/webhook-secret/rotate',
            trigger_options: expect.stringContaining('__NODEFLOW_TYPE__'),
            trigger_source_options: expect.stringContaining('__NODEFLOW_SOURCE__'),
        })
    })

    it('reserves modified F and L shortcuts while accepting only the approved Fit and Auto layout keys', () => {
        const fit = vi.fn()
        renderEditor({ graph: {
            ...graph,
            nodes: [
                { ...graph.nodes![0]!, position: { x: 999, y: 333 } },
                { ...graph.nodes![1]!, position: { x: 1_500, y: 333 } },
            ],
        } })
        const callbacks = canvasProbe.current
        if (callbacks?.onReady === undefined) throw new Error('Canvas did not expose its actions callback.')
        act(() => callbacks.onReady?.({ fit, centerNode: vi.fn(), screenToFlowPosition: vi.fn(() => ({ x: 0, y: 0 })) }))
        const pane = document.querySelector('.react-flow__pane')
        if (!(pane instanceof HTMLElement)) throw new Error('Could not find the React Flow pane.')
        fireEvent.pointerDown(pane)

        for (const init of [
            { key: 'f', ctrlKey: true },
            { key: 'f', metaKey: true },
            { key: 'f', altKey: true },
            { key: 'f', shiftKey: true },
            { key: 'l', ctrlKey: true, shiftKey: true },
            { key: 'l', metaKey: true, shiftKey: true },
            { key: 'l', altKey: true, shiftKey: true },
        ]) {
            const event = new KeyboardEvent('keydown', { ...init, cancelable: true })
            act(() => document.dispatchEvent(event))
            expect(event.defaultPrevented).toBe(false)
        }
        expect(fit).not.toHaveBeenCalled()
        expect(canvasProbe.current?.nodes.map((node) => node.position)).toEqual([{ x: 999, y: 333 }, { x: 1_500, y: 333 }])

        const fitEvent = new KeyboardEvent('keydown', { key: 'f', cancelable: true })
        act(() => document.dispatchEvent(fitEvent))
        expect(fitEvent.defaultPrevented).toBe(true)
        expect(fit).toHaveBeenCalledOnce()

        const layoutEvent = new KeyboardEvent('keydown', { key: 'l', shiftKey: true, cancelable: true })
        act(() => document.dispatchEvent(layoutEvent))
        expect(layoutEvent.defaultPrevented).toBe(true)
        expect(canvasProbe.current?.nodes.map((node) => node.position)).not.toEqual([{ x: 999, y: 333 }, { x: 1_500, y: 333 }])
    })

    it('keeps the desktop inspector open for overview after genuine pane deselection but respects an explicit collapse', () => {
        installMediaQuery(false)
        renderEditor()

        expect(screen.getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        fireEvent.click(canvasNode('send1'))
        expect(screen.getByRole('complementary', { name: 'Node inspector' })).toBeInTheDocument()

        const pane = document.querySelector('.react-flow__pane')
        if (!(pane instanceof HTMLElement)) throw new Error('Could not find the React Flow pane.')
        fireEvent.click(pane)
        expect(screen.getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Collapse Inspector' })).toBeInTheDocument()
        expect(screen.queryByRole('button', { name: 'Open Inspector' })).toBeNull()

        fireEvent.click(screen.getByRole('button', { name: 'Collapse Inspector' }))
        expect(screen.queryByRole('complementary', { name: 'Flow overview' })).toBeNull()
        fireEvent.click(canvasNode('send1'))
        expect(screen.getByRole('complementary', { name: 'Node inspector' })).toBeInTheDocument()

        fireEvent.click(screen.getByRole('button', { name: 'Collapse Inspector' }))
        fireEvent.click(pane)
        expect(screen.queryByRole('complementary', { name: 'Flow overview' })).toBeNull()
        expect(screen.getByRole('button', { name: 'Open Inspector' })).toBeInTheDocument()
    })

    it('keeps the narrow inspector drawer closed after pane deselection but opens it for node selection', async () => {
        installMediaQuery(true)
        renderEditor()
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Inspector' })).toBeInTheDocument())

        fireEvent.keyDown(document, { key: 'Escape' })
        expect(screen.getByRole('button', { name: 'Open Inspector' })).toBeInTheDocument()
        fireEvent.click(canvasNode('send1'))
        const dialog = await screen.findByRole('dialog', { name: 'Inspector' })
        expect(within(dialog).getByRole('complementary', { name: 'Node inspector' })).toBeInTheDocument()

        const pane = document.querySelector('.react-flow__pane')
        if (!(pane instanceof HTMLElement)) throw new Error('Could not find the React Flow pane.')
        fireEvent.click(pane)
        expect(within(screen.getByRole('dialog', { name: 'Inspector' })).getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        fireEvent.keyDown(document, { key: 'Escape' })
        fireEvent.click(pane)
        expect(screen.queryByRole('dialog', { name: 'Inspector' })).toBeNull()
        expect(screen.getByRole('button', { name: 'Open Inspector' })).toBeInTheDocument()
    })

    // Trigger metadata is server-authored and read-only; counterfactual showing only the key hides author guidance.
    it('names the flow and resets the whole editor only when its authoritative identity changes', async () => {
        let resolveOldPublish!: (response: Response) => void
        const oldPublish = new Promise<Response>((resolve) => { resolveOldPublish = resolve })
        const fetchMock = vi.fn((url: string) => {
            if (url === urls.publish) {
                return oldPublish
            }
            if (url === '/flows/13/publish') {
                return Promise.resolve(Response.json({ version: 6, draft_revision: 11 }))
            }
            return Promise.resolve(Response.json({ draft_revision: 10 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const view = renderEditor()
        expect(screen.getByRole('heading', { name: 'Welcome journey', level: 1 })).toBeInTheDocument()
        expect(screen.getByText(/Trigger: No trigger/)).toBeInTheDocument()

        fireEvent.click(canvasNode('send1'))
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))

        const nextFlow = {
            ...flow,
            id: 13,
            name: 'Flow Two',
            version: 5,
            draft_revision: 9,
        }
        const nextUrls = {
            ...urls,
            draft: '/flows/13/draft',
            publish: '/flows/13/publish',
        }
        const nextGraph: Graph = {
            start: 'new1',
            nodes: [{
                id: 'new1',
                type: 'app.send',
                config: { template: 'new flow' },
                position: { x: 25, y: 50 },
            }],
            edges: [],
        }
        view.rerender(
            <FlowEditor
                flow={nextFlow}
                graph={nextGraph}
                palette={palette}
                trigger_nodes={[]}
                trigger_sources={{ event: [] }}
                webhook={null}
                urls={nextUrls}
                autosaveDebounceMs={5}
            />,
        )

        expect(screen.getByRole('heading', { name: 'Flow Two', level: 1 })).toBeInTheDocument()
        expect(screen.getByText(/published v5/i)).toBeInTheDocument()
        expect(screen.getByText(/Start: new1/i)).toBeInTheDocument()
        expect(canvasNode('new1')).toBeInTheDocument()
        expect(screen.getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        await new Promise((resolve) => setTimeout(resolve, 15))
        expect(fetchMock.mock.calls.filter(([url]) => url === nextUrls.draft)).toHaveLength(0)

        await act(async () => resolveOldPublish(Response.json({ version: 77, draft_revision: 77 })))
        expect(screen.queryByText(/v77/i)).toBeNull()
        expect(screen.queryByText(/Published v77/i)).toBeNull()

        fireEvent.click(canvasNode('new1'))
        fireEvent.change(screen.getByLabelText(/Template/), { target: { value: 'kept through churn' } })
        view.rerender(
            <FlowEditor
                flow={{ ...nextFlow }}
                graph={{ ...nextGraph, nodes: [...(nextGraph.nodes ?? [])], edges: [] }}
                palette={[...palette]}
                trigger_nodes={[]}
                trigger_sources={{ event: [] }}
                webhook={null}
                urls={{ ...nextUrls }}
                autosaveDebounceMs={5}
            />,
        )
        expect(screen.getByLabelText(/Template/)).toHaveValue('kept through churn')

        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === nextUrls.publish)).toHaveLength(1))
        expect(requestBody(fetchMock, nextUrls.publish).graph).toMatchObject({
            start: 'new1',
            nodes: [expect.objectContaining({ id: 'new1', config: { template: 'kept through churn' } })],
        })
    })

    it('remounts the publish barrier when the publish endpoint changes', async () => {
        let resolveOld!: (response: Response) => void
        const oldPublish = new Promise<Response>((resolve) => { resolveOld = resolve })
        const nextUrls = { ...urls, publish: '/flows/12/publish-new' }
        const fetchMock = vi.fn((url: string) => {
            if (url === urls.publish) return oldPublish
            if (url === nextUrls.publish) return Promise.resolve(Response.json({ version: 5, draft_revision: 9 }))
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const view = renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))

        view.rerender(<FlowEditor flow={flow} graph={graph} palette={palette} trigger_nodes={[]} trigger_sources={{ event: [] }} webhook={null} urls={nextUrls} autosaveDebounceMs={5} />)
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === nextUrls.publish)).toHaveLength(1))
        await act(async () => resolveOld(Response.json({ version: 99, draft_revision: 99 })))
        expect(screen.queryByText(/Published v99/i)).toBeNull()
        expect((await screen.findAllByText(/Published v5/i)).length).toBeGreaterThan(0)
    })

    // Published version is durable state; counterfactual reporting draft revision confuses concurrency with releases.
    it('reports the published version and actionable draft autosave failures', async () => {
        const published = renderEditor()
        expect(screen.getByText(/published v3/i)).toBeInTheDocument()
        published.unmount()

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('draft offline')))
        const offline = renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        expect(await screen.findByText(/Could not reach the server to save this draft.*draft offline/i)).toBeInTheDocument()
        offline.unmount()

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Page Expired</html>', { status: 419 })))
        const expired = renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        expect(await screen.findByText(/session expired before this draft could be saved/i)).toBeInTheDocument()
        expired.unmount()

        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 'bad' })))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        expect(await screen.findByText(/invalid draft response.*non-negative safe integer/i)).toBeInTheDocument()
    })

    // One semantic error has three scopes; counterfactual flatten-only loses card and field placement.
    it('places semantic errors in the banner, node card and selected field', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({
            errors: ['Node [send1] field [template]: required'],
            node_errors: [{ node: 'send1', field: 'template', message: 'required' }],
        }, { status: 422 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText('Node [send1] field [template]: required')).length).toBeGreaterThan(0)
        expect(within(canvasNode('send1')).getByText('template: required')).toBeInTheDocument()
        fireEvent.click(canvasNode('send1'))
        expect(screen.getByText('required')).toBeInTheDocument()
    })

    // An absent node cannot own visible card UI; counterfactual dropping it makes the failure unrepairably invisible.
    it('keeps a semantic error for an absent node in the banner', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({
            errors: [],
            node_errors: [{ node: 'ghost', field: null, message: 'Node [ghost] is invalid.' }],
        }, { status: 422 })))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText('Node [ghost] is invalid.')).length).toBeGreaterThan(0)
    })

    // Structural 422 means the client broke the wire shape; counterfactual presenting it as author validation misdiagnoses it.
    it('labels and flattens structural validation as an editor bug', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({
            errors: { 'graph.nodes.0.id': ['The graph.nodes.0.id field is required.'] },
        }, { status: 422 })))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText(/editor sent a graph the server could not read/i)).toBeInTheDocument()
        expect(screen.getAllByText('graph.nodes.0.id: The graph.nodes.0.id field is required.').length).toBeGreaterThan(0)
    })

    // Ambiguous outputs must block locally; counterfactual guessing an output sends a graph the author never made.
    it('refuses to publish an unresolved edge output', async () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { ...graph, edges: [{ from: 'send1', to: 'exit1', output: null }] } })
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText(/which output/i)).length).toBeGreaterThan(0)
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
    })

    // Keep mine must retry byte-for-byte local graph with the server token; counterfactual rebuilding from theirs loses work.
    it('keeps mine after a conflict and retries the same graph with the newer revision', async () => {
        const theirs = { start: null, nodes: [], edges: [] }
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json({ message: 'Conflict', graph: theirs, draft_revision: 20 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 21 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        await screen.findByRole('button', { name: 'Keep mine' })
        const first = requestBody(fetchMock, urls.draft)
        fireEvent.click(screen.getByRole('button', { name: 'Keep mine' }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
        const second = requestBody(fetchMock, urls.draft, 1)
        expect(second.draft_revision).toBe(20)
        expect(second.graph).toEqual(first.graph)
    })

    // The POST can lose a race after its draft barrier succeeds; its 409 must
    // enter the same visible conflict workflow as a draft PUT.
    it('adopts a publish-time conflict revision before retrying the local graph', async () => {
        const theirs: Graph = { start: null, nodes: [{ id: 'theirs', type: 'core.exit' }], edges: [] }
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json({ message: 'Publish lost the draft race.', graph: theirs, draft_revision: 20 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 21 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()

        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await screen.findByRole('button', { name: 'Keep mine' })
        expect(requestBody(fetchMock, urls.publish).draft_revision).toBe(7)

        fireEvent.click(screen.getByRole('button', { name: 'Keep mine' }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
        expect(requestBody(fetchMock, urls.draft)).toMatchObject({
            draft_revision: 20,
            graph,
        })
    })

    // Use theirs must canonicalise omitted containers and clear selection; counterfactual retaining local selection edits the wrong node.
    it('adopts and publishes the server graph after a conflict', async () => {
        const theirs: Graph = {
            start: null,
            nodes: [{ id: 'exit2', type: 'app.send' }, { id: 'theirs2', type: 'core.exit' }],
            edges: [{ from: 'exit2', to: 'theirs2', output: 'sent' }],
        }
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json({ message: 'Conflict', graph: theirs, draft_revision: 20 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ version: 4, draft_revision: 20 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(canvasNode('exit1'))
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        await screen.findByRole('button', { name: 'Use theirs' })
        fireEvent.click(screen.getByRole('button', { name: 'Use theirs' }))
        expect(await screen.findByText(/Start: none/i)).toBeInTheDocument()
        expect(canvasNode('exit2')).toBeInTheDocument()
        expect(canvasNode('theirs2')).toBeInTheDocument()
        expect(screen.getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText(/Published v4/)).length).toBeGreaterThan(0)
        expect(requestBody(fetchMock, urls.publish)).toEqual({
            draft_revision: 20,
            graph: {
                start: '',
                nodes: [
                    { id: 'exit2', type: 'app.send', config: {}, position: { x: 72, y: 88 } },
                    { id: 'theirs2', type: 'core.exit', config: {}, position: { x: 480, y: 88 } },
                ],
                edges: [{ from: 'exit2', to: 'theirs2', output: 'sent' }],
            },
        })
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.draft)).toHaveLength(1)
    })

    // Publish serialises behind an active PUT; counterfactual POST racing it publishes an older server draft.
    it('waits for an active draft save and adopts the publish revision for the next edit', async () => {
        let resolveDraft!: (response: Response) => void
        const activeDraft = new Promise<Response>((resolve) => { resolveDraft = resolve })
        const fetchMock = vi.fn()
            .mockReturnValueOnce(activeDraft)
            .mockResolvedValueOnce(Response.json({ version: 4, draft_revision: 30 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 31 }))
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
        await resolveDraft(Response.json({ draft_revision: 8 }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({
            nodes: expect.arrayContaining([{ id: 'exit2', type: 'core.exit', config: {}, position: { x: 72, y: 296 } }]),
        })
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
        expect(requestBody(fetchMock, urls.draft, 1).draft_revision).toBe(30)
    })

    // Network rejection is recoverable UI state; counterfactual leaving the button disabled forces a reload.
    it('owns one publish attempt, suppresses stale validation and recovers from a network failure', async () => {
        let resolvePublish!: (response: Response) => void
        const pendingPublish = new Promise<Response>((resolve) => { resolvePublish = resolve })
        const fetchMock = vi.fn((url: string) => url === urls.publish
            ? pendingPublish
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const ownership = renderEditor()

        const publish = screen.getByRole('button', { name: 'Publish' })
        act(() => {
            publish.dispatchEvent(new MouseEvent('click', { bubbles: true }))
            publish.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        })
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
        expect(screen.queryByText(/draft could not be saved before publishing/i)).toBeNull()

        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        await act(async () => resolvePublish(Response.json({
            errors: ['Node [send1] field [template]: stale validation'],
            node_errors: [{ node: 'send1', field: 'template', message: 'stale validation' }],
        }, { status: 422 })))
        await waitFor(() => expect(screen.getByRole('button', { name: 'Publish' })).toBeEnabled())
        expect(screen.queryAllByText(/stale validation/i)).toHaveLength(0)
        ownership.unmount()

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText(/Could not reach server.*offline/i)).length).toBeGreaterThan(0)
        expect(screen.getByRole('button', { name: 'Publish' })).toBeEnabled()
    })

    // IDs extend the existing type sequence; counterfactual counting nodes alone can collide with send1.
    it('mints collision-safe ids across two synchronous additions', () => {
        renderEditor()
        const add = screen.getByRole('button', { name: 'Add Send message' })
        act(() => {
            add.dispatchEvent(new MouseEvent('click', { bubbles: true }))
            add.dispatchEvent(new MouseEvent('click', { bubbles: true }))
        })
        expect(canvasNode('send2')).toBeInTheDocument()
        expect(canvasNode('send3')).toBeInTheDocument()
    })

    // Panel edits mutate graph data; counterfactual local input state publishes the stale welcome value.
    it('publishes a configuration edit on the original node', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        render(
            <StrictMode>
                <FlowEditor
                    flow={flow}
                    graph={graph}
                    palette={palette}
                    trigger_nodes={[]}
                    trigger_sources={{ event: [] }}
                    webhook={null}
                    urls={urls}
                    autosaveDebounceMs={5}
                />
            </StrictMode>,
        )
        fireEvent.click(canvasNode('send1'))
        fireEvent.change(screen.getByLabelText(/Template/), { target: { value: 'changed' } })
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        expect((await screen.findAllByText(/Published v4/)).length).toBeGreaterThan(0)
        expect(screen.getByRole('button', { name: 'Publish' })).toBeEnabled()
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({
            nodes: expect.arrayContaining([
                expect.objectContaining({ id: 'send1', config: { template: 'changed' } }),
            ]),
        })
    })

    it('routes document undo only to the editor claimed by its latest pointer interaction', () => {
        const firstGraph: Graph = { start: 'first', nodes: [{ id: 'first', type: 'app.send', config: { template: 'first' }, position: { x: 0, y: 0 } }], edges: [] }
        const secondGraph: Graph = { start: 'second', nodes: [{ id: 'second', type: 'app.send', config: { template: 'second' }, position: { x: 0, y: 0 } }], edges: [] }
        render(<>
            <FlowEditor flow={{ ...flow, id: 71, name: 'First editor' }} graph={firstGraph} palette={palette} trigger_nodes={triggerNodes} trigger_sources={{ event: [] }} webhook={null} urls={urls} autosaveDebounceMs={5} />
            <FlowEditor flow={{ ...flow, id: 72, name: 'Second editor' }} graph={secondGraph} palette={palette} trigger_nodes={triggerNodes} trigger_sources={{ event: [] }} webhook={null} urls={urls} autosaveDebounceMs={5} />
        </>)
        const firstNode = document.querySelector('.react-flow__node[data-id="first"]') as HTMLElement
        const secondNode = document.querySelector('.react-flow__node[data-id="second"]') as HTMLElement

        fireEvent.click(firstNode)
        fireEvent.change(screen.getAllByLabelText(/Template/)[0]!, { target: { value: 'first changed' } })
        fireEvent.click(secondNode)
        fireEvent.change(screen.getAllByLabelText(/Template/)[1]!, { target: { value: 'second changed' } })
        fireEvent.pointerDown(secondNode)
        fireEvent.keyDown(document, { key: 'z', ctrlKey: true })

        expect(screen.getAllByLabelText(/Template/)[0]).toHaveValue('first changed')
        expect(screen.getAllByLabelText(/Template/)[1]).toHaveValue('second')
        fireEvent.pointerDown(firstNode)
        fireEvent.keyDown(document, { key: 'z', ctrlKey: true })
        expect(screen.getAllByLabelText(/Template/)[0]).toHaveValue('first')
    })

    it('promotes shortcut focus to the fallback editor only when the active editor unmounts focused', () => {
        const First = ({ second }: { second: boolean }) => <>
            <FlowEditor flow={{ ...flow, id: 81, name: 'First editor' }} graph={graph} palette={palette} trigger_nodes={triggerNodes} trigger_sources={{ event: [] }} webhook={null} urls={urls} autosaveDebounceMs={5} />
            {second && <FlowEditor flow={{ ...flow, id: 82, name: 'Second editor' }} graph={graph} palette={palette} trigger_nodes={triggerNodes} trigger_sources={{ event: [] }} webhook={null} urls={urls} autosaveDebounceMs={5} />}
        </>
        const view = render(<First second />)
        const roots = view.container.querySelectorAll<HTMLElement>('.contents[tabindex="-1"]')
        roots[1]!.focus()
        expect(document.activeElement).toBe(roots[1])

        view.rerender(<First second={false} />)
        expect(document.activeElement).toBe(roots[0])
    })

    // Panel deletion owns graph invariants; counterfactual deleting only the node leaves start and dangling edges.
    it('clears start and incident edges when deleting from the panel', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(canvasNode('send1'))
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish)).toEqual({
            draft_revision: 8,
            graph: {
                start: '',
                nodes: [{ id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }],
                edges: [],
            },
        })
    })

    // React Flow keyboard deletion must share cleanup; counterfactual applyNodeChanges alone leaves a dangling start/edge.
    it('clears start and incident edges when React Flow deletes a selected node', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        const keyboard = renderEditor()
        fireEvent.click(canvasNode('send1'))
        fireEvent.keyDown(document, { key: 'Delete' })
        await waitFor(() => expect(document.querySelector('.react-flow__node[data-id="send1"]')).toBeNull())
        keyboard.unmount()

        renderEditor()
        const callbacks = canvasProbe.current
        if (callbacks?.onNodesChange === undefined || callbacks.onConnect === undefined) {
            throw new Error('Canvas did not expose its mutation callbacks.')
        }
        act(() => {
            callbacks.onNodesChange?.([{ id: 'send1', type: 'remove' }])
            callbacks.onConnect?.({
                source: 'send1',
                target: 'exit1',
                sourceHandle: 'sent',
                targetHandle: null,
            })
        })
        await waitFor(() => expect(document.querySelector('.react-flow__node[data-id="send1"]')).toBeNull())
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish)).toEqual({
            draft_revision: 8,
            graph: {
                start: '',
                nodes: [{ id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }],
                edges: [],
            },
        })
        for (const requestUrl of [urls.draft, urls.publish]) {
            const calls = fetchMock.mock.calls.filter(([calledUrl]) => calledUrl === requestUrl)
            calls.forEach((_, occurrence) => {
                expect(requestBody(fetchMock, requestUrl, occurrence).graph).toMatchObject({ edges: [] })
            })
        }
    })

    // Trigger identity owns start; adding an executable to an empty draft cannot bypass it.
    it('does not promote the first executable node to graph start', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { start: null, nodes: [], edges: [] } })
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        expect(within(canvasNode('exit1')).queryByText('START')).toBeNull()
        expect(screen.getByText(/Start: none/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({ start: '' })
    })

    // Explicit start selection and blank control drafts both belong to the selected node. A field-only key leaks
    // between nodes with the same field, while a colon composite collides for [a:b, c] and [a, b:c].
    it('isolates selected node control state without exposing executable make-start', async () => {
        const durationDefinition = (type: string, label: string, fieldKey: string): NodeTypePayload => ({
            kind: 'executable',
            type,
            label,
            group: 'Core',
            icon: null,
            description: 'Wait before continuing.',
            outputs: ['completed'],
            fields: [{
                key: fieldKey,
                type: 'duration',
                label: 'Duration',
                help: null,
                default: null,
                required: true,
                options: {},
                dynamic_options: false,
            }],
            default_config: { [fieldKey]: null },
            cardinality: ['subject'],
        })

        const sameFieldFetch = successfulFetch()
        vi.stubGlobal('fetch', sameFieldFetch)
        const sameFieldDefinition = durationDefinition('app.wait', 'Wait', 'duration')
        const sameFieldEditor = renderEditor({
            palette: [sameFieldDefinition],
            graph: {
                start: 'same-a',
                nodes: [
                    { id: 'same-a', type: 'app.wait', config: { duration: null }, position: { x: 0, y: 0 } },
                    { id: 'same-b', type: 'app.wait', config: { duration: null }, position: { x: 300, y: 0 } },
                ],
                edges: [],
            },
        })

        fireEvent.click(canvasNode('same-a'))
        fireEvent.change(screen.getByRole('combobox', { name: 'Duration unit' }), { target: { value: 'hours' } })
        fireEvent.click(canvasNode('same-b'))
        expect(screen.getByRole('combobox', { name: 'Duration unit' })).toHaveValue('minutes')
        fireEvent.change(screen.getByRole('spinbutton', { name: 'Duration amount' }), { target: { value: '1' } })
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(sameFieldFetch.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(sameFieldFetch, urls.publish).graph).toMatchObject({
            nodes: expect.arrayContaining([
                expect.objectContaining({ id: 'same-b', config: { duration: '1 minute' } }),
            ]),
        })
        sameFieldEditor.unmount()

        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        const waitADefinition = durationDefinition('app.wait-a', 'Wait A', 'c')
        const waitBDefinition = durationDefinition('app.wait-b', 'Wait B', 'b:c')
        renderEditor({
            palette: [waitADefinition, waitBDefinition],
            graph: {
                start: 'a:b',
                nodes: [
                    { id: 'a:b', type: 'app.wait-a', config: { c: null }, position: { x: 0, y: 0 } },
                    { id: 'a', type: 'app.wait-b', config: { 'b:c': null }, position: { x: 300, y: 0 } },
                ],
                edges: [],
            },
        })

        fireEvent.click(canvasNode('a:b'))
        fireEvent.change(screen.getByRole('combobox', { name: 'Duration unit' }), { target: { value: 'hours' } })

        fireEvent.click(canvasNode('a'))
        expect(screen.getByRole('combobox', { name: 'Duration unit' })).toHaveValue('minutes')
        fireEvent.change(screen.getByRole('spinbutton', { name: 'Duration amount' }), { target: { value: '1' } })
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        expect(screen.queryByRole('button', { name: /start node/i })).toBeNull()
        expect(screen.getByText(/Start: a:b$/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({
            start: 'a:b',
            nodes: expect.arrayContaining([
                expect.objectContaining({ id: 'a', config: { 'b:c': '1 minute' } }),
            ]),
        })
    })

    // Host controls merge with built-ins; counterfactual replacing the map makes Template disappear.
    it('renders a host control alongside a working built-in control', () => {
        const Town = ({ field, value, onChange }: FieldControlProps) => (
            <label>{field.label}<input value={String(value ?? '')} onChange={(event) => onChange(event.target.value)} /></label>
        )
        const withTown: NodeTypePayload = {
            ...sendDefinition,
            fields: [
                { ...sendDefinition.fields[0]!, key: 'destination', type: 'town', label: 'Destination' },
                sendDefinition.fields[0]!,
            ],
        }
        renderEditor({
            palette: [withTown, exitDefinition],
            graph: {
                ...graph,
                nodes: graph.nodes?.map((item) => item.id === 'send1'
                    ? { ...item, config: { destination: 'Bucharest', template: 'welcome' } }
                    : item),
            },
            controls: { town: Town },
        })
        fireEvent.click(canvasNode('send1'))
        expect(screen.getByLabelText('Destination')).toHaveValue('Bucharest')
        expect(screen.getByLabelText(/Template/)).toHaveValue('welcome')
    })

    it('isolates nested executable config from an in-place mutating host control', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        const MutatingObject = ({ value, onChange }: FieldControlProps) => {
            const current = value as { rules: { tags: string[] }[] }
            return <button type="button" onClick={() => {
                current.rules[0]!.tags.push('changed')
                onChange(current)
            }}>Executable value: {current.rules[0]!.tags.join(',')}</button>
        }
        const nestedDefinition: NodeTypePayload = {
            ...sendDefinition,
            fields: [{ ...sendDefinition.fields[0]!, key: 'routing', type: 'mutating-object', label: 'Routing' }],
            default_config: {},
        }
        const inputGraph: Graph = {
            start: 'send1',
            nodes: [{ id: 'send1', type: 'app.send', config: { routing: { rules: [{ tags: ['original'] }] } }, position: { x: 0, y: 0 } }],
            edges: [],
        }

        renderEditor({ graph: inputGraph, palette: [nestedDefinition, exitDefinition], controls: { 'mutating-object': MutatingObject } })
        fireEvent.click(canvasNode('send1'))
        fireEvent.click(screen.getByRole('button', { name: 'Executable value: original' }))

        expect(screen.getByRole('button', { name: 'Executable value: original,changed' })).toBeInTheDocument()
        expect(inputGraph.nodes![0]!.config).toEqual({ routing: { rules: [{ tags: ['original'] }] } })
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.draft)).toHaveLength(1))
        expect(requestBody(fetchMock, urls.draft)).toMatchObject({
            graph: { nodes: [expect.objectContaining({ config: { routing: { rules: [{ tags: ['original', 'changed'] }] } } })] },
        })

        fireEvent.keyDown(document, { key: 'z', ctrlKey: true })
        expect(screen.getByRole('button', { name: 'Executable value: original' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Redo' })).toBeEnabled()
        fireEvent.keyDown(document, { key: 'z', ctrlKey: true, shiftKey: true })
        expect(screen.getByRole('button', { name: 'Executable value: original,changed' })).toBeInTheDocument()
    })

    it('isolates a source-contributed trigger field default from a mutating host control', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        const MutatingArray = ({ value, onChange }: FieldControlProps) => {
            const current = value as string[]
            return <button type="button" onClick={() => {
                current.push('updated')
                onChange(current)
            }}>Trigger value: {current.join(',')}</button>
        }
        const sourceDefault = ['created']
        const sourceField = {
            ...sendDefinition.fields[0]!,
            key: 'events',
            type: 'mutating-array',
            label: 'Source events',
            default: sourceDefault,
        }
        const triggerDefinition: TriggerNodeTypePayload = {
            ...triggerNodes[0]!,
            fields: [sourceField],
        }
        const inputGraph: Graph = {
            start: 'trigger',
            nodes: [
                { id: 'trigger', type: triggerDefinition.type, config: {}, position: { x: 0, y: 0 } },
                { id: 'send1', type: 'app.send', config: { template: 'welcome' }, position: { x: 300, y: 0 } },
            ],
            edges: [{ from: 'trigger', to: 'send1', output: 'started' }],
        }

        renderEditor({ graph: inputGraph, trigger_nodes: [triggerDefinition], controls: { 'mutating-array': MutatingArray } })
        fireEvent.click(canvasNode('trigger'))
        fireEvent.click(screen.getByRole('button', { name: 'Trigger value: created' }))

        expect(screen.getByRole('button', { name: 'Trigger value: created,updated' })).toBeInTheDocument()
        expect(sourceDefault).toEqual(['created'])
        expect(sourceField.default).toEqual(['created'])
        expect(inputGraph.nodes![0]!.config).toEqual({})
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.draft)).toHaveLength(1))
        expect(requestBody(fetchMock, urls.draft)).toMatchObject({
            graph: { nodes: expect.arrayContaining([expect.objectContaining({ id: 'trigger', config: { events: ['created', 'updated'] } })]) },
        })

        fireEvent.keyDown(document, { key: 'z', ctrlKey: true })
        expect(screen.getByRole('button', { name: 'Trigger value: created' })).toBeInTheDocument()
        expect(sourceField.default).toEqual(['created'])
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Redo' })).toBeEnabled()
        fireEvent.keyDown(document, { key: 'z', ctrlKey: true, shiftKey: true })
        expect(screen.getByRole('button', { name: 'Trigger value: created,updated' })).toBeInTheDocument()
    })

    // Two focusable descendants remain one field transaction until focus leaves their shared field row.
    it('undoes compound custom-control edits together after focus leaves that field', () => {
        const Compound = ({ value, onChange }: FieldControlProps) => <>
            <input aria-label="Compound first" value={String(value ?? '')} onChange={(event) => onChange(event.target.value)} />
            <input aria-label="Compound second" value={String(value ?? '')} onChange={(event) => onChange(event.target.value)} />
        </>
        const compoundDefinition: NodeTypePayload = {
            ...sendDefinition,
            fields: [{ ...sendDefinition.fields[0]!, key: 'compound', type: 'compound', label: 'Compound' }],
            default_config: { compound: '' },
        }
        renderEditor({
            palette: [compoundDefinition, exitDefinition],
            controls: { compound: Compound },
            graph: {
                ...graph,
                nodes: graph.nodes?.map((item) => item.id === 'send1' ? { ...item, config: { compound: '' } } : item),
            },
        })

        fireEvent.click(canvasNode('send1'))
        const first = screen.getByRole('textbox', { name: 'Compound first' })
        const second = screen.getByRole('textbox', { name: 'Compound second' })
        fireEvent.change(first, { target: { value: 'one' } })
        fireEvent.blur(first, { relatedTarget: second })
        fireEvent.change(second, { target: { value: 'two' } })
        fireEvent.blur(second, { relatedTarget: document.body })
        fireEvent.keyDown(document, { key: 'z', ctrlKey: true })

        expect(screen.getByRole('textbox', { name: 'Compound first' })).toHaveValue('')
        expect(screen.getByRole('textbox', { name: 'Compound second' })).toHaveValue('')
    })

    // Host node renderers own only the body; counterfactual ignoring nodeRenderers prevents package customization.
    it('uses a host node body renderer', () => {
        renderEditor({
            graph: {
                ...graph,
                nodes: [
                    ...(graph.nodes ?? []),
                    { id: 'constructor', type: 'toString', config: {}, position: { x: 600, y: 0 } },
                ],
            },
            nodeRenderers: { 'app.send': ({ data }) => <p>host node body: {data.id}</p> },
        })
        expect(screen.getByText('host node body: send1')).toBeInTheDocument()
        expect(within(canvasNode('constructor')).getByText(/Unknown node type “toString”/)).toBeInTheDocument()
    })

    // Dynamic option failure stays named beside the field; counterfactual empty select looks like a valid empty registry.
    it('shows a named dynamic options HTTP failure in the selected panel', async () => {
        const dynamicSend: NodeTypePayload = {
            ...sendDefinition,
            fields: [{ ...sendDefinition.fields[0]!, type: 'select', dynamic_options: true }],
        }
        vi.stubGlobal('fetch', vi.fn(async (url: string) => url.startsWith('/options/')
            ? Response.json({}, { status: 500 })
            : Response.json({ draft_revision: 8 })))
        renderEditor({ palette: [dynamicSend, exitDefinition] })
        fireEvent.click(canvasNode('send1'))
        expect(await screen.findByText(/Could not load.*HTTP 500/i)).toBeInTheDocument()
    })

    it('adds one trigger and requires an accessible confirmation before preserving its target during replacement', async () => {
        const user = userEvent.setup()
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({
            graph: triggeredGraph,
            trigger_nodes: [webhookTrigger, eventTrigger],
            trigger_sources: authorableSources,
        })

        await user.click(screen.getByRole('button', { name: 'Add Laravel event' }))
        expect(screen.getByRole('dialog', { name: 'Replace trigger' })).toBeInTheDocument()
        expect(canvasProbe.current?.nodes.filter((item) => item.data.kind === 'trigger')).toHaveLength(1)
        await user.click(screen.getByRole('button', { name: 'Cancel' }))
        expect(canvasProbe.current?.nodes.find((item) => item.id === 'trigger')?.data.type).toBe(webhookTrigger.type)

        await user.click(screen.getByRole('button', { name: 'Add Laravel event' }))
        await user.click(screen.getByRole('button', { name: 'Replace trigger' }))
        expect(canvasProbe.current?.nodes.filter((item) => item.data.kind === 'trigger')).toHaveLength(1)
        expect(canvasProbe.current?.nodes.find((item) => item.id === 'trigger')?.data.type).toBe(eventTrigger.type)
        expect(canvasProbe.current?.edges).toEqual(expect.arrayContaining([
            expect.objectContaining({ source: 'trigger', sourceHandle: 'started', target: 'send1' }),
        ]))
    })

    it('contains replacement Escape inside the narrow library drawer', async () => {
        const user = userEvent.setup()
        installMediaQuery(true)
        renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger, eventTrigger], trigger_sources: authorableSources })
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Inspector' })).toBeInTheDocument())
        await user.click(screen.getByRole('button', { name: 'Open Node Library' }))
        const drawer = await screen.findByRole('dialog', { name: 'Node Library' })
        const opener = within(drawer).getByRole('button', { name: 'Add Laravel event' })
        await user.click(opener)
        const confirm = screen.getByRole('button', { name: 'Replace trigger' })
        const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })

        act(() => { confirm.dispatchEvent(escape) })

        expect(escape.defaultPrevented).toBe(true)
        expect(screen.queryByRole('dialog', { name: 'Replace trigger' })).toBeNull()
        expect(screen.getByRole('dialog', { name: 'Node Library' })).toBeInTheDocument()
        expect(opener).toHaveFocus()

        fireEvent.keyDown(document, { key: 'Escape' })
        expect(screen.queryByRole('dialog', { name: 'Node Library' })).toBeNull()
    })

    it('deletes a trigger through the inspector and clears graph start', async () => {
        renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger], trigger_sources: authorableSources })
        fireEvent.click(canvasNode('trigger'))
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))

        expect(document.querySelector('.react-flow__node[data-id="trigger"]')).toBeNull()
        expect(screen.getByText(/Start: none/i)).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
    })

    it('disables publish with a direct readiness reason for a missing trigger or incompatible source', () => {
        const first = renderEditor({
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
        })
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Publish' })).toHaveAttribute('title', expect.stringMatching(/add a trigger/i))
        first.unmount()

        const incompatible = renderEditor({
            graph: { ...triggeredGraph, nodes: triggeredGraph.nodes?.map((item) => item.id === 'trigger' ? { ...item, config: { source: 'missing' } } : item) },
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
        })
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
        expect(screen.getByText(/selected trigger source is not compatible/i)).toBeInTheDocument()
        incompatible.unmount()

        const unavailable = renderEditor({ trigger_nodes: [{ ...webhookTrigger, compatible_source_keys: [] }], trigger_sources: { webhook: [] } })
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Publish' })).toHaveAttribute('title', expect.stringMatching(/no compatible trigger source/i))
        unavailable.unmount()
    })

    it('selects the trigger and literal source field from a structured publish issue', async () => {
        const user = userEvent.setup()
        vi.stubGlobal('fetch', vi.fn(async (url: string) => url === urls.publish
            ? Response.json({
                errors: [],
                node_errors: [{ node: 'trigger', field: 'source', message: 'Choose an observed model.' }],
            }, { status: 422 })
            : Response.json({ draft_revision: 8 })))
        renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger], trigger_sources: authorableSources })

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        await user.click(await screen.findByRole('button', { name: 'Choose an observed model.' }))

        expect(screen.getByRole('complementary', { name: 'Node inspector' })).toHaveTextContent('Webhook')
        const fieldAlert = screen.getByRole('alert')
        expect(fieldAlert).toHaveTextContent('Choose an observed model.')
        expect(fieldAlert.closest('[data-nodeflow-field-key]')).toHaveAttribute('data-nodeflow-field-key', 'source')
    })

    it('shows webhook metadata without a secret and keeps publish credentials one-time and outside the graph', async () => {
        const user = userEvent.setup()
        const writeText = vi.spyOn(navigator.clipboard, 'writeText').mockResolvedValue()
        const fetchMock = vi.fn(async (url: string, init: RequestInit) => {
            if (url === urls.publish && init.method === 'POST') {
                return Response.json({
                    version: 4,
                    draft_revision: 8,
                    webhook_url: 'https://example.test/hooks/token',
                    webhook_secret: 'publish-secret',
                })
            }
            return Response.json({ draft_revision: 8 })
        })
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({
            graph: triggeredGraph,
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
            webhook: { endpoint_url: 'https://example.test/hooks/token', active: true, secret_rotated_at: '2026-08-24T10:00:00Z' },
        })
        fireEvent.click(canvasNode('trigger'))

        expect(screen.getByRole('link', { name: /webhook endpoint/i })).toHaveAttribute('href', 'https://example.test/hooks/token')
        expect(screen.getByText(/active/i)).toBeInTheDocument()
        expect(screen.queryByText('publish-secret')).toBeNull()
        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText('publish-secret')).toBeInTheDocument()
        expect(screen.getByRole('alert')).toHaveTextContent(/shown only once/i)
        await user.click(screen.getByRole('button', { name: 'Copy webhook secret' }))
        expect(writeText).toHaveBeenCalledWith('publish-secret')
        expect(await screen.findByText('Secret copied.')).toHaveAttribute('role', 'status')

        const everyGraph = fetchMock.mock.calls
            .filter(([, init]) => typeof init?.body === 'string')
            .map(([, init]) => String(init?.body))
            .join(' ')
        expect(everyGraph).not.toContain('publish-secret')
        await user.click(screen.getByRole('button', { name: 'Acknowledge webhook secret' }))
        expect(screen.queryByText('publish-secret')).toBeNull()
        writeText.mockRestore()
    })

    it('consumes same-session webhook credentials while suppressing a generation-stale publish outcome', async () => {
        const user = userEvent.setup()
        let resolvePublish!: (response: Response) => void
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.publish
            ? new Promise<Response>((resolve) => { resolvePublish = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        renderEditor({
            graph: triggeredGraph,
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
            webhook: { endpoint_url: 'https://example.test/hooks/previous', active: false, secret_rotated_at: '2026-08-24T12:00:00Z' },
        })

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))
        fireEvent.click(canvasNode('send1'))
        fireEvent.change(screen.getByLabelText(/Template/), { target: { value: 'edited while publishing' } })
        await act(async () => resolvePublish(Response.json({
            version: 4,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/stale-generation',
            webhook_secret: 'same-session-secret',
        })))
        fireEvent.click(canvasNode('trigger'))

        expect(await screen.findByText('same-session-secret')).toBeInTheDocument()
        expect(screen.getByRole('link', { name: /webhook endpoint/i })).toHaveAttribute('href', 'https://example.test/hooks/stale-generation')
        expect(screen.getByRole('region', { name: 'Webhook details' })).toHaveTextContent('Active')
        expect(screen.getByText('2026-08-24T12:00:00Z')).toBeInTheDocument()
        expect(screen.queryByText(/Published v4/i)).toBeNull()
        expect(screen.getByText(/Published v3/i)).toBeInTheDocument()
    })

    it('clears an older one-time secret on a later same-session success even when its generation is stale', async () => {
        const user = userEvent.setup()
        let publications = 0
        let resolveLater!: (response: Response) => void
        vi.stubGlobal('fetch', vi.fn((url: string) => {
            if (url !== urls.publish) return Promise.resolve(Response.json({ draft_revision: 8 }))
            publications += 1
            return publications === 1
                ? Promise.resolve(Response.json({
                    version: 4,
                    draft_revision: 8,
                    webhook_url: 'https://example.test/hooks/token',
                    webhook_secret: 'older-secret',
                }))
                : new Promise<Response>((resolve) => { resolveLater = resolve })
        }))
        renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger], trigger_sources: authorableSources })
        fireEvent.click(canvasNode('trigger'))

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText('older-secret')).toBeInTheDocument()
        await user.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(resolveLater).toBeTypeOf('function'))
        fireEvent.click(canvasNode('send1'))
        fireEvent.change(screen.getByLabelText(/Template/), { target: { value: 'new generation' } })
        await act(async () => resolveLater(Response.json({
            version: 5,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/token',
        })))
        fireEvent.click(canvasNode('trigger'))

        expect(screen.queryByText('older-secret')).toBeNull()
        expect(screen.queryByText(/Published v5/i)).toBeNull()
        expect(screen.getByText(/Published v4/i)).toBeInTheDocument()
    })

    it.each(['switch', 'unmount'] as const)('does not consume a webhook secret after editor %s', async (exit) => {
        const user = userEvent.setup()
        const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
        let resolvePublish!: (response: Response) => void
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.publish
            ? new Promise<Response>((resolve) => { resolvePublish = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const view = renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger], trigger_sources: authorableSources })

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))
        if (exit === 'unmount') {
            view.unmount()
        } else {
            view.rerender(
                <FlowEditor
                    flow={{ ...flow, id: 13 }}
                    graph={triggeredGraph}
                    palette={palette}
                    trigger_nodes={[webhookTrigger]}
                    trigger_sources={authorableSources}
                    webhook={null}
                    urls={{ ...urls, draft: '/flows/13/draft', publish: '/flows/13/publish', rotate_webhook_secret: '/flows/13/webhook-secret/rotate' }}
                />,
            )
        }
        await act(async () => resolvePublish(Response.json({
            version: 4,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/abandoned',
            webhook_secret: 'abandoned-secret',
        })))

        if (exit === 'switch') fireEvent.click(canvasNode('trigger'))

        expect(screen.queryByText('abandoned-secret')).toBeNull()
        expect(screen.queryByRole('link', { name: /webhook endpoint/i })).toBeNull()
        expect(consoleError.mock.calls.flat().join(' ')).not.toMatch(/unmounted|state update/i)
        consoleError.mockRestore()
    })

    it('clears a one-time webhook secret after a later successful publish and when flow identity changes', async () => {
        const user = userEvent.setup()
        let publications = 0
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            if (url.endsWith('/publish') && init.method === 'POST') {
                publications += 1
                return Response.json(publications === 1
                    ? { version: 4, draft_revision: 8, webhook_url: 'https://example.test/hooks/token', webhook_secret: 'temporary-secret' }
                    : publications === 2
                        ? { version: 5, draft_revision: 8, webhook_url: 'https://example.test/hooks/token' }
                        : { version: 6, draft_revision: 8, webhook_url: 'https://example.test/hooks/token', webhook_secret: 'flow-switch-secret' })
            }
            return Response.json({ draft_revision: 8 })
        }))
        const view = renderEditor({ graph: triggeredGraph, trigger_nodes: [webhookTrigger], trigger_sources: authorableSources })
        fireEvent.click(canvasNode('trigger'))

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText('temporary-secret')).toBeInTheDocument()
        await user.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(screen.queryByText('temporary-secret')).toBeNull())

        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText('flow-switch-secret')).toBeInTheDocument()
        view.rerender(
            <FlowEditor
                flow={{ ...flow, id: 13 }}
                graph={triggeredGraph}
                palette={palette}
                trigger_nodes={[webhookTrigger]}
                trigger_sources={authorableSources}
                webhook={null}
                urls={{ ...urls, draft: '/flows/13/draft', publish: '/flows/13/publish', rotate_webhook_secret: '/flows/13/webhook-secret/rotate' }}
            />,
        )
        expect(screen.queryByText('flow-switch-secret')).toBeNull()
    })

    it('rotates a webhook secret only after confirmation and safely handles failures', async () => {
        const user = userEvent.setup()
        let rotation = 0
        vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
            if (url === urls.rotate_webhook_secret && init.method === 'POST') {
                rotation += 1
                return rotation === 1
                    ? Response.json({ secret: 'rotated-secret', rotated_at: '2026-08-24T11:00:00Z' })
                    : Response.json({ message: 'internal secret material must not render' }, { status: 500 })
            }
            return Response.json({ draft_revision: 8 })
        }))
        renderEditor({
            graph: triggeredGraph,
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
            webhook: { endpoint_url: 'https://example.test/hooks/token', active: true, secret_rotated_at: null },
        })
        fireEvent.click(canvasNode('trigger'))

        await user.click(screen.getByRole('button', { name: 'Rotate webhook secret' }))
        expect(screen.getByRole('dialog', { name: 'Rotate webhook secret' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Confirm rotation' })).toHaveFocus()
        await user.tab()
        expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
        await user.tab({ shift: true })
        expect(screen.getByRole('button', { name: 'Confirm rotation' })).toHaveFocus()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(screen.queryByRole('dialog', { name: 'Rotate webhook secret' })).toBeNull()
        expect(screen.getByRole('button', { name: 'Rotate webhook secret' })).toHaveFocus()

        await user.click(screen.getByRole('button', { name: 'Rotate webhook secret' }))
        await user.click(screen.getByRole('button', { name: 'Cancel' }))
        expect(rotation).toBe(0)

        await user.click(screen.getByRole('button', { name: 'Rotate webhook secret' }))
        await user.click(screen.getByRole('button', { name: 'Confirm rotation' }))
        expect(await screen.findByText('rotated-secret')).toBeInTheDocument()
        expect(screen.getByText(/2026-08-24T11:00:00Z/)).toBeInTheDocument()

        await user.click(screen.getByRole('button', { name: 'Rotate webhook secret' }))
        await user.click(screen.getByRole('button', { name: 'Confirm rotation' }))
        expect(await screen.findByRole('alert', { name: 'Webhook rotation error' })).toHaveTextContent(/could not rotate/i)
        expect(screen.queryByText(/internal secret material/i)).toBeNull()
        expect(screen.getByText('rotated-secret')).toBeInTheDocument()
    })

    it('contains rotation Escape inside the narrow inspector drawer', async () => {
        const user = userEvent.setup()
        installMediaQuery(true)
        renderEditor({
            graph: triggeredGraph,
            trigger_nodes: [webhookTrigger],
            trigger_sources: authorableSources,
            webhook: { endpoint_url: 'https://example.test/hooks/token', active: true, secret_rotated_at: null },
        })
        const drawer = await screen.findByRole('dialog', { name: 'Inspector' })
        fireEvent.click(canvasNode('trigger'))
        const opener = await within(drawer).findByRole('button', { name: 'Rotate webhook secret' })
        await user.click(opener)
        const confirm = screen.getByRole('button', { name: 'Confirm rotation' })
        const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })

        act(() => { confirm.dispatchEvent(escape) })

        expect(escape.defaultPrevented).toBe(true)
        expect(screen.queryByRole('dialog', { name: 'Rotate webhook secret' })).toBeNull()
        expect(screen.getByRole('dialog', { name: 'Inspector' })).toBeInTheDocument()
        expect(opener).toHaveFocus()

        fireEvent.keyDown(document, { key: 'Escape' })
        expect(screen.queryByRole('dialog', { name: 'Inspector' })).toBeNull()
    })

    // The composed surface keeps validation separate from saving/publishing while preserving the author journey.
    it('adds, configures, validates, and publishes a workflow through accessible Studio controls', async () => {
        const user = userEvent.setup()
        const validateUrl = '/flows/12/validate'
        vi.stubGlobal('fetch', vi.fn(async (url: string) => {
            if (url === validateUrl) return Response.json({ valid: true, warnings: [] })
            if (url === urls.publish) return Response.json({ version: 4, draft_revision: 8 })
            return Response.json({ draft_revision: 8 })
        }))
        renderEditor({ graph: { start: null, nodes: [], edges: [] }, urls: { ...urls, validate: validateUrl } })

        await user.click(screen.getByRole('button', { name: 'Add Send message' }))
        await user.click(screen.getByRole('tab', { name: 'Configure' }))
        await user.type(await screen.findByLabelText(/Template/), 'welcome')
        await user.click(screen.getByRole('button', { name: 'Validate flow' }))
        expect((await screen.findAllByText('Ready to publish')).length).toBeGreaterThan(0)
        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect((await screen.findAllByText(/Published v4/)).length).toBeGreaterThan(0)
    })
})
