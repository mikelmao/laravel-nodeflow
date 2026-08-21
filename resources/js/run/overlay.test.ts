import { describe, expect, it } from 'vitest'
import { decorationsFor, normalizeOverlay, overlayFor } from './overlay'

function snapshot(nodes: Record<string, unknown>, terminal = false) {
    return normalizeOverlay({ status: 'running', terminal, nodes })
}

const reachedZero = { reached: true, byOutput: { unmatched: 0 }, waiting: 0, failed: 0, error: null }
const neverReached = { reached: false, byOutput: {}, waiting: 0, failed: 0, error: null }

describe('normalizeOverlay', () => {
    // Counterfactual: keep the server object as-is and a persisted node id of
    // "toString" resolves Object.prototype.toString, so a node that was never
    // reached reports itself as a function-shaped overlay.
    it('builds a null-prototype map so inherited keys cannot resolve', () => {
        const result = snapshot({ n1: neverReached })

        expect(Object.getPrototypeOf(result.nodes)).toBeNull()
        expect(overlayFor(result, 'toString')).toBeUndefined()
        expect(overlayFor(result, 'constructor')).toBeUndefined()
        expect(overlayFor(result, '__proto__')).toBeUndefined()
    })

    // Node ids come from a stored graph, so these are real record keys a flow
    // author can choose. Counterfactual: read with `snapshot.nodes[id]` and a
    // node genuinely named __proto__ silently reads the prototype instead.
    it('keeps a node genuinely named like a prototype key addressable', () => {
        // Built from JSON text, not an object literal: `{ __proto__: x }` is
        // special-cased by the language to set the prototype, whereas JSON.parse
        // creates a genuine own enumerable property — and JSON.parse is how this
        // payload actually arrives, via send() and response.json(). A literal
        // fixture here would silently test nothing.
        const result = normalizeOverlay(JSON.parse(
            '{"status":"running","terminal":false,"nodes":{"__proto__":{"reached":true,"byOutput":{},"waiting":0,"failed":0,"error":null},"constructor":{"reached":false,"byOutput":{},"waiting":0,"failed":0,"error":null}}}',
        ))

        expect(overlayFor(result, '__proto__')?.reached).toBe(true)
        expect(overlayFor(result, 'constructor')?.reached).toBe(false)

        // Confirms the map itself still has a null prototype after being built
        // from keys shaped like prototype accessors — the same invariant the
        // previous test asserts, re-checked against a payload that actually
        // exercises those keys rather than merely avoiding them.
        expect(Object.getPrototypeOf(result.nodes)).toBeNull()
    })

    // Counterfactual: trust the payload and a server change lands as
    // `Cannot read properties of undefined` somewhere deep in the canvas.
    it('rejects a payload that is not an overlay envelope', () => {
        expect(() => normalizeOverlay(null)).toThrow(/overlay payload/i)
        expect(() => normalizeOverlay({ status: 'running', nodes: {} })).toThrow(/overlay payload/i)
        expect(() => normalizeOverlay({ terminal: false, nodes: [] })).toThrow(/overlay payload/i)
    })

    it('coerces a missing or malformed node entry to a safe never-reached shape', () => {
        // Counterfactual: pass a string through as an entry and byOutput reads
        // as undefined at render time.
        const result = snapshot({ n1: 'nonsense', n2: { reached: true, waiting: 'lots' } })

        expect(overlayFor(result, 'n1')).toEqual({ reached: false, byOutput: {}, waiting: 0, failed: 0, error: null })
        expect(overlayFor(result, 'n2')?.waiting).toBe(0)
        expect(overlayFor(result, 'n2')?.reached).toBe(true)
    })
})

describe('decorationsFor', () => {
    /**
     * The DOM-level half of this lives in FlowRun.test.tsx; this is the data
     * half. Counterfactual: derive dimming from a count — `!byOutput.length` or
     * `total === 0` — and the reached-zero node dims exactly like the node
     * nothing ever touched, which is the misreading the whole overlay exists to
     * prevent.
     */
    it('dims only the never-reached node and gives the reached-zero node an explicit zero', () => {
        const decorations = decorationsFor(['zero', 'never'], snapshot({ zero: reachedZero, never: neverReached }))

        expect(decorations.never).toEqual({ dimmed: true, badges: [] })
        expect(decorations.zero?.dimmed).toBe(false)
        expect(decorations.zero?.badges).toEqual([{ key: 'out:unmatched', label: 'unmatched', value: 0 }])
    })

    it('badges every output, then waiting, then failures', () => {
        const decorations = decorationsFor(['n1'], snapshot({
            n1: { reached: true, byOutput: { sent: 7, failed: 1 }, waiting: 3, failed: 2, error: 'boom' },
        }))

        expect(decorations.n1?.badges).toEqual([
            { key: 'out:sent', label: 'sent', value: 7 },
            { key: 'out:failed', label: 'failed', value: 1 },
            { key: 'waiting', label: 'waiting', value: 3 },
            { key: 'failed', label: 'failed', value: 2 },
        ])
    })

    it('shows a zero rather than nothing for a reached node with no numbers at all', () => {
        // Reachable via a node holding subjects that then leaves. Counterfactual:
        // emit no badges and a reached node renders identically to a dimmed one
        // apart from the opacity, which is not a semantic difference.
        const decorations = decorationsFor(['n1'], snapshot({
            n1: { reached: true, byOutput: {}, waiting: 0, failed: 0, error: null },
        }))

        expect(decorations.n1).toEqual({ dimmed: false, badges: [{ key: 'zero', label: 'subjects', value: 0 }] })
    })

    it('treats a graph node the server did not mention as never reached', () => {
        // Counterfactual: skip unknown ids and the canvas renders a node with no
        // decoration at all, which looks reached.
        const decorations = decorationsFor(['ghost'], snapshot({}))

        expect(decorations.ghost).toEqual({ dimmed: true, badges: [] })
    })
})
