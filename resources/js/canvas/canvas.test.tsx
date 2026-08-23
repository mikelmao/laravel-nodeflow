import { Position, ReactFlowProvider, useReactFlow, useStoreApi, type NodeProps } from '@xyflow/react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useEffect, useLayoutEffect, useRef } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { Canvas, canvasBehavior, interactionProps, type NodeflowNode } from './Canvas'
import { CanvasContext } from './context'
import { defaultNodeRenderer, NodeCard, rendererFor } from './NodeCard'
import { WorkflowEdge } from './WorkflowEdge'

function def(overrides: Partial<NodeTypePayload> = {}): NodeTypePayload {
    return {
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: 'Sends one message',
        outputs: ['sent', 'failed'],
        fields: [],
        default_config: {},
        cardinality: ['subject'],
        ...overrides,
    }
}

const data: NodeCardData = {
    id: 'n1',
    type: 'app.send',
    config: { template: 'welcome' },
    isStart: true,
}
const canvasNode: CanvasNode = {
    id: 'n1',
    type: 'nodeflowNode',
    position: { x: 0, y: 0 },
    data,
}
const canvasEdge: CanvasEdge = {
    id: 'n1-sent-n2',
    type: 'nodeflowEdge',
    source: 'n1',
    sourceHandle: 'sent',
    target: 'n2',
}

function FlowInstanceProbe({ onInstance }: { onInstance: (instance: ReturnType<typeof useReactFlow>) => void }) {
    const instance = useReactFlow()

    useEffect(() => onInstance(instance), [instance, onInstance])

    return null
}

function nodeTypeTransfer(type: string | null): DataTransfer {
    return {
        getData: vi.fn((mime: string) => mime === 'application/x-nodeflow-node-type' ? type ?? '' : ''),
    } as unknown as DataTransfer
}

type CanvasActions = {
    fit: () => void
    centerNode: (id: string) => void
    screenToFlowPosition: (point: { x: number; y: number }) => { x: number; y: number }
}
const nodeProps: NodeProps<NodeflowNode> = {
    id: 'n1',
    data,
    type: 'nodeflowNode',
    selected: false,
    dragging: false,
    zIndex: 0,
    isConnectable: true,
    positionAbsoluteX: 0,
    positionAbsoluteY: 0,
    selectable: true,
    deletable: true,
    draggable: true,
}

function WorkflowEdgePortalHarness() {
    const store = useStoreApi()
    const flowRoot = useRef<HTMLDivElement>(null)

    useLayoutEffect(() => {
        store.setState({ domNode: flowRoot.current })
    }, [store])

    return (
        <div ref={flowRoot} className="react-flow">
            <svg>
                <WorkflowEdge
                    id="edge-1"
                    source="n1"
                    target="n2"
                    sourceX={0}
                    sourceY={32}
                    targetX={240}
                    targetY={32}
                    sourcePosition={Position.Right}
                    targetPosition={Position.Left}
                    label="sent"
                    style={{ stroke: 'red' }}
                    markerEnd="url(#arrow)"
                />
            </svg>
            <div className="react-flow__edgelabel-renderer" />
        </div>
    )
}

describe('rendererFor', () => {
    // Host override wins; counterfactual wrong fallback order ignores it.
    it('prefers a host renderer for that node type', () => {
        const Mine = () => null
        expect(rendererFor('app.send', { 'app.send': Mine })).toBe(Mine)
        expect(rendererFor('app.send', {})).toBe(defaultNodeRenderer)
        expect(rendererFor('__proto__', {})).toBe(defaultNodeRenderer)
        expect(rendererFor('constructor', {})).toBe(defaultNodeRenderer)
        expect(rendererFor('toString', Object.create({ toString: Mine }))).toBe(defaultNodeRenderer)
        expect(rendererFor('__proto__', { ['__proto__']: Mine })).toBe(Mine)
        expect(rendererFor('constructor', { constructor: Mine })).toBe(Mine)
        expect(rendererFor('toString', { toString: Mine })).toBe(Mine)
    })
})

describe('defaultNodeRenderer', () => {
    it('renders exactly one concise summary without repeating the card header', () => {
        render(
            defaultNodeRenderer({
                data,
                def: def({ fields: [{ key: 'template', type: 'text', label: 'Template', help: null, default: null, required: true, options: {}, dynamic_options: false }] }),
                selected: false,
                errors: [],
            }),
        )
        expect(screen.getByText('Template: welcome')).toBeInTheDocument()
        expect(screen.queryByText('Send message')).toBeNull()
        expect(screen.queryByText('n1')).toBeNull()
        expect(screen.queryByText('app.send')).toBeNull()
    })

    // Draft unknown legal; counterfactual undefined def renders empty undiagnosable card.
    it('names an unregistered node type instead of rendering an empty card', () => {
        render(
            defaultNodeRenderer({
                data: { ...data, type: 'not.registered' },
                def: undefined,
                selected: false,
                errors: [],
            }),
        )
        expect(screen.getByRole('alert').textContent).toContain('not.registered')
    })

})

describe('NodeCard', () => {
    // Handles belong to NodeCard. Counterfactual move to default renderer and
    // host override loses wiring.
    it('draws one source handle per declared output even under a host renderer', () => {
        const Mine = () => <p>mine</p>
        const { container } = render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{
                        defs: { 'app.send': def() },
                        renderers: { 'app.send': Mine },
                        nodeErrors: {},
                        decorations: {},
                    }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByText('mine')).toBeInTheDocument()
        expect(container.querySelectorAll('[data-handleid="sent"]')).toHaveLength(1)
        expect(container.querySelectorAll('[data-handleid="failed"]')).toHaveLength(1)
        expect(container.querySelectorAll('.react-flow__handle-left')).toHaveLength(1)
        expect(screen.getByLabelText('Outputs')).toHaveTextContent('sent')
        expect(screen.getByLabelText('Outputs')).toHaveTextContent('failed')
    })

    // Per-node errors mandatory and NodeCard owns list even if host renderer ignores errors.
    // Counterfactual ignore nodeErrors and errors exist only in banner.
    it('renders the errors recorded against its own id and no others', () => {
        const Mine = () => <p>host body</p>
        const view = render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{
                        defs: { 'app.send': def() },
                        renderers: { 'app.send': Mine },
                        nodeErrors: { n1: ['field [template]: required'], n2: ['not mine'] },
                        decorations: {},
                    }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByRole('alert').textContent).toContain('field [template]: required')
        expect(screen.queryByText('not mine')).toBeNull()

        const inheritedDefs = Object.create({ toString: def({ type: 'toString', label: 'inherited definition' }) })
        const inheritedRenderers = Object.create({ toString: () => <p>inherited renderer</p> })
        const inheritedErrors = Object.create({ constructor: ['inherited error'] })
        view.rerender(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{
                        defs: inheritedDefs,
                        renderers: inheritedRenderers,
                        nodeErrors: inheritedErrors,
                        decorations: {},
                    }}
                >
                    <NodeCard
                        {...nodeProps}
                        id="constructor"
                        data={{ ...data, id: 'constructor', type: 'toString' }}
                    />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByRole('alert')).toHaveTextContent('toString')
        expect(screen.queryByText('inherited definition')).toBeNull()
        expect(screen.queryByText('inherited renderer')).toBeNull()
        expect(screen.queryByText('inherited error')).toBeNull()
    })

    // E16: the run view's counts reach the shared card as data. Counterfactual:
    // render badges from a run-specific context inside NodeCard and the editor
    // imports run vocabulary it has no use for.
    it('renders decoration badges and dims a node when told to', () => {
        render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{
                        defs: { 'app.send': def() },
                        renderers: {},
                        nodeErrors: {},
                        decorations: {
                            n1: { dimmed: false, badges: [{ key: 'out:sent', label: 'sent', value: 7 }] },
                        },
                    }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByTestId('nodeflow-badges-n1')).toHaveTextContent('sent 7')
    })

    // The editor passes no decorations at all. Counterfactual: default the
    // prop to a dimmed shape, or render an empty badge list unconditionally,
    // and every editor card grows an empty row or fades.
    it('renders no badge row and no dimming when there is no decoration', () => {
        render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{ defs: { 'app.send': def() }, renderers: {}, nodeErrors: {}, decorations: {} }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.queryByTestId('nodeflow-badges-n1')).toBeNull()
        expect(screen.getByText('Send message').closest('article')).not.toHaveClass('opacity-40')
    })

    it('owns the labelled card header, issue badges, body, and output rows', () => {
        const { container } = render(
            <ReactFlowProvider>
                <CanvasContext.Provider
                    value={{
                        defs: { 'app.send': def({ icon: '📨', fields: [{ key: 'template', type: 'text', label: 'Template', help: null, default: null, required: true, options: {}, dynamic_options: false }] }) },
                        renderers: {},
                        nodeErrors: { n1: ['Template is required'] },
                        decorations: {},
                    }}
                >
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        const card = screen.getByRole('article', { name: 'Send message' })
        expect(card).toHaveStyle({ width: '256px' })
        expect(screen.getByText('📨')).toBeInTheDocument()
        expect(screen.getByText('START')).toBeInTheDocument()
        expect(screen.getByText('ISSUE')).toBeInTheDocument()
        expect(screen.getByText('Template: welcome')).toBeInTheDocument()
        expect(card).not.toHaveTextContent('n1')
        expect(card).not.toHaveTextContent('app.send')

        const outputRows = screen.getByLabelText('Outputs').querySelectorAll('[data-output-row]')
        expect(outputRows).toHaveLength(2)
        expect(outputRows[0]?.querySelector('[data-handleid="sent"]')).not.toBeNull()
        expect(outputRows[1]?.querySelector('[data-handleid="failed"]')).not.toBeNull()
        expect(container.querySelectorAll('[data-handleid="sent"]')).toHaveLength(1)
    })

    it('keeps a host body without duplicating package summary or header text', () => {
        const Mine = () => <p>host-specific content</p>
        render(
            <ReactFlowProvider>
                <CanvasContext.Provider value={{ defs: { 'app.send': def() }, renderers: { 'app.send': Mine }, nodeErrors: {}, decorations: {} }}>
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        expect(screen.getByText('host-specific content')).toBeInTheDocument()
        expect(screen.getAllByText('Send message')).toHaveLength(1)
        expect(screen.queryByText('Sends one message')).toBeNull()
    })

    it('centers each source handle in its own fixed-height output row when the host body is empty', () => {
        const EmptyBody = () => null
        render(
            <ReactFlowProvider>
                <CanvasContext.Provider value={{ defs: { 'app.send': def() }, renderers: { 'app.send': EmptyBody }, nodeErrors: {}, decorations: {} }}>
                    <NodeCard {...nodeProps} />
                </CanvasContext.Provider>
            </ReactFlowProvider>,
        )

        const rows = screen.getByLabelText('Outputs').querySelectorAll<HTMLElement>('[data-output-row]')
        expect(rows).toHaveLength(2)
        for (const row of rows) {
            const handle = row.querySelector<HTMLElement>('.react-flow__handle-right')
            expect(row).toHaveClass('relative', 'h-7')
            expect(handle?.parentElement).toBe(row)
            expect(handle).toHaveStyle({ top: '50%', transform: 'translate(50%, -50%)' })
        }
    })
})

describe('WorkflowEdge', () => {
    it('renders a smooth-step path and non-interactive output chip through the React Flow edge portal', async () => {
        const { container } = render(
            <ReactFlowProvider><WorkflowEdgePortalHarness /></ReactFlowProvider>,
        )

        await waitFor(() => expect(container.querySelector('path.react-flow__edge-path')).not.toBeNull())
        expect(container.querySelector('path.react-flow__edge-path')).toHaveAttribute('d', expect.stringContaining('M'))
        expect(container.querySelector('path.react-flow__edge-path')).toHaveAttribute('marker-end', 'url(#arrow)')
        const chip = screen.getByLabelText('Connection output: sent')
        expect(chip).toHaveTextContent('sent')
        expect(chip).toHaveClass('rounded', 'pointer-events-none', 'nodrag', 'nopan')
        expect(chip.getAttribute('style')).toContain('translate(-50%, -100%) translate(')
    })
})

describe('interactionProps', () => {
    // Global false flags alone leave keyboard/select/delete. Counterfactual
    // omit any and the run graph acts editable or retains transient editor state.
    it('turns every graph mutation and selection affordance off for a read-only canvas', () => {
        expect(interactionProps(false)).toEqual({
            nodesDraggable: false,
            nodesConnectable: false,
            nodesFocusable: false,
            edgesFocusable: false,
            elementsSelectable: false,
            edgesReconnectable: false,
            deleteKeyCode: null,
            disableKeyboardA11y: true,
        })
        const behavior = canvasBehavior(
            false,
            [
                {
                    ...canvasNode,
                    selected: true,
                    dragging: true,
                    draggable: true,
                    selectable: true,
                    deletable: true,
                    focusable: true,
                    connectable: true,
                },
            ],
            [
                {
                    ...canvasEdge,
                    selected: true,
                    selectable: true,
                    deletable: true,
                    focusable: true,
                    reconnectable: true,
                },
            ],
            {
                onNodesChange: vi.fn(),
                onEdgesChange: vi.fn(),
                onConnect: vi.fn(),
            },
        )

        expect(behavior.nodes[0]).toMatchObject({
            selected: false,
            dragging: false,
            draggable: false,
            selectable: false,
            deletable: false,
            focusable: false,
            connectable: false,
        })
        expect(behavior.edges[0]).toMatchObject({
            selected: false,
            selectable: false,
            deletable: false,
            focusable: false,
            reconnectable: false,
        })
        expect(behavior.onNodesChange).toBeUndefined()
        expect(behavior.onEdgesChange).toBeUndefined()
        expect(behavior.onConnect).toBeUndefined()
    })
})

describe('Canvas', () => {
    // Mount smoke only; jsdom cannot measure layout. Counterfactual forget
    // nodeTypes registration, replaceable fixed shims, stable empty context
    // values, or the click bridge and the label disappears or an unchanged host
    // body rerenders.
    it('mounts and renders a node through the registered node type', () => {
        const first = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} />)

        expect(screen.getByText('Send message')).toBeInTheDocument()
        expect(Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')).toMatchObject({
            configurable: true,
        })
        expect(Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetWidth')).toMatchObject({
            configurable: true,
        })
        first.unmount()

        const HostRenderer = vi.fn(() => <p>stable host</p>)
        const stableNodes = [canvasNode]
        const stableEdges: CanvasEdge[] = []
        const stableDefs = { 'app.send': def() }
        const stableRenderers = { 'app.send': HostRenderer }
        const second = render(
            <Canvas nodes={stableNodes} edges={stableEdges} defs={stableDefs} renderers={stableRenderers} />,
        )
        expect(screen.getByText('stable host')).toBeInTheDocument()

        const callCount = HostRenderer.mock.calls.length

        second.rerender(
            <Canvas nodes={stableNodes} edges={stableEdges} defs={stableDefs} renderers={stableRenderers} />,
        )

        expect(HostRenderer).toHaveBeenCalledTimes(callCount)
    })

    // Per-node flags override globals/Handle default. Counterfactual only global policy leaves frozen run permissive.
    it('applies read-only mode to the mounted nodes, handles, callbacks and keyboard path', () => {
        const onNodesChange = vi.fn()
        const onEdgesChange = vi.fn()
        const onConnect = vi.fn()
        const { container } = render(
            <Canvas
                interactive={false}
                nodes={[
                    {
                        id: 'n1',
                        type: 'nodeflowNode',
                        position: { x: 0, y: 0 },
                        data,
                        selected: true,
                        dragging: true,
                        draggable: true,
                        selectable: true,
                        deletable: true,
                        focusable: true,
                        connectable: true,
                    },
                ]}
                edges={[]}
                defs={{ 'app.send': def() }}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onConnect={onConnect}
            />,
        )

        expect(screen.getByTestId('rf__node-n1')).not.toHaveAttribute('tabindex')
        expect(screen.getByTestId('rf__node-n1')).not.toHaveClass('selected')
        expect(screen.getByTestId('rf__node-n1')).not.toHaveClass('dragging')

        for (const handle of container.querySelectorAll('.react-flow__handle')) {
            expect(handle).not.toHaveClass('connectable')
        }

        fireEvent.keyDown(document, { key: 'Delete' })

        expect(onNodesChange).not.toHaveBeenCalled()
        expect(onEdgesChange).not.toHaveBeenCalled()
        expect(onConnect).not.toHaveBeenCalled()
    })

    it('routes pane clicks without treating a node or edge click as a pane click', async () => {
        const onPaneClick = vi.fn()
        const onEdgeClick = vi.fn()
        const { container } = render(
            <Canvas
                nodes={[canvasNode]}
                edges={[canvasEdge]}
                defs={{ 'app.send': def() }}
                onPaneClick={onPaneClick}
                onEdgeClick={onEdgeClick}
            />,
        )

        await waitFor(() => expect(container.querySelector('.react-flow__edge')).not.toBeNull())
        expect(screen.getByLabelText('Connection output: sent')).toBeInTheDocument()
        fireEvent.click(screen.getByTestId('rf__node-n1'))
        fireEvent.click(container.querySelector('.react-flow__edge')!)

        expect(onPaneClick).not.toHaveBeenCalled()
        expect(onEdgeClick).toHaveBeenCalledOnce()
        expect(onEdgeClick).toHaveBeenCalledWith('n1-sent-n2')

        fireEvent.click(container.querySelector('.react-flow__pane')!)
        expect(onPaneClick).toHaveBeenCalledOnce()
    })

    it('converts only an exact node-type drag payload to a flow position', async () => {
        const onDropNodeType = vi.fn()
        let instance: ReturnType<typeof useReactFlow> | null = null
        const { container } = render(
            <ReactFlowProvider>
                <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onDropNodeType={onDropNodeType} />
                <FlowInstanceProbe onInstance={(value) => { instance = value }} />
            </ReactFlowProvider>,
        )
        const pane = container.querySelector('.react-flow__pane')!
        const dataTransfer = nodeTypeTransfer('app.send')
        await waitFor(() => expect(instance).not.toBeNull())
        const screenToFlowPosition = vi.spyOn(instance!, 'screenToFlowPosition').mockReturnValue({ x: 11, y: 12 })

        expect(fireEvent.dragOver(pane, { dataTransfer })).toBe(false)
        expect(fireEvent.drop(pane, { dataTransfer, clientX: 123, clientY: 456 })).toBe(false)
        expect(screenToFlowPosition).toHaveBeenCalledWith({ x: 123, y: 456 })
        expect(onDropNodeType).toHaveBeenCalledWith('app.send', { x: 11, y: 12 })
    })

    it('rejects unsupported or empty drops and every drop in read-only mode', () => {
        const onDropNodeType = vi.fn()
        const { container } = render(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onDropNodeType={onDropNodeType} interactive={false} />,
        )
        const pane = container.querySelector('.react-flow__pane')!

        expect(fireEvent.dragOver(pane, { dataTransfer: nodeTypeTransfer(null) })).toBe(true)
        expect(fireEvent.drop(pane, { dataTransfer: nodeTypeTransfer(null), clientX: 1, clientY: 2 })).toBe(true)
        expect(fireEvent.drop(pane, { dataTransfer: nodeTypeTransfer('app.send'), clientX: 1, clientY: 2 })).toBe(true)
        expect(onDropNodeType).not.toHaveBeenCalled()
    })

    it('exposes instance-backed fit, centering, and coordinate actions once ready', async () => {
        const onReady = vi.fn()
        let instance: ReturnType<typeof useReactFlow> | null = null
        const rendered = render(
            <ReactFlowProvider>
                <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} />
                <FlowInstanceProbe onInstance={(value) => { instance = value }} />
            </ReactFlowProvider>,
        )

        await waitFor(() => expect(onReady).toHaveBeenCalledOnce())
        await waitFor(() => expect(instance).not.toBeNull())
        rendered.rerender(
            <ReactFlowProvider>
                <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} />
                <FlowInstanceProbe onInstance={(value) => { instance = value }} />
            </ReactFlowProvider>,
        )
        expect(onReady).toHaveBeenCalledOnce()
        const actions = onReady.mock.calls[0]?.[0] as CanvasActions
        const fitView = vi.spyOn(instance!, 'fitView').mockResolvedValue(true)
        const getNode = vi.spyOn(instance!, 'getNode').mockReturnValue({ position: { x: 40, y: 80 } } as never)
        const getZoom = vi.spyOn(instance!, 'getZoom').mockReturnValue(0.5)
        const setCenter = vi.spyOn(instance!, 'setCenter').mockResolvedValue(true)
        const screenToFlowPosition = vi.spyOn(instance!, 'screenToFlowPosition').mockReturnValue({ x: 8, y: 9 })

        actions.fit()
        actions.centerNode('n1')
        actions.centerNode('missing')

        expect(fitView).toHaveBeenCalledWith({ padding: 0.22, duration: 220 })
        expect(getNode).toHaveBeenNthCalledWith(1, 'n1')
        expect(setCenter).toHaveBeenCalledWith(168, 136, { zoom: 0.85, duration: 220 })
        expect(getNode).toHaveBeenLastCalledWith('missing')
        expect(actions.screenToFlowPosition({ x: 1, y: 2 })).toEqual({ x: 8, y: 9 })
        expect(screenToFlowPosition).toHaveBeenCalledWith({ x: 1, y: 2 })
    })

    it('uses zero-duration controls when the OS asks for reduced motion', async () => {
        vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })))
        const onReady = vi.fn()
        let instance: ReturnType<typeof useReactFlow> | null = null
        render(
            <ReactFlowProvider>
                <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} />
                <FlowInstanceProbe onInstance={(value) => { instance = value }} />
            </ReactFlowProvider>,
        )

        await waitFor(() => expect(onReady).toHaveBeenCalledOnce())
        const fitView = vi.spyOn(instance!, 'fitView').mockResolvedValue(true)
        ;(onReady.mock.calls[0]?.[0] as CanvasActions).fit()

        expect(fitView).toHaveBeenCalledWith({ padding: 0.22, duration: 0 })
    })

    it('renders a pannable, zoomable minimap only when explicitly requested', () => {
        const hidden = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} />)
        expect(hidden.container.querySelector('.react-flow__minimap')).toBeNull()
        hidden.unmount()

        const visible = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} showMinimap />)
        expect(visible.container.querySelector('.react-flow__minimap')).not.toBeNull()
    })

})
