import { ReactFlowProvider, type NodeProps } from '@xyflow/react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { Canvas, canvasBehavior, interactionProps, type NodeflowNode } from './Canvas'
import { CanvasContext } from './context'
import { defaultNodeRenderer, NodeCard, rendererFor } from './NodeCard'

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
    source: 'n1',
    sourceHandle: 'sent',
    target: 'n2',
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
    // 5.8 all four; counterfactual read label only loses icon/group/description.
    it('reads icon, label, group and description from the definition', () => {
        render(
            defaultNodeRenderer({
                data,
                def: def({ icon: '✉' }),
                selected: false,
                errors: [],
            }),
        )
        expect(screen.getByText('✉')).toBeInTheDocument()
        expect(screen.getByText('Send message')).toBeInTheDocument()
        expect(screen.getByText('Messaging')).toBeInTheDocument()
        expect(screen.getByText('Sends one message')).toBeInTheDocument()
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

    // Counterfactual drop isStart and author cannot see graph entry.
    it('marks the start node', () => {
        render(
            defaultNodeRenderer({
                data,
                def: def(),
                selected: false,
                errors: [],
            }),
        )
        expect(screen.getByText('START')).toBeInTheDocument()
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
                    value={{ defs: inheritedDefs, renderers: inheritedRenderers, nodeErrors: inheritedErrors }}
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
})
