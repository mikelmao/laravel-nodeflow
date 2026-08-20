import type { NodeTypePayload } from '../graph/types'

export function nextNodeId(type: string, taken: Set<string>): string {
    // IDs appear in publish errors; duplicates collapse nodes with last-one-wins behavior.
    const rawSegment = type.split('.').pop() || 'node'
    const segment = rawSegment.replace(/[^a-z0-9_]/gi, '') || 'node'
    let index = 1

    while (taken.has(`${segment}${index}`)) {
        index += 1
    }

    return `${segment}${index}`
}

export function canConnect(
    sourceType: string | undefined,
    sourceHandle: string | null,
    defs: Record<string, NodeTypePayload>,
): boolean {
    const definition = sourceType !== undefined
        && Object.prototype.hasOwnProperty.call(defs, sourceType)
        ? defs[sourceType]
        : undefined
    const outputs = definition?.outputs

    // Unknown types refuse safely, as does any canvas gesture that toGraph cannot resolve.
    if (outputs === undefined || outputs.length === 0) {
        return false
    }

    if (sourceHandle === null || sourceHandle === '') {
        return outputs.length === 1 && outputs[0] !== ''
    }

    return outputs.includes(sourceHandle)
}
