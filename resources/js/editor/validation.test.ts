import { describe, expect, it } from 'vitest'

import type { HttpResult } from '../http'
import { interpretValidation } from './validation'

function result(status: number, data: Record<string, unknown> | null): HttpResult {
    return { ok: status >= 200 && status < 300, status, data }
}

const known = new Set(['send1'])

describe('interpretValidation', () => {
    it('returns valid warnings from a successful validation', () => {
        expect(interpretValidation(result(200, {
            valid: true,
            warnings: ['Sequential waits can delay delivery.'],
        }), known)).toEqual({
            kind: 'valid',
            warnings: ['Sequential waits can delay delivery.'],
        })
    })

    it('groups semantic errors for known nodes and keeps graph-wide errors unplaceable', () => {
        expect(interpretValidation(result(422, {
            valid: false,
            message: 'The flow is not ready to publish.',
            errors: ['invalid graph'],
            warnings: ['sequential waits'],
            node_errors: [
                { node: 'send1', field: 'template', message: 'Required.' },
                { node: null, field: null, message: 'Cycle.' },
                { node: 'removed', field: null, message: 'The removed node is invalid.' },
            ],
        }), known)).toEqual({
            kind: 'invalid',
            errors: ['invalid graph'],
            warnings: ['sequential waits'],
            byNode: {
                send1: [{ node: 'send1', field: 'template', message: 'Required.' }],
            },
            unplaceable: ['Cycle.', 'The removed node is invalid.'],
        })
    })

    it('returns Laravel structural validation errors as developer messages', () => {
        expect(interpretValidation(result(422, {
            errors: {
                'graph.nodes.0.id': ['The graph.nodes.0.id field is required.'],
            },
        }), known)).toEqual({
            kind: 'structural',
            developer: ['graph.nodes.0.id: The graph.nodes.0.id field is required.'],
        })
    })

    it('rejects malformed semantic node errors', () => {
        expect(interpretValidation(result(422, {
            valid: false,
            message: 'The flow is not ready to publish.',
            errors: [],
            warnings: [],
            node_errors: [{ node: 'send1', field: null }],
        }), known)).toEqual({
            kind: 'failed',
            message: 'The validation response contained invalid node_errors.',
        })
    })

    it('explains when the validation session has expired', () => {
        expect(interpretValidation(result(419, null), known)).toEqual({
            kind: 'failed',
            message: 'Your session expired before this flow could be validated. Reload the page and try again.',
        })
    })

    it('uses the server message or fallback for other failed statuses', () => {
        expect(interpretValidation(result(403, { message: 'You may not validate this flow.' }), known)).toEqual({
            kind: 'failed',
            message: 'You may not validate this flow.',
        })
        expect(interpretValidation(result(500, null), known)).toEqual({
            kind: 'failed',
            message: 'The flow could not be validated (HTTP 500).',
        })
    })

    it('rejects an invalid success response shape', () => {
        expect(interpretValidation(result(200, { valid: true, warnings: 'not an array' }), known)).toEqual({
            kind: 'failed',
            message: 'The validation response had an invalid success shape.',
        })
    })

    it('requires the server message on semantic validation failures', () => {
        for (const message of [undefined, 123]) {
            expect(interpretValidation(result(422, {
                valid: false,
                ...(message === undefined ? {} : { message }),
                errors: [],
                warnings: [],
                node_errors: [],
            }), known)).toEqual({
                kind: 'failed',
                message: 'The validation response contained an invalid message.',
            })
        }
    })
})
