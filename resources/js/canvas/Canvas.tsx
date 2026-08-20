import { Background, Controls, ReactFlow, type Connection, type Edge, type Node, type NodeTypes, type OnEdgesChange, type OnNodesChange, type ReactFlowProps } from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import { useMemo } from 'react'
import type { CanvasEdge, CanvasNode, NodeCardData, NodeTypePayload } from '../graph/types'
import { CanvasContext, type NodeRendererMap } from './context'
import { NodeCard } from './NodeCard'

// The graph module stays free of xyflow. These intersections make adapter drift
// fail the compiler without an unknown or as-unknown boundary cast.
export type NodeflowNode = CanvasNode & Node<NodeCardData, 'nodeflowNode'>
export type NodeflowEdge = CanvasEdge & Edge

export type CanvasProps = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    defs: Record<string, NodeTypePayload>
    renderers?: NodeRendererMap
    nodeErrors?: Record<string, string[]>
    onNodesChange?: OnNodesChange<NodeflowNode>
    onEdgesChange?: OnEdgesChange<NodeflowEdge>
    onConnect?: (connection: Connection) => void
    onNodeClick?: (id: string) => void
    /** False freezes every mutation, selection, focus, and keyboard path for run views. */
    interactive?: boolean
    className?: string
}

// Keeping nodeTypes at module scope avoids React Flow remount warnings.
const nodeTypes = { nodeflowNode: NodeCard } satisfies NodeTypes

type InteractionProps = Pick<ReactFlowProps<NodeflowNode, NodeflowEdge>, 'nodesDraggable' | 'nodesConnectable' | 'nodesFocusable' | 'edgesFocusable' | 'elementsSelectable' | 'edgesReconnectable' | 'deleteKeyCode' | 'disableKeyboardA11y'>

export function interactionProps(interactive: boolean): InteractionProps {
    return { nodesDraggable: interactive, nodesConnectable: interactive, nodesFocusable: interactive, edgesFocusable: interactive, elementsSelectable: interactive, edgesReconnectable: interactive, deleteKeyCode: interactive ? ['Backspace', 'Delete'] : null, disableKeyboardA11y: !interactive }
}

// Element flags override global React Flow flags, so read-only mode clears both.
function readOnlyNodes(nodes: NodeflowNode[]): NodeflowNode[] {
    return nodes.map(node => ({ ...node, draggable: false, selectable: false, deletable: false, focusable: false, connectable: false }))
}

function readOnlyEdges(edges: NodeflowEdge[]): NodeflowEdge[] {
    return edges.map(edge => ({ ...edge, selectable: false, deletable: false, focusable: false, reconnectable: false }))
}

type MutationCallbacks = Pick<CanvasProps, 'onNodesChange' | 'onEdgesChange' | 'onConnect'>

export function canvasBehavior(interactive: boolean, nodes: NodeflowNode[], edges: NodeflowEdge[], callbacks: MutationCallbacks): { nodes: NodeflowNode[]; edges: NodeflowEdge[] } & MutationCallbacks {
    return interactive ? { nodes, edges, ...callbacks } : { nodes: readOnlyNodes(nodes), edges: readOnlyEdges(edges), onNodesChange: undefined, onEdgesChange: undefined, onConnect: undefined }
}

/**
 * Canvas owns no graph state, so the editor and Plan 4 run view can share it.
 * The default class supplies a real parent height because xyflow cannot lay out
 * inside a heightless container. Its stylesheet is imported at this boundary.
 */
export function Canvas({ nodes, edges, defs, renderers = {}, nodeErrors = {}, onNodesChange, onEdgesChange, onConnect, onNodeClick, interactive = true, className = 'h-full min-h-[32rem] w-full' }: CanvasProps) {
    const context = useMemo(() => ({ defs, renderers, nodeErrors }), [defs, renderers, nodeErrors])
    const interactions = interactionProps(interactive)
    const behavior = useMemo(() => canvasBehavior(interactive, nodes, edges, { onNodesChange, onEdgesChange, onConnect }), [interactive, nodes, edges, onNodesChange, onEdgesChange, onConnect])

    return <CanvasContext.Provider value={context}><div className={className}><ReactFlow<NodeflowNode, NodeflowEdge>
        nodes={behavior.nodes} edges={behavior.edges} nodeTypes={nodeTypes} onNodesChange={behavior.onNodesChange} onEdgesChange={behavior.onEdgesChange} onConnect={behavior.onConnect}
        onNodeClick={(_, node) => onNodeClick?.(node.id)} {...interactions} fitView proOptions={{ hideAttribution: true }}>
        <Background /><Controls showInteractive={false} />
    </ReactFlow></div></CanvasContext.Provider>
}
