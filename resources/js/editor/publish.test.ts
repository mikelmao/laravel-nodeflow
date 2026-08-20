import { describe, expect, it } from 'vitest'

import type { HttpResult } from '../http'
import { interpretPublish } from './publish'

function result(status: number, data: Record<string, unknown> | null): HttpResult {
    return { ok: status >= 200 && status < 300, status, data }
}

const known = new Set(['w1', 'send1'])

describe('interpretPublish', () => {
    it('reads the version and the revision off a success', () => {
        // Without the returned revision, the next autosave silently receives a 409.
        expect(interpretPublish(result(200, { version: 4, draft_revision: 7 }), known)).toEqual({
            kind: 'published',
            version: 4,
            revision: 7,
        })
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

        expect(interpretPublish(result(422, {
            errors: [],
            node_errors: [template, channel],
        }), known)).toEqual({
            kind: 'semantic',
            banner: [],
            byNode: { send1: [template, channel] },
            unplaceable: [],
        })
    })
})
