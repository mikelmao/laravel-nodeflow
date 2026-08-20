import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { FieldControlProps } from '../controls/types'
import type { Graph, NodeTypePayload } from '../graph/types'
import { FlowEditor } from './FlowEditor'

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
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: 8 })))
})

describe('FlowEditor', () => {
    // Trigger metadata is server-authored and read-only; counterfactual showing only the key hides author guidance.
    it('names the flow and describes its selected trigger', () => {
        renderEditor()
        expect(screen.getByText('Welcome journey')).toBeInTheDocument()
        expect(screen.getByText('Order placed')).toBeInTheDocument()
        expect(screen.getByText('When a customer places an order.')).toBeInTheDocument()
    })

    // Published version is durable state; counterfactual reporting draft revision confuses concurrency with releases.
    it('reports the published version', () => {
        renderEditor()
        expect(screen.getByText(/published v3/i)).toBeInTheDocument()
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
        expect(await screen.findByText('Node [send1] field [template]: required')).toBeInTheDocument()
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
        expect(await screen.findByText('Node [ghost] is invalid.')).toBeInTheDocument()
    })

    // Structural 422 means the client broke the wire shape; counterfactual presenting it as author validation misdiagnoses it.
    it('labels and flattens structural validation as an editor bug', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({
            errors: { 'graph.nodes.0.id': ['The graph.nodes.0.id field is required.'] },
        }, { status: 422 })))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText(/editor sent a graph the server could not read/i)).toBeInTheDocument()
        expect(screen.getByText('graph.nodes.0.id: The graph.nodes.0.id field is required.')).toBeInTheDocument()
    })

    // Ambiguous outputs must block locally; counterfactual guessing an output sends a graph the author never made.
    it('refuses to publish an unresolved edge output', async () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { ...graph, edges: [{ from: 'send1', to: 'exit1', output: null }] } })
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText(/which output/i)).toBeInTheDocument()
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
        fireEvent.click(screen.getByRole('button', { name: /Exitcore\.exit/ }))
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
        fireEvent.click(screen.getByRole('button', { name: /Exitcore\.exit/ }))
        await screen.findByRole('button', { name: 'Use theirs' })
        fireEvent.click(screen.getByRole('button', { name: 'Use theirs' }))
        expect(await screen.findByText(/Start: none/i)).toBeInTheDocument()
        expect(canvasNode('exit2')).toBeInTheDocument()
        expect(canvasNode('theirs2')).toBeInTheDocument()
        expect(screen.getByText('Select a node to configure it.')).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByRole('status')).toHaveTextContent('Published v4')
        expect(requestBody(fetchMock, urls.publish)).toEqual({
            graph: {
                start: '',
                nodes: [
                    { id: 'exit2', type: 'app.send', config: {}, position: { x: 60, y: 60 } },
                    { id: 'theirs2', type: 'core.exit', config: {}, position: { x: 300, y: 60 } },
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
        fireEvent.click(screen.getByRole('button', { name: /Exitcore\.exit/ }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
        await resolveDraft(Response.json({ draft_revision: 8 }))
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({
            nodes: expect.arrayContaining([{ id: 'exit2', type: 'core.exit', config: {}, position: { x: 180, y: 160 } }]),
        })
        fireEvent.click(screen.getByRole('button', { name: /Exitcore\.exit/ }))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
        expect(requestBody(fetchMock, urls.draft, 1).draft_revision).toBe(30)
    })

    // Network rejection is recoverable UI state; counterfactual leaving the button disabled forces a reload.
    it('reports a network publish failure and re-enables publish', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('offline')))
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        expect(await screen.findByText(/Could not reach server.*offline/i)).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Publish' })).toBeEnabled()
    })

    // IDs extend the existing type sequence; counterfactual counting nodes alone can collide with send1.
    it('mints send2 when adding another Send message node', () => {
        renderEditor()
        fireEvent.click(screen.getByRole('button', { name: /Send messageapp\.send/ }))
        expect(canvasNode('send2')).toBeInTheDocument()
    })

    // Panel edits mutate graph data; counterfactual local input state publishes the stale welcome value.
    it('publishes a configuration edit on the original node', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(canvasNode('send1'))
        fireEvent.change(screen.getByLabelText(/Template/), { target: { value: 'changed' } })
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
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
        renderEditor()
        fireEvent.click(canvasNode('send1'))
        fireEvent.keyDown(document, { key: 'Delete' })
        await waitFor(() => expect(document.querySelector('.react-flow__node[data-id="send1"]')).toBeNull())
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish)).toEqual({ graph: {
            start: '',
            nodes: [{ id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }],
            edges: [],
        } })
    })

    // The first node makes an empty graph runnable; counterfactual leaving start blank creates needless invalid state.
    it('makes the first added node the start node', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor({ graph: { start: null, nodes: [], edges: [] } })
        fireEvent.click(screen.getByRole('button', { name: /Exitcore\.exit/ }))
        expect(within(canvasNode('exit1')).getByText('START')).toBeInTheDocument()
        expect(screen.getByText(/Start: exit1/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({ start: 'exit1' })
    })

    // Explicit start selection is graph state; counterfactual changing only the badge publishes the old start.
    it('makes a selected node the start node and publishes it', async () => {
        const fetchMock = successfulFetch()
        vi.stubGlobal('fetch', fetchMock)
        renderEditor()
        fireEvent.click(canvasNode('exit1'))
        fireEvent.click(screen.getByRole('button', { name: 'Make start node' }))
        expect(screen.getByText(/Start: exit1/i)).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Publish' }))
        await waitFor(() => expect(fetchMock.mock.calls.some(([url]) => url === urls.publish)).toBe(true))
        expect(requestBody(fetchMock, urls.publish).graph).toMatchObject({ start: 'exit1' })
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

    // Host node renderers own only the body; counterfactual ignoring nodeRenderers prevents package customization.
    it('uses a host node body renderer', () => {
        renderEditor({ nodeRenderers: { 'app.send': ({ data }) => <p>host node body: {data.id}</p> } })
        expect(screen.getByText('host node body: send1')).toBeInTheDocument()
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
})
