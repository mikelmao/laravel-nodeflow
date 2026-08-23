import type { HttpResult } from '../http'
import type { NodeErrorEntry } from '../graph/types'

const INVALID_SUCCESS_MESSAGE = 'The validation response had an invalid success shape.'
const INVALID_NODE_ERRORS_MESSAGE = 'The validation response contained invalid node_errors.'
const INVALID_SEMANTIC_ERRORS_MESSAGE = 'The validation response contained invalid semantic errors.'
const INVALID_SEMANTIC_WARNINGS_MESSAGE = 'The validation response contained invalid semantic warnings.'
const INVALID_STRUCTURAL_ERRORS_MESSAGE = 'The validation response contained invalid structural errors.'

export type ValidationOutcome =
    | { kind: 'valid'; warnings: string[] }
    | { kind: 'invalid'; errors: string[]; warnings: string[]; byNode: Record<string, NodeErrorEntry[]>; unplaceable: string[] }
    | { kind: 'structural'; developer: string[] }
    | { kind: 'failed'; message: string }

export function interpretValidation(
    result: HttpResult,
    knownNodeIds: Set<string>,
): ValidationOutcome {
    if (result.ok) {
        return result.data?.valid === true && isStringArray(result.data.warnings)
            ? { kind: 'valid', warnings: result.data.warnings }
            : { kind: 'failed', message: INVALID_SUCCESS_MESSAGE }
    }
    if (result.status === 419) {
        return { kind: 'failed', message: 'Your session expired before this flow could be validated. Reload the page and try again.' }
    }
    if (result.status === 422 && Object.prototype.hasOwnProperty.call(result.data ?? {}, 'node_errors')) {
        return semanticOutcome(result.data ?? {}, knownNodeIds)
    }
    if (result.status === 422) return structuralOutcome(result.data?.errors)
    return { kind: 'failed', message: typeof result.data?.message === 'string' ? result.data.message : `The flow could not be validated (HTTP ${result.status}).` }
}

function semanticOutcome(
    body: Record<string, unknown>,
    knownNodeIds: Set<string>,
): ValidationOutcome {
    if (body.valid !== false || !isStringArray(body.errors)) {
        return { kind: 'failed', message: INVALID_SEMANTIC_ERRORS_MESSAGE }
    }
    if (!isStringArray(body.warnings)) {
        return { kind: 'failed', message: INVALID_SEMANTIC_WARNINGS_MESSAGE }
    }
    if (!Array.isArray(body.node_errors) || !body.node_errors.every(isNodeErrorEntry)) {
        return { kind: 'failed', message: INVALID_NODE_ERRORS_MESSAGE }
    }

    const byNode = Object.create(null) as Record<string, NodeErrorEntry[]>
    const unplaceable: string[] = []

    for (const entry of body.node_errors) {
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
        kind: 'invalid',
        errors: body.errors,
        warnings: body.warnings,
        byNode,
        unplaceable,
    }
}

function structuralOutcome(errors: unknown): ValidationOutcome {
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

function isNodeErrorEntry(value: unknown): value is NodeErrorEntry {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false
    }

    const entry = value as Record<string, unknown>
    return (typeof entry.node === 'string' || entry.node === null)
        && (typeof entry.field === 'string' || entry.field === null)
        && typeof entry.message === 'string'
}

function isStringArray(value: unknown): value is string[] {
    return Array.isArray(value) && value.every((message) => typeof message === 'string')
}

function isStructuralErrors(value: unknown): value is Record<string, string[]> {
    return typeof value === 'object'
        && value !== null
        && !Array.isArray(value)
        && Object.values(value).every((messages) =>
            Array.isArray(messages) && messages.every((message) => typeof message === 'string'),
        )
}
