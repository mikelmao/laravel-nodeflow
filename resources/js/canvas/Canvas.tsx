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
import { useCallback, useEffect, useMemo, useRef, useState, type DragEvent, type MouseEvent } from 'react'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { CanvasContext, type NodeDecorationMap, type NodeRendererMap } from './context'
import { CANVAS_ORIGIN, NODE_MIN_HEIGHT, NODE_WIDTH } from './layout'
import { NodeCard } from './NodeCard'
import { WorkflowEdge } from './WorkflowEdge'

// The graph module stays free of xyflow. These intersections make adapter drift
// fail the compiler without an unknown or as-unknown boundary cast.
export type NodeflowNode = CanvasNode & Node<NodeCardData, 'nodeflowNode'>
export type NodeflowEdge = CanvasEdge & Edge
export type CanvasPoint = { x: number; y: number }

export type CanvasActions = {
    fit: () => void
    centerNode: (id: string) => void
    screenToFlowPosition: (point: { x: number; y: number }) => { x: number; y: number }
    viewportCenter: () => CanvasPoint
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
    onDispose?: (actions: CanvasActions) => void
    /** The editor scopes deletion itself so React Flow cannot race its cleanup. */
    deleteKeyCode?: ReactFlowProps<NodeflowNode, NodeflowEdge>['deleteKeyCode']
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
    wrapper?: HTMLElement | null,
): CanvasActions {
    const duration = reducedMotion ? 0 : 220

    return {
        fit: () => void instance.fitView({ padding: 0.22, duration }),
        centerNode: (id) => {
            const node = instance.getNode(id)

            if (node !== undefined) {
                const bounds = instance.getNodesBounds([node])
                const width = bounds.width > 0 ? bounds.width : NODE_WIDTH
                const height = bounds.height > 0 ? bounds.height : NODE_MIN_HEIGHT
                void instance.setCenter(
                    bounds.x + width / 2,
                    bounds.y + height / 2,
                    { zoom: Math.max(instance.getZoom(), 0.85), duration },
                )
            }
        },
        screenToFlowPosition: (point) => instance.screenToFlowPosition(point),
        viewportCenter: () => {
            const rect = wrapper?.getBoundingClientRect()
            const screen = rect !== undefined && rect.width > 0 && rect.height > 0
                ? { x: rect.left + rect.width / 2, y: rect.top + rect.height / 2 }
                : typeof window === 'undefined'
                    ? CANVAS_ORIGIN
                    : { x: Math.max(0, window.innerWidth || document.documentElement.clientWidth || 0) / 2, y: Math.max(0, window.innerHeight || document.documentElement.clientHeight || 0) / 2 }
            return instance.screenToFlowPosition(screen)
        },
    }
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
    onDispose,
    deleteKeyCode,
    showMinimap = false,
    interactive = true,
    className = 'h-full min-h-[32rem] w-full',
}: CanvasProps) {
    const [instance, setInstance] = useState<ReactFlowInstance<NodeflowNode, NodeflowEdge> | null>(null)
    const wrapperRef = useRef<HTMLDivElement>(null)
    const disposeRef = useRef(onDispose)
    disposeRef.current = onDispose
    const reducedMotion = prefersReducedMotion()
    const context = useMemo(
        () => ({ defs, renderers, nodeErrors, decorations: nodeDecorations }),
        [defs, renderers, nodeErrors, nodeDecorations],
    )
    const defaultInteractions = interactionProps(interactive)
    const interactions = { ...defaultInteractions, deleteKeyCode: deleteKeyCode === undefined ? defaultInteractions.deleteKeyCode : deleteKeyCode }
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
        (_: MouseEvent, edge: NodeflowEdge) => onEdgeClick?.(edge.id),
        [onEdgeClick],
    )
    const actions = useMemo(
        () => instance === null ? null : canvasActions(instance, reducedMotion, wrapperRef.current),
        [instance, reducedMotion],
    )
    useEffect(() => {
        if (actions !== null) onReady?.(actions)
    }, [actions, onReady])
    useEffect(() => {
        if (actions !== null) return () => disposeRef.current?.(actions)
    }, [actions])
    const canDropNodeType = interactive && onDropNodeType !== undefined
    const hasNodeTypeMime = useCallback(
        (event: DragEvent<HTMLDivElement>) => Array.from(event.dataTransfer.types).includes(NODE_TYPE_MIME),
        [],
    )
    const handleDragOver = useCallback((event: DragEvent<HTMLDivElement>) => {
        if (canDropNodeType && hasNodeTypeMime(event)) {
            event.preventDefault()
        }
    }, [canDropNodeType, hasNodeTypeMime])
    const handleDrop = useCallback((event: DragEvent<HTMLDivElement>) => {
        if (!canDropNodeType || !hasNodeTypeMime(event) || instance === null) {
            return
        }

        const type = event.dataTransfer.getData(NODE_TYPE_MIME)

        if (type === '') {
            return
        }

        event.preventDefault()
        onDropNodeType!(type, instance.screenToFlowPosition({
            x: event.clientX,
            y: event.clientY,
        }))
    }, [canDropNodeType, hasNodeTypeMime, instance, onDropNodeType])

    return (
        <CanvasContext.Provider value={context}>
            <div ref={wrapperRef} className={className}>
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
                    {showMinimap && (
                        <MiniMap
                            pannable
                            zoomable
                            className="border border-border bg-background"
                            style={{ background: 'hsl(var(--background))' }}
                        />
                    )}
                </ReactFlow>
            </div>
        </CanvasContext.Provider>
    )
}
