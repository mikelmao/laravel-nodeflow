import type { Graph, NodeCardData } from '../graph/types'

/** Authoring metadata only. Examples must never contain live recipient data. */
export type NodeDataField = {
    key: string
    label: string
    type: string
    origin: string
    description: string
    example: string
    availability: 'available' | 'conditional' | 'reference'
    /** Host-provided requirements for conditional fields. */
    availabilityNote?: string
}

export type NodeDataContext = {
    summary: string
    fields: NodeDataField[]
    /** Only explicitly supported text fields receive interpolation controls. */
    templateFields?: string[]
    multilineFields?: string[]
}

export type NodeDataResolver = (context: { node: NodeCardData; graph: Graph }) => NodeDataContext
