import type { HttpResult } from '../http'
import type { NodeErrorEntry } from '../graph/types'

const INVALID_SUCCESS_MESSAGE = 'The publish response contained an invalid version or draft_revision.'
const INVALID_NODE_ERRORS_MESSAGE = 'The publish response contained invalid node_errors.'
const INVALID_STRUCTURAL_ERRORS_MESSAGE = 'The publish response contained invalid structural errors.'

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
        const version = result.data?.version
        const revision = result.data?.draft_revision
        if (
            typeof version !== 'number'
            || !Number.isSafeInteger(version)
            || version <= 0
            || typeof revision !== 'number'
            || !Number.isSafeInteger(revision)
            || revision < 0
        ) {
            return { kind: 'failed', message: INVALID_SUCCESS_MESSAGE }
        }

        return {
            kind: 'published',
            version,
            revision,
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
        const errors = body.errors
        if (!isStructuralErrors(errors)) {
            return { kind: 'failed', message: INVALID_STRUCTURAL_ERRORS_MESSAGE }
        }

        return {
            kind: 'structural',
            developer: Object.entries(errors).flatMap(([field, messages]) =>
                messages.map((message) => `${field}: ${message}`),
            ),
        }
    }

    if (!Array.isArray(body.node_errors) || !body.node_errors.every(isNodeErrorEntry)) {
        return { kind: 'failed', message: INVALID_NODE_ERRORS_MESSAGE }
    }

    const nodeErrors = body.node_errors
    const byNode = Object.create(null) as Record<string, NodeErrorEntry[]>
    const unplaceable: string[] = []

    for (const entry of nodeErrors) {
        // Null and absent node ids cannot be placed on a card, so keep them visible globally.
        if (entry.node !== null && knownNodeIds.has(entry.node)) {
            if (!Object.prototype.hasOwnProperty.call(byNode, entry.node)) {
                byNode[entry.node] = []
            }
            byNode[entry.node]!.push(entry)
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

function isNodeErrorEntry(value: unknown): value is NodeErrorEntry {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false
    }

    const entry = value as Record<string, unknown>
    return (typeof entry.node === 'string' || entry.node === null)
        && (typeof entry.field === 'string' || entry.field === null)
        && typeof entry.message === 'string'
}

function isStructuralErrors(value: unknown): value is Record<string, string[]> {
    return typeof value === 'object'
        && value !== null
        && !Array.isArray(value)
        && Object.values(value).every((messages) =>
            Array.isArray(messages) && messages.every((message) => typeof message === 'string'),
        )
}
