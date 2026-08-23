import { Position, ReactFlowProvider, useStoreApi, type NodeProps, type ReactFlowInstance } from '@xyflow/react'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { useLayoutEffect, useRef } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { Canvas, canvasActions, canvasBehavior, edgeTypes, interactionProps, prefersReducedMotion, type NodeflowEdge, type NodeflowNode } from './Canvas'
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
const canvasNodeTwo: CanvasNode = {
    id: 'n2',
    type: 'nodeflowNode',
    position: { x: 300, y: 0 },
    data: { ...data, id: 'n2', isStart: false },
}
const canvasEdge: CanvasEdge = {
    id: 'n1-sent-n2',
    type: 'nodeflowEdge',
    source: 'n1',
    sourceHandle: 'sent',
    target: 'n2',
}

function nodeTypeTransfer(type: string | null): DataTransfer {
    return {
        types: type === null ? [] : ['application/x-nodeflow-node-type'],
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

    // The editor owns deletion so its one scoped shortcut cannot race React Flow's default handler.
    it('preserves an explicit null delete key on an editable mounted canvas', () => {
        const onNodesChange = vi.fn()
        render(
            <Canvas
                deleteKeyCode={null}
                nodes={[{ ...canvasNode, selected: true, deletable: true }]}
                edges={[]}
                defs={{ 'app.send': def() }}
                onNodesChange={onNodesChange}
            />,
        )

        fireEvent.keyDown(document, { key: 'Delete' })

        expect(onNodesChange).not.toHaveBeenCalled()
    })

    it('routes pane clicks without treating a node click as a pane click', () => {
        const onPaneClick = vi.fn()
        const { container } = render(
            <Canvas
                nodes={[canvasNode]}
                edges={[]}
                defs={{ 'app.send': def() }}
                onPaneClick={onPaneClick}
            />,
        )

        fireEvent.click(screen.getByTestId('rf__node-n1'))
        expect(onPaneClick).not.toHaveBeenCalled()

        fireEvent.click(container.querySelector('.react-flow__pane')!)
        expect(onPaneClick).toHaveBeenCalledOnce()
    })

    it('routes a measured React Flow edge click only to the edge callback', async () => {
        const onPaneClick = vi.fn()
        const onEdgeClick = vi.fn()
        class ImmediateResizeObserver {
            constructor(private readonly callback: ResizeObserverCallback) {}

            observe(target: Element) {
                queueMicrotask(() => this.callback([{
                    target,
                    contentRect: { width: 208, height: 40 },
                } as ResizeObserverEntry], this as unknown as ResizeObserver))
            }

            unobserve() {}
            disconnect() {}
        }
        vi.stubGlobal('ResizeObserver', ImmediateResizeObserver)
        try {
            const { container } = render(
                <Canvas
                    nodes={[canvasNode, canvasNodeTwo]}
                    edges={[canvasEdge]}
                    defs={{ 'app.send': def() }}
                    onPaneClick={onPaneClick}
                    onEdgeClick={onEdgeClick}
                />,
            )

            await waitFor(() => expect(screen.getByTestId('rf__edge-n1-sent-n2')).toBeInTheDocument())
            fireEvent.click(screen.getByTestId('rf__edge-n1-sent-n2'))

            expect(onEdgeClick).toHaveBeenCalledOnce()
            expect(onEdgeClick).toHaveBeenCalledWith('n1-sent-n2')
            expect(onPaneClick).not.toHaveBeenCalled()

            fireEvent.click(container.querySelector('.react-flow__pane')!)
            expect(onPaneClick).toHaveBeenCalledOnce()
        } finally {
            vi.unstubAllGlobals()
        }
    })

    it('converts only an exact node-type drag payload to a flow position', async () => {
        const onDropNodeType = vi.fn()
        const onReady = vi.fn()
        const { container } = render(
            <Canvas
                nodes={[canvasNode]}
                edges={[]}
                defs={{ 'app.send': def() }}
                onDropNodeType={onDropNodeType}
                onReady={onReady}
            />,
        )
        const pane = container.querySelector('.react-flow__pane')!
        const dataTransfer = nodeTypeTransfer('app.send')
        await waitFor(() => expect(onReady).toHaveBeenCalledOnce())
        const actions = onReady.mock.calls[0]?.[0] as CanvasActions
        const expected = actions.screenToFlowPosition({ x: 123, y: 456 })

        expect(fireEvent.dragOver(pane, { dataTransfer })).toBe(false)
        const drop = new Event('drop', { bubbles: true, cancelable: true })
        Object.defineProperties(drop, {
            dataTransfer: { value: dataTransfer },
            clientX: { value: 123 },
            clientY: { value: 456 },
        })
        expect(pane.dispatchEvent(drop)).toBe(false)
        expect(onDropNodeType).toHaveBeenCalledWith('app.send', expected)
    })

    it('accepts the exact MIME during protected dragover before its payload can be read', () => {
        const onDropNodeType = vi.fn()
        const { container } = render(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onDropNodeType={onDropNodeType} />,
        )
        const protectedTransfer = {
            types: ['application/x-nodeflow-node-type'],
            getData: vi.fn(() => ''),
        } as unknown as DataTransfer

        expect(fireEvent.dragOver(container.querySelector('.react-flow__pane')!, { dataTransfer: protectedTransfer })).toBe(false)
        expect(protectedTransfer.getData).not.toHaveBeenCalled()
    })

    it('rejects unsupported or empty drops and every drop in read-only mode', () => {
        const onDropNodeType = vi.fn()
        const { container } = render(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onDropNodeType={onDropNodeType} interactive={false} />,
        )
        const pane = container.querySelector('.react-flow__pane')!
        const unsupported = {
            types: ['text/plain'],
            getData: (mime: string) => mime === 'text/plain' ? 'app.send' : '',
        } as unknown as DataTransfer

        expect(fireEvent.drop(pane, { dataTransfer: unsupported, clientX: 1, clientY: 2 })).toBe(true)
        expect(fireEvent.dragOver(pane, { dataTransfer: nodeTypeTransfer(null) })).toBe(true)
        expect(fireEvent.drop(pane, { dataTransfer: nodeTypeTransfer(null), clientX: 1, clientY: 2 })).toBe(true)
        expect(fireEvent.drop(pane, { dataTransfer: nodeTypeTransfer('app.send'), clientX: 1, clientY: 2 })).toBe(true)
        expect(onDropNodeType).not.toHaveBeenCalled()
    })

    it('exposes instance-backed fit, centering, and coordinate actions once ready', async () => {
        const onReady = vi.fn()
        const rendered = render(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} />,
        )

        await waitFor(() => expect(onReady).toHaveBeenCalledOnce())
        rendered.rerender(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} />,
        )
        expect(onReady).toHaveBeenCalledOnce()
        const replacementReady = vi.fn()
        rendered.rerender(
            <Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={replacementReady} />,
        )
        await waitFor(() => expect(replacementReady).toHaveBeenCalledOnce())
        const fitView = vi.fn()
        const getNode = vi.fn((id: string) => {
            if (id === 'n1') return { id, position: { x: 40, y: 80 } }
            if (id === 'nested') return { id, position: { x: 20, y: 30 }, parentId: 'parent' }
            if (id === 'partial') return { id, position: { x: 10, y: 20 }, parentId: 'parent' }
            return undefined
        })
        const getNodesBounds = vi.fn((nodes: Array<{ id: string }>) => {
            if (nodes[0]?.id === 'nested') return { x: 500, y: 600, width: 0, height: 0 }
            if (nodes[0]?.id === 'partial') return { x: 100, y: 200, width: 0, height: 180 }
            return { x: 40, y: 80, width: 0, height: 0 }
        })
        const getZoom = vi.fn(() => 0.5)
        const setCenter = vi.fn()
        const screenToFlowPosition = vi.fn(() => ({ x: 8, y: 9 }))
        const actions = canvasActions(
            { fitView, getNode, getNodesBounds, getZoom, setCenter, screenToFlowPosition } as unknown as ReactFlowInstance<NodeflowNode, NodeflowEdge>,
            false,
        )

        actions.fit()
        actions.centerNode('n1')
        actions.centerNode('nested')
        actions.centerNode('partial')
        actions.centerNode('missing')

        expect(fitView).toHaveBeenCalledWith({ padding: 0.22, duration: 220 })
        expect(getNode).toHaveBeenNthCalledWith(1, 'n1')
        expect(getNodesBounds).toHaveBeenCalledWith([{ id: 'n1', position: { x: 40, y: 80 } }])
        expect(setCenter).toHaveBeenCalledWith(168, 136, { zoom: 0.85, duration: 220 })
        expect(getNodesBounds).toHaveBeenCalledWith([{ id: 'nested', position: { x: 20, y: 30 }, parentId: 'parent' }])
        expect(setCenter).toHaveBeenCalledWith(628, 656, { zoom: 0.85, duration: 220 })
        expect(setCenter).toHaveBeenCalledWith(228, 290, { zoom: 0.85, duration: 220 })
        expect(getNode).toHaveBeenLastCalledWith('missing')
        expect(actions.screenToFlowPosition({ x: 1, y: 2 })).toEqual({ x: 8, y: 9 })
        expect(screenToFlowPosition).toHaveBeenCalledWith({ x: 1, y: 2 })
        const wrapper = document.createElement('div')
        vi.spyOn(wrapper, 'getBoundingClientRect').mockReturnValue({ x: 40, y: 60, left: 40, top: 60, right: 440, bottom: 260, width: 400, height: 200, toJSON: () => ({}) })
        const viewportActions = canvasActions(
            { screenToFlowPosition } as unknown as ReactFlowInstance<NodeflowNode, NodeflowEdge>,
            false,
            wrapper,
        )
        viewportActions.viewportCenter()
        expect(screenToFlowPosition).toHaveBeenLastCalledWith({ x: 240, y: 160 })
    })

    it('disposes the same registered canvas actions on unmount', async () => {
        const onReady = vi.fn()
        const onDispose = vi.fn()
        const rendered = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} onReady={onReady} onDispose={onDispose} />)

        await waitFor(() => expect(onReady).toHaveBeenCalledOnce())
        rendered.unmount()
        expect(onDispose).toHaveBeenCalledWith(onReady.mock.calls[0]?.[0])
    })

    it('uses zero-duration controls when the OS asks for reduced motion', async () => {
        const fitView = vi.fn()
        canvasActions({ fitView } as unknown as ReactFlowInstance<NodeflowNode, NodeflowEdge>, true).fit()

        expect(fitView).toHaveBeenCalledWith({ padding: 0.22, duration: 0 })
    })

    it('detects reduced motion without requiring browser globals during SSR', () => {
        vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })))
        expect(prefersReducedMotion()).toBe(true)
        vi.unstubAllGlobals()

        vi.stubGlobal('window', undefined)
        expect(prefersReducedMotion()).toBe(false)
        vi.unstubAllGlobals()
    })

    it('renders a pannable, zoomable minimap only when explicitly requested', () => {
        const hidden = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} />)
        expect(hidden.container.querySelector('.react-flow__minimap')).toBeNull()
        hidden.unmount()

        const visible = render(<Canvas nodes={[canvasNode]} edges={[]} defs={{ 'app.send': def() }} showMinimap />)
        expect(visible.container.querySelector('.react-flow__minimap')).not.toBeNull()
        expect(visible.container.querySelector('.react-flow__minimap')).toHaveClass('border', 'border-border', 'bg-background')
        expect(visible.container.querySelector('.react-flow__minimap')).toHaveStyle({
            background: 'hsl(var(--background))',
        })
    })

    it('registers the workflow edge renderer at module scope', () => {
        expect(edgeTypes.nodeflowEdge).toBe(WorkflowEdge)
    })

})
