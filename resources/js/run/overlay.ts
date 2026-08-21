import type { NodeBadge, NodeDecoration, NodeDecorationMap } from '../canvas/context'
import type { NodeOverlay, OverlaySnapshot } from '../graph/types'

const MALFORMED =
    'The overlay payload must be an object with a boolean "terminal" and an object "nodes". The server\'s run overlay contract has changed shape.'

function isPlainObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function finiteNumber(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0
}

/**
 * Own enumerable pairs only, into a null-prototype map. `Object.entries`
 * reads via [[OwnPropertyKeys]], so it never consults the prototype for
 * either the key list or the value — there is no `__proto__` special case to
 * reason about, because the payload always arrives via `JSON.parse` (send()
 * -> response.json()), which itself always creates a genuine own enumerable
 * property named "__proto__" rather than special-casing it the way object
 * literal syntax does.
 */
function numberMap(raw: unknown): Record<string, number> {
    const counts: Record<string, number> = Object.create(null)

    if (!isPlainObject(raw)) {
        return counts
    }

    for (const [key, value] of Object.entries(raw)) {
        counts[key] = finiteNumber(value)
    }

    return counts
}

function nodeOverlay(raw: unknown): NodeOverlay {
    const source = isPlainObject(raw) ? raw : {}

    return {
        reached: source.reached === true,
        byOutput: numberMap(source.byOutput),
        waiting: finiteNumber(source.waiting),
        failed: finiteNumber(source.failed),
        error: typeof source.error === 'string' ? source.error : null,
    }
}

/**
 * Validate and re-key the server's snapshot before any of it reaches state.
 *
 * Node ids are persisted record keys chosen by a flow author, so `__proto__`,
 * `constructor` and `toString` are all reachable values. A plain object literal
 * would resolve those from Object.prototype, reporting an overlay for a node
 * that has none and losing the real entry for a node that is genuinely named
 * that. Object.create(null) plus own-key reads closes both directions.
 */
export function normalizeOverlay(raw: unknown): OverlaySnapshot {
    if (!isPlainObject(raw) || typeof raw.terminal !== 'boolean' || !isPlainObject(raw.nodes)) {
        throw new Error(MALFORMED)
    }

    const nodes: Record<string, NodeOverlay> = Object.create(null)

    for (const [key, value] of Object.entries(raw.nodes)) {
        nodes[key] = nodeOverlay(value)
    }

    return {
        status: typeof raw.status === 'string' ? raw.status : '',
        terminal: raw.terminal,
        nodes,
    }
}

export function overlayFor(snapshot: OverlaySnapshot, nodeId: string): NodeOverlay | undefined {
    return Object.prototype.hasOwnProperty.call(snapshot.nodes, nodeId) ? snapshot.nodes[nodeId] : undefined
}

/**
 * The one place the two visual states are decided.
 *
 * Never reached is dimmed with no badge; reached always carries at least one
 * number, falling back to an explicit 0. Deriving either from a count collapses
 * "released nobody" into "never touched", which is the misreading this whole
 * overlay exists to prevent — see the run-view spec's E13.
 */
export function decorationsFor(nodeIds: string[], snapshot: OverlaySnapshot): NodeDecorationMap {
    const decorations: NodeDecorationMap = Object.create(null)

    for (const id of nodeIds) {
        decorations[id] = decoration(overlayFor(snapshot, id))
    }

    return decorations
}

function decoration(overlay: NodeOverlay | undefined): NodeDecoration {
    if (overlay === undefined || !overlay.reached) {
        return { dimmed: true, badges: [] }
    }

    const badges: NodeBadge[] = Object.keys(overlay.byOutput).map((output) => ({
        key: `out:${output}`,
        label: output,
        value: overlay.byOutput[output]!,
    }))

    if (overlay.waiting > 0) {
        badges.push({ key: 'waiting', label: 'waiting', value: overlay.waiting })
    }

    if (overlay.failed > 0) {
        // Labelled "errors", not "failed" (a deliberate override of spec
        // §4.2): a node can declare an output literally named "failed" (the
        // demo's `demo.send` does), which gets its own `out:failed` badge
        // right alongside this one. Two badges both reading "failed" — one
        // meaning "1 subject took the output named failed" and the other "2
        // subjects errored at this node" — is nonsense to an operator, even
        // though the React keys stay distinct (`out:failed` vs `errors`).
        // Nothing stops a node from also declaring an output named "errors",
        // so this does not eliminate the collision in general — it only
        // avoids the one real, demonstrated case.
        badges.push({ key: 'errors', label: 'errors', value: overlay.failed })
    }

    return {
        dimmed: false,
        badges: badges.length > 0 ? badges : [{ key: 'zero', label: 'subjects', value: 0 }],
    }
}
