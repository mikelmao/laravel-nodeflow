import {
    Background,
    Controls,
    MiniMap,
    ReactFlow,
    useReactFlow,
    useStore,
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
import { useCallback, useEffect, useMemo, useRef, useState, type CSSProperties, type DragEvent, type MouseEvent } from 'react'
import type { CanvasEdge, CanvasNode, GraphComponentPayload, NodeCardData } from '../graph/types'
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
    viewportCenter?: () => CanvasPoint
}

export type CanvasProps = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    defs: Record<string, GraphComponentPayload>
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
/** React Flow's default floor (0.5) cannot frame a flow wider than twice the viewport. */
export const CANVAS_MIN_ZOOM = 0.2
export const CANVAS_MAX_ZOOM = 2
const FIT_PADDING = 0.22

/**
 * A host semantic token as a CSS color. Tailwind 4 themes expose it either as
 * --color-<name> or, with `@theme inline` (shadcn style), only as --<name>.
 * The package ships no stylesheet, so these are the only values it assumes.
 */
function token(name: string): string {
    return `var(--color-${name}, var(--${name}))`
}

function tint(name: string, percent: number): string {
    return `color-mix(in oklab, ${token(name)} ${percent}%, transparent)`
}

/**
 * Maps React Flow's own theming variables onto the host tokens so edges,
 * controls, minimap and selection follow the host theme in light and dark.
 * Each value can be overridden through a --nodeflow-* variable.
 */
export const canvasThemeStyle = {
    '--xy-edge-stroke': `var(--nodeflow-edge, ${tint('muted-foreground', 80)})`,
    '--xy-edge-stroke-selected': `var(--nodeflow-edge-selected, ${token('primary')})`,
    '--xy-edge-stroke-width': 'var(--nodeflow-edge-width, 1.5)',
    '--xy-connectionline-stroke': `var(--nodeflow-edge-selected, ${token('primary')})`,
    '--xy-connectionline-stroke-width': 'var(--nodeflow-edge-width, 1.5)',
    '--xy-handle-background-color': token('muted-foreground'),
    '--xy-handle-border-color': token('card'),
    '--xy-selection-background-color': tint('primary', 8),
    '--xy-selection-border': `1px dashed ${token('primary')}`,
    '--xy-controls-button-background-color': token('card'),
    '--xy-controls-button-background-color-hover': token('muted'),
    '--xy-controls-button-color': token('foreground'),
    '--xy-controls-button-color-hover': token('foreground'),
    '--xy-controls-button-border-color': token('border'),
    '--xy-controls-box-shadow': 'none',
    '--xy-minimap-background-color': token('card'),
    '--xy-minimap-mask-background-color': tint('foreground', 8),
    '--xy-minimap-mask-stroke-color': token('border'),
    '--xy-minimap-node-background-color': tint('muted-foreground', 35),
    '--xy-minimap-node-stroke-color': 'transparent',
    '--xy-edge-label-background-color': token('card'),
    '--xy-edge-label-color': token('muted-foreground'),
} as CSSProperties
const DOT_COLOR = `var(--nodeflow-canvas-dots, ${tint('muted-foreground', 28)})`
const MINIMAP_NODE_COLOR = `var(--nodeflow-minimap-node, ${tint('muted-foreground', 45)})`
const CONNECTION_LINE_STYLE = { strokeWidth: 1.5 } satisfies CSSProperties
/** Below this zoom node text is unreadable, so the first view pans instead of shrinking further. */
export const READABLE_ZOOM = 0.6
const VIEWPORT_MARGIN = 56

export type ViewportSize = { width: number; height: number }
export type Bounds = { x: number; y: number; width: number; height: number }

/**
 * The first view of a flow: the whole graph when it fits at a readable zoom
 * (never above 1:1), otherwise a readable zoom anchored on the flow's left
 * edge, where its trigger sits. Explicit Fit still frames everything.
 */
export function initialViewport(bounds: Bounds, size: ViewportSize): { x: number; y: number; zoom: number } {
    const usableWidth = Math.max(1, size.width - VIEWPORT_MARGIN * 2)
    const usableHeight = Math.max(1, size.height - VIEWPORT_MARGIN * 2)
    const fitZoom = Math.min(usableWidth / Math.max(1, bounds.width), usableHeight / Math.max(1, bounds.height), 1)
    const zoom = Math.max(fitZoom, READABLE_ZOOM)
    const graphWidth = bounds.width * zoom
    const graphHeight = bounds.height * zoom
    const x = graphWidth <= usableWidth ? (size.width - graphWidth) / 2 - bounds.x * zoom : VIEWPORT_MARGIN - bounds.x * zoom
    const y = graphHeight <= usableHeight ? (size.height - graphHeight) / 2 - bounds.y * zoom : VIEWPORT_MARGIN - bounds.y * zoom
    return { x, y, zoom }
}

/** Applies initialViewport once, after React Flow has measured the nodes and the pane. */
function InitialViewport() {
    // React Flow's own nodesInitialized flag only updates when the host feeds
    // measured nodes back, which read-only canvases never do; the measured
    // internals are the reliable signal.
    const initialized = useStore((state) => {
        if (state.nodeLookup.size === 0) return false
        for (const node of state.nodeLookup.values()) {
            if (node.internals.handleBounds === undefined || !node.measured.width || !node.measured.height) return false
        }
        return true
    })
    const width = useStore((state) => state.width)
    const height = useStore((state) => state.height)
    const { getNodes, getNodesBounds, setViewport } = useReactFlow<NodeflowNode, NodeflowEdge>()
    const applied = useRef(false)

    useEffect(() => {
        if (applied.current || !initialized || width === 0 || height === 0) return
        const nodes = getNodes()
        if (nodes.length === 0) return
        applied.current = true
        void setViewport(initialViewport(getNodesBounds(nodes), { width, height }))
    }, [getNodes, getNodesBounds, height, initialized, setViewport, width])

    return null
}

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
        fit: () => void instance.fitView({ padding: FIT_PADDING, duration, minZoom: CANVAS_MIN_ZOOM }),
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
 * Canvas owns no graph state, so the editor and read-only run view can share it.
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
            <div ref={wrapperRef} className={className} style={canvasThemeStyle}>
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
                    minZoom={CANVAS_MIN_ZOOM}
                    maxZoom={CANVAS_MAX_ZOOM}
                    connectionLineStyle={CONNECTION_LINE_STYLE}
                    proOptions={{ hideAttribution: true }}
                >
                    <InitialViewport />
                    <Background color={DOT_COLOR} gap={20} size={1.2} />
                    <Controls showInteractive={false} className="overflow-hidden rounded-md border border-border shadow-sm" />
                    {showMinimap && (
                        <MiniMap
                            pannable
                            zoomable
                            ariaLabel="Flow minimap"
                            nodeColor={MINIMAP_NODE_COLOR}
                            nodeBorderRadius={6}
                            className="overflow-hidden rounded-md border border-border shadow-sm max-sm:hidden"
                            style={{ width: 176, height: 120 }}
                        />
                    )}
                </ReactFlow>
            </div>
        </CanvasContext.Provider>
    )
}
