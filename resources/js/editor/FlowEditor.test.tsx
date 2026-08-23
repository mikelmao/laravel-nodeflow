import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { StrictMode } from 'react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CanvasProps } from '../canvas/Canvas'
import type { FieldControlProps } from '../controls/types'
import type { Graph, NodeTypePayload } from '../graph/types'
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
    trigger_type: 'app.order_placed',
    status: 'draft',
    version: 3,
    draft_revision: 7,
    draft_updated_at: null,
}
const urls = {
    draft: '/flows/12/draft',
    publish: '/flows/12/publish',
    options: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
}
const sendDefinition: NodeTypePayload = {
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
const triggers = [{
    type: 'app.order_placed',
    label: 'Order placed',
    description: 'When a customer places an order.',
    fields: [],
}]
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
            triggers={triggers}
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

beforeEach(() => {
    canvasProbe.current = null
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 8 })))
})

describe('FlowEditor', () => {
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
        expect(screen.getByText('Order placed')).toBeInTheDocument()
        expect(screen.getByText('When a customer places an order.')).toBeInTheDocument()

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
                triggers={triggers}
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
                triggers={[...triggers]}
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
                    triggers={triggers}
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
        expect(requestBody(fetchMock, urls.publish)).toEqual({ graph: {
            start: '',
            nodes: [{ id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }],
            edges: [],
        } })
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
        expect(requestBody(fetchMock, urls.publish)).toEqual({ graph: {
            start: '',
            nodes: [{ id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }],
            edges: [],
        } })
        for (const requestUrl of [urls.draft, urls.publish]) {
            const calls = fetchMock.mock.calls.filter(([calledUrl]) => calledUrl === requestUrl)
            calls.forEach((_, occurrence) => {
                expect(requestBody(fetchMock, requestUrl, occurrence).graph).toMatchObject({ edges: [] })
            })
        }
    })

    // The first node makes an empty graph runnable; counterfactual leaving start blank creates needless invalid state.
    it('makes the first added node the start node', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { start: null, nodes: [], edges: [] } })
        fireEvent.click(screen.getByRole('button', { name: 'Add Exit' }))
        expect(within(canvasNode('exit1')).getByText('START')).toBeInTheDocument()
        expect(screen.getByText(/Start: exit1/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({ start: 'exit1' })
    })

    // Explicit start selection and blank control drafts both belong to the selected node. A field-only key leaks
    // between nodes with the same field, while a colon composite collides for [a:b, c] and [a, b:c].
    it('isolates selected node control state, makes it start and publishes it', async () => {
        const durationDefinition = (type: string, label: string, fieldKey: string): NodeTypePayload => ({
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
        fireEvent.click(screen.getByRole('button', { name: 'Make start node' }))
        expect(screen.getByText(/Start: a$/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({
            start: 'a',
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
