import { createContext, type ReactElement } from 'react'
import type { NodeCardData, NodeTypePayload } from '../graph/types'

/**
 * A renderer supplies only the node body. NodeCard deliberately retains every
 * handle so an application override cannot accidentally make a node unwirable.
 * The definition may be undefined because an unregistered type is a legal
 * draft state. Errors are available to renderers as well as the mandatory list
 * owned by NodeCard.
 */
export type NodeRendererProps = {
    data: NodeCardData
    def: NodeTypePayload | undefined
    selected: boolean
    errors: string[]
}
export type NodeRenderer = (props: NodeRendererProps) => ReactElement | null
export type NodeRendererMap = Record<string, NodeRenderer>

/**
 * Node definitions, host renderers, and publish errors are contextual rather
 * than graph data. This keeps errors and presentation changes from looking like
 * graph edits to the autosave layer.
 */
export type CanvasContextValue = {
    defs: Record<string, NodeTypePayload>
    renderers: NodeRendererMap
    nodeErrors: Record<string, string[]>
    decorations: NodeDecorationMap
}
export const CanvasContext = createContext<CanvasContextValue>({
    defs: {},
    renderers: {},
    nodeErrors: {},
    decorations: {},
})

/**
 * Per-node presentation the canvas applies but does not interpret.
 *
 * Deliberately plain data rather than JSX or run vocabulary: the canvas is
 * shared with the editor, so it should not learn what a "run" is, and a
 * serialisable shape lets a test assert the derivation and the rendering
 * separately instead of only through the DOM.
 */
export type NodeBadge = { key: string; label: string; value: number }
export type NodeDecoration = { dimmed: boolean; badges: NodeBadge[] }
export type NodeDecorationMap = Record<string, NodeDecoration>
