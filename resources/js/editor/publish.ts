import type { HttpResult } from '../http'
import type { NodeErrorEntry } from '../graph/types'

export type PublishOutcome =
    | { kind: 'published'; version: number; revision: number }
    | { kind: 'semantic'; banner: string[]; byNode: Record<string, NodeErrorEntry[]>; unplaceable: string[] }
    | { kind: 'structural'; developer: string[] }
    | { kind: 'failed'; message: string }

export function interpretPublish(
    result: HttpResult,
    knownNodeIds: Set<string>,
): PublishOutcome {
    if (result.ok) {
        // Publishing does not reset the draft revision used by the next autosave.
        return {
            kind: 'published',
            version: Number(result.data?.version ?? 0),
            revision: Number(result.data?.draft_revision ?? 0),
        }
    }

    if (result.status === 419) {
        return {
            kind: 'failed',
            message: 'Your session expired before this flow could be published. Reload the page and try again.',
        }
    }

    if (result.status !== 422) {
        return {
            kind: 'failed',
            message: typeof result.data?.message === 'string'
                ? result.data.message
                : `The flow could not be published (HTTP ${result.status}).`,
        }
    }

    const body = result.data ?? {}

    // Both failures use 422; the presence of node_errors is their wire discriminator.
    const hasNodeErrors = Object.prototype.hasOwnProperty.call(body, 'node_errors')
    if (!hasNodeErrors) {
        // Structural validation means the editor sent a malformed graph: a client bug.
        const errors = (body.errors ?? {}) as Record<string, string[]>
        return {
            kind: 'structural',
            developer: Object.entries(errors).flatMap(([field, messages]) =>
                messages.map((message) => `${field}: ${message}`),
            ),
        }
    }

    const nodeErrors = Array.isArray(body.node_errors)
        ? body.node_errors as NodeErrorEntry[]
        : []
    const byNode: Record<string, NodeErrorEntry[]> = {}
    const unplaceable: string[] = []

    for (const entry of nodeErrors) {
        // Null and absent node ids cannot be placed on a card, so keep them visible globally.
        if (entry.node !== null && knownNodeIds.has(entry.node)) {
            (byNode[entry.node] ??= []).push(entry)
        } else {
            unplaceable.push(entry.message)
        }
    }

    return {
        kind: 'semantic',
        banner: Array.isArray(body.errors) ? body.errors as string[] : [],
        byNode,
        unplaceable,
    }
}
