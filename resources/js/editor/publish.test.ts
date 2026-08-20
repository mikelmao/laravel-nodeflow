import { describe, expect, it } from 'vitest'

import type { HttpResult } from '../http'
import { interpretPublish } from './publish'

function result(status: number, data: Record<string, unknown> | null): HttpResult {
    return { ok: status >= 200 && status < 300, status, data }
}

const known = new Set(['w1', 'send1', '__proto__', 'constructor', 'toString'])

describe('interpretPublish', () => {
    it('reads the version and the revision off a success', () => {
        // Without the returned revision, the next autosave silently receives a 409.
        expect(interpretPublish(result(200, { version: 4, draft_revision: 7 }), known)).toEqual({
            kind: 'published',
            version: 4,
            revision: 7,
        })

        const invalidSuccesses: Array<Record<string, unknown> | null> = [
            null,
            { version: 4 },
            { draft_revision: 7 },
            { version: '4', draft_revision: 7 },
            { version: 4, draft_revision: '7' },
            { version: true, draft_revision: 7 },
            { version: 4, draft_revision: false },
            { version: Number.NaN, draft_revision: 7 },
            { version: 4, draft_revision: Number.POSITIVE_INFINITY },
            { version: 0, draft_revision: 7 },
            { version: 4, draft_revision: -1 },
        ]

        for (const data of invalidSuccesses) {
            expect(interpretPublish(result(200, data), known)).toEqual({
                kind: 'failed',
                message: 'The publish response contained an invalid version or draft_revision.',
            })
        }
    })

    it('reads a semantic failure by the presence of node_errors', () => {
        // Looking at errors instead would silently misclassify this valid semantic response.
        const entry = { node: 'w1', field: 'duration', message: 'not a duration' }

        expect(interpretPublish(result(422, {
            errors: { unexpected: ['shape'] },
            node_errors: [entry],
        }), known)).toEqual({
            kind: 'semantic',
            banner: [],
            byNode: { w1: [entry] },
            unplaceable: [],
        })

        const malformedNodeErrors: unknown[] = [
            { unexpected: 'object' },
            [null],
            [{ node: 1, field: null, message: 'bad node' }],
            [{ node: 'w1', field: 1, message: 'bad field' }],
            [{ node: 'w1', field: null, message: 1 }],
        ]

        for (const nodeErrors of malformedNodeErrors) {
            expect(interpretPublish(result(422, {
                errors: { unexpected: ['shape'] },
                node_errors: nodeErrors,
            }), known)).toEqual({
                kind: 'failed',
                message: 'The publish response contained invalid node_errors.',
            })
        }
    })

    it('sends a graph-level error to the banner rather than to a card', () => {
        // Assigning a graph-level error to a card would silently hide its true scope.
        const entry = { node: null, field: null, message: 'The graph contains a cycle.' }

        expect(interpretPublish(result(422, {
            errors: ['The graph contains a cycle.'],
            node_errors: [entry],
        }), known)).toEqual({
            kind: 'semantic',
            banner: ['The graph contains a cycle.'],
            byNode: {},
            unplaceable: ['The graph contains a cycle.'],
        })
    })

    it('sends an error naming a node that is not in the graph to the banner', () => {
        // Attaching an absent node's error would silently make it impossible to display.
        expect(interpretPublish(result(422, {
            errors: [],
            node_errors: [{ node: 'ghost', field: null, message: 'Ghost node is invalid.' }],
        }), known)).toEqual({
            kind: 'semantic',
            banner: [],
            byNode: {},
            unplaceable: ['Ghost node is invalid.'],
        })
    })

    it('reads a structural failure, with no node_errors key, as a developer message', () => {
        // Rendering a malformed graph as a node error would silently conceal a client bug.
        expect(interpretPublish(result(422, {
            errors: {
                'graph.nodes.0.id': ['The graph.nodes.0.id field is required.'],
            },
        }), known)).toEqual({
            kind: 'structural',
            developer: ['graph.nodes.0.id: The graph.nodes.0.id field is required.'],
        })

        const malformedErrors: unknown[] = [
            undefined,
            null,
            'not an object',
            ['not', 'field-keyed'],
            { graph: 'not an array' },
            { graph: [1] },
        ]

        for (const errors of malformedErrors) {
            expect(interpretPublish(result(422, { errors }), known)).toEqual({
                kind: 'failed',
                message: 'The publish response contained invalid structural errors.',
            })
        }
    })

    it('reports any other status as a plain failure', () => {
        // A generic 419 message would silently omit the only actionable recovery step.
        expect(interpretPublish(result(419, null), known)).toEqual({
            kind: 'failed',
            message: 'Your session expired before this flow could be published. Reload the page and try again.',
        })
        expect(interpretPublish(result(403, null), known)).toEqual({
            kind: 'failed',
            message: 'The flow could not be published (HTTP 403).',
        })
    })

    it('keeps every message recorded against one node', () => {
        // Overwriting by node id would silently discard all but one repair instruction.
        const template = { node: 'send1', field: 'template', message: 'Template is required.' }
        const channel = { node: 'send1', field: 'channel', message: 'Channel is required.' }
        const proto = { node: '__proto__', field: null, message: 'Prototype node is invalid.' }
        const constructor = { node: 'constructor', field: null, message: 'Constructor node is invalid.' }
        const toString = { node: 'toString', field: null, message: 'toString node is invalid.' }

        const outcome = interpretPublish(result(422, {
            errors: [],
            node_errors: [template, channel, proto, constructor, toString],
        }), known)

        expect(outcome.kind).toBe('semantic')
        if (outcome.kind !== 'semantic') {
            throw new Error('Expected a semantic publish outcome.')
        }

        expect(outcome.byNode.send1).toEqual([template, channel])
        expect(Object.prototype.hasOwnProperty.call(outcome.byNode, '__proto__')).toBe(true)
        expect(outcome.byNode.__proto__).toEqual([proto])
        expect(outcome.byNode.__proto__?.[0]).toBe(proto)
        expect(Object.prototype.hasOwnProperty.call(outcome.byNode, 'constructor')).toBe(true)
        expect(outcome.byNode.constructor).toEqual([constructor])
        expect(Object.prototype.hasOwnProperty.call(outcome.byNode, 'toString')).toBe(true)
        expect(outcome.byNode.toString).toEqual([toString])
    })
})
