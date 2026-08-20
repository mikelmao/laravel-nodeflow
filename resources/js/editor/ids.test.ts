import { describe, expect, it } from 'vitest'

import type { NodeTypePayload } from '../graph/types'
import { canConnect, nextNodeId } from './ids'

function def(type: string, outputs: string[]): NodeTypePayload {
    return {
        type,
        label: type,
        group: 'G',
        icon: null,
        description: null,
        outputs,
        fields: [],
        default_config: {},
        cardinality: ['subject'],
    }
}

const defs = {
    'app.send': def('app.send', ['sent', 'failed']),
    'one.out': def('one.out', ['default']),
    'core.exit': def('core.exit', []),
}

describe('nextNodeId', () => {
    it('never returns an id that is already taken', () => {
        // Duplicate ids collapse nodes with last-one-wins behavior and silently lose graph data.
        expect(nextNodeId('app.send', new Set())).toBe('send1')
        expect(nextNodeId('app.send', new Set(['send1']))).toBe('send2')
        expect(nextNodeId('app.send', new Set(['send1', 'send2']))).toBe('send3')
    })

    it('uses the full type when the type has no dot', () => {
        // Treating a missing dot segment as undefined would create an unusable id.
        expect(nextNodeId('sendsms', new Set())).toBe('sendsms1')
    })

    it('removes punctuation from the readable id segment', () => {
        // Keeping punctuation would make ids harder to read in publish diagnostics.
        expect(nextNodeId('yaya.send-message', new Set())).toBe('sendmessage1')
    })
})

describe('canConnect', () => {
    it('requires a named handle when the source has multiple outputs', () => {
        // An unattributable multi-output edge cannot be resolved when converted to a graph.
        expect(canConnect('app.send', null, defs)).toBe(false)
        expect(canConnect('app.send', 'sent', defs)).toBe(true)
    })

    it('allows a missing handle when the source has exactly one output', () => {
        // Rejecting the implicit handle would prevent a valid single-output connection.
        expect(canConnect('one.out', null, defs)).toBe(true)
    })

    it('rejects a named handle that the source does not declare', () => {
        // Accepting a stale or fabricated output would persist an unresolved edge.
        expect(canConnect('app.send', 'unknown', defs)).toBe(false)
        expect(canConnect('one.out', 'unknown', defs)).toBe(false)
    })

    it('rejects connections from a terminal node', () => {
        // A terminal node declares no output, so no connection can be attributed to it.
        expect(canConnect('core.exit', null, defs)).toBe(false)
        expect(canConnect('core.exit', 'default', defs)).toBe(false)
    })

    it('rejects an unknown or undefined source type safely', () => {
        // An unknown draft type must not crash the editor or create an unresolved edge.
        expect(canConnect('missing.type', null, defs)).toBe(false)
        expect(canConnect(undefined, null, defs)).toBe(false)
    })
})
