import {
    Background,
    Controls,
    MiniMap,
    ReactFlow,
    type Connection,
    type Edge,
    type EdgeTypes,
    type Node,
    type NodeMouseHandler,
    type NodeTypes,
    type OnEdgesChange,
    type OnNodesChange,
    type ReactFlowInstance,
    type ReactFlowProps,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useCallback, useEffect, useMemo, useState, type DragEvent, type MouseEvent } from 'react'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { CanvasContext, type NodeDecorationMap, type NodeRendererMap } from './context'
import { NODE_MIN_HEIGHT, NODE_WIDTH } from './layout'
import { NodeCard } from './NodeCard'
import { WorkflowEdge } from './WorkflowEdge'

// The graph module stays free of xyflow. These intersections make adapter drift
// fail the compiler without an unknown or as-unknown boundary cast.
export type NodeflowNode = CanvasNode & Node<NodeCardData, 'nodeflowNode'>
export type NodeflowEdge = CanvasEdge & Edge

export type CanvasActions = {
    fit: () => void
    centerNode: (id: string) => void
    screenToFlowPosition: (point: { x: number; y: number }) => { x: number; y: number }
}

export type CanvasProps = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    defs: Record<string, NodeTypePayload>
    renderers?: NodeRendererMap
    nodeErrors?: Record<string, string[]>
    /** Per-node badges and dimming. The editor passes none; the run view does. */
    nodeDecorations?: NodeDecorationMap
    onNodesChange?: OnNodesChange<NodeflowNode>
    onEdgesChange?: OnEdgesChange<NodeflowEdge>
    onConnect?: (connection: Connection) => void
    onNodeClick?: (id: string) => void
    onPaneClick?: () => void
    onEdgeClick?: (id: string) => void
    onDropNodeType?: (type: string, position: { x: number; y: number }) => void
    onReady?: (actions: CanvasActions) => void
    showMinimap?: boolean
    /** False freezes every mutation, selection, focus, and keyboard path for run views. */
    interactive?: boolean
    className?: string
}

// Keeping nodeTypes at module scope avoids React Flow remount warnings.
const nodeTypes = { nodeflowNode: NodeCard } satisfies NodeTypes
export const edgeTypes = { nodeflowEdge: WorkflowEdge } satisfies EdgeTypes
const EMPTY_RENDERERS: NodeRendererMap = Object.freeze({})
const EMPTY_NODE_ERRORS: Record<string, string[]> = Object.freeze({})
const EMPTY_DECORATIONS: NodeDecorationMap = Object.freeze({})
const NODE_TYPE_MIME = 'application/x-nodeflow-node-type'

export function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined'
        && typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches
}

export function canvasActions(
    instance: ReactFlowInstance<NodeflowNode, NodeflowEdge>,
    reducedMotion: boolean,
): CanvasActions {
    const duration = reducedMotion ? 0 : 220

    return {
        fit: () => void instance.fitView({ padding: 0.22, duration }),
        centerNode: (id) => {
            const node = instance.getNode(id)

            if (node !== undefined) {
                void instance.setCenter(
                    node.position.x + NODE_WIDTH / 2,
                    node.position.y + NODE_MIN_HEIGHT / 2,
                    { zoom: Math.max(instance.getZoom(), 0.85), duration },
                )
            }
        },
        screenToFlowPosition: (point) => instance.screenToFlowPosition(point),
    }
}

export function notifyEdgeClick(onEdgeClick: CanvasProps['onEdgeClick'], edge: NodeflowEdge): void {
    onEdgeClick?.(edge.id)
}

type InteractionProps = Pick<
    ReactFlowProps<NodeflowNode, NodeflowEdge>,
    | 'nodesDraggable'
    | 'nodesConnectable'
    | 'nodesFocusable'
    | 'edgesFocusable'
    | 'elementsSelectable'
    | 'edgesReconnectable'
    | 'deleteKeyCode'
    | 'disableKeyboardA11y'
>

export function interactionProps(interactive: boolean): InteractionProps {
    return {
        nodesDraggable: interactive,
        nodesConnectable: interactive,
        nodesFocusable: interactive,
        edgesFocusable: interactive,
        elementsSelectable: interactive,
        edgesReconnectable: interactive,
        deleteKeyCode: interactive ? ['Backspace', 'Delete'] : null,
        disableKeyboardA11y: !interactive,
    }
}

// Element flags override global React Flow flags, so read-only mode clears both.
function readOnlyNodes(nodes: NodeflowNode[]): NodeflowNode[] {
    return nodes.map((node) => ({
        ...node,
        selected: false,
        dragging: false,
        draggable: false,
        selectable: false,
        deletable: false,
        focusable: false,
        connectable: false,
    }))
}

function readOnlyEdges(edges: NodeflowEdge[]): NodeflowEdge[] {
    return edges.map((edge) => ({
        ...edge,
        selected: false,
        selectable: false,
        deletable: false,
        focusable: false,
        reconnectable: false,
    }))
}

type MutationCallbacks = Pick<CanvasProps, 'onNodesChange' | 'onEdgesChange' | 'onConnect'>

export function canvasBehavior(
    interactive: boolean,
    nodes: NodeflowNode[],
    edges: NodeflowEdge[],
    callbacks: MutationCallbacks,
): { nodes: NodeflowNode[]; edges: NodeflowEdge[] } & MutationCallbacks {
    if (interactive) {
        return { nodes, edges, ...callbacks }
    }

    return {
        nodes: readOnlyNodes(nodes),
        edges: readOnlyEdges(edges),
        onNodesChange: undefined,
        onEdgesChange: undefined,
        onConnect: undefined,
    }
}

/**
 * Canvas owns no graph state, so the editor and Plan 4 run view can share it.
 * The default class supplies a real parent height because xyflow cannot lay out
 * inside a heightless container. Its stylesheet is imported at this boundary.
 */
export function Canvas({
    nodes,
    edges,
    defs,
    renderers = EMPTY_RENDERERS,
    nodeErrors = EMPTY_NODE_ERRORS,
    nodeDecorations = EMPTY_DECORATIONS,
    onNodesChange,
    onEdgesChange,
    onConnect,
    onNodeClick,
    onPaneClick,
    onEdgeClick,
    onDropNodeType,
    onReady,
    showMinimap = false,
    interactive = true,
    className = 'h-full min-h-[32rem] w-full',
}: CanvasProps) {
    const [instance, setInstance] = useState<ReactFlowInstance<NodeflowNode, NodeflowEdge> | null>(null)
    const reducedMotion = prefersReducedMotion()
    const context = useMemo(
        () => ({ defs, renderers, nodeErrors, decorations: nodeDecorations }),
        [defs, renderers, nodeErrors, nodeDecorations],
    )
    const interactions = interactionProps(interactive)
    const behavior = useMemo(
        () => canvasBehavior(interactive, nodes, edges, { onNodesChange, onEdgesChange, onConnect }),
        [interactive, nodes, edges, onNodesChange, onEdgesChange, onConnect],
    )
    const handleNodeClick = useCallback<NodeMouseHandler<NodeflowNode>>(
        (_, node) => onNodeClick?.(node.id),
        [onNodeClick],
    )
    const handlePaneClick = useCallback(() => onPaneClick?.(), [onPaneClick])
    const handleEdgeClick = useCallback(
        (_: MouseEvent, edge: NodeflowEdge) => notifyEdgeClick(onEdgeClick, edge),
        [onEdgeClick],
    )
    const actions = useMemo(
        () => instance === null ? null : canvasActions(instance, reducedMotion),
        [instance, reducedMotion],
    )
    useEffect(() => {
        if (actions !== null) {
            onReady?.(actions)
        }
    }, [actions, onReady])
    const acceptsNodeTypeDrop = useCallback((event: DragEvent<HTMLDivElement>) => {
        return interactive && onDropNodeType !== undefined && event.dataTransfer.getData(NODE_TYPE_MIME) !== ''
    }, [interactive, onDropNodeType])
    const handleDragOver = useCallback((event: DragEvent<HTMLDivElement>) => {
        if (acceptsNodeTypeDrop(event)) {
            event.preventDefault()
        }
    }, [acceptsNodeTypeDrop])
    const handleDrop = useCallback((event: DragEvent<HTMLDivElement>) => {
        if (!acceptsNodeTypeDrop(event) || instance === null) {
            return
        }

        event.preventDefault()
        onDropNodeType!(event.dataTransfer.getData(NODE_TYPE_MIME), instance.screenToFlowPosition({
            x: event.clientX,
            y: event.clientY,
        }))
    }, [acceptsNodeTypeDrop, instance, onDropNodeType])

    return (
        <CanvasContext.Provider value={context}>
            <div className={className}>
                <ReactFlow<NodeflowNode, NodeflowEdge>
                    nodes={behavior.nodes}
                    edges={behavior.edges}
                    nodeTypes={nodeTypes}
                    edgeTypes={edgeTypes}
                    onNodesChange={behavior.onNodesChange}
                    onEdgesChange={behavior.onEdgesChange}
                    onConnect={behavior.onConnect}
                    onNodeClick={handleNodeClick}
                    onPaneClick={handlePaneClick}
                    onEdgeClick={handleEdgeClick}
                    onInit={setInstance}
                    onDragOver={handleDragOver}
                    onDrop={handleDrop}
                    {...interactions}
                    fitView
                    proOptions={{ hideAttribution: true }}
                >
                    <Background color="hsl(var(--border))" />
                    <Controls showInteractive={false} className="border border-border bg-background text-foreground shadow-sm" />
                    {showMinimap && <MiniMap pannable zoomable />}
                </ReactFlow>
            </div>
        </CanvasContext.Provider>
    )
}
