import { useCallback, useEffect, useRef, useState } from 'react'
import type { RunSubjectRow } from '../graph/types'
import { send, subjectsUrl } from '../http'

export type SubjectPanelProps = { template: string; nodeId: string; reached: boolean }

type PanelState = {
    rows: RunSubjectRow[]
    nextCursor: string | null
    loading: boolean
    error: string | null
}

const EMPTY: PanelState = { rows: [], nextCursor: null, loading: true, error: null }

function pageUrl(template: string, nodeId: string, cursor: string | null): string {
    const url = subjectsUrl(template, nodeId)

    if (cursor === null) {
        return url
    }

    return `${url}${url.includes('?') ? '&' : '?'}cursor=${encodeURIComponent(cursor)}`
}

function rowsFrom(data: Record<string, unknown> | null): RunSubjectRow[] {
    return Array.isArray(data?.data) ? (data.data as RunSubjectRow[]) : []
}

/**
 * Who is at one node right now, a page at a time.
 *
 * "Right now" is the whole contract: the schema keeps no per-subject history
 * and nulls current_node_id on every terminal transition, so this can neither
 * list who passed through nor list a node's failures (spec E15). An empty
 * result is ambiguous between two different facts unless `reached` disambiguates
 * it in words: a node the run visited and now has nobody on it, versus a node
 * the run never touched at all. The reached branch must not claim *how* the
 * first group left — RunOverlay's byOutput/failed buckets and
 * SubjectExiter::exit() are three disjoint paths off a node (advanced through a
 * declared output, failed at the node, or cancelled out of the run with no
 * output at all), and this panel has no way to tell which happened to any one
 * subject who is no longer here. "Released" or "passed through" would assert a
 * mechanism this component cannot verify — the card's own counts are the only
 * source of truth for that. The canvas already tells reached apart from
 * never-reached visually (dimmed with no badge versus an explicit 0); this is
 * the same distinction restated in words for the panel a user opens precisely
 * to get the detail the canvas summarised away.
 */
export function SubjectPanel({ template, nodeId, reached }: SubjectPanelProps) {
    const [state, setState] = useState<PanelState>(EMPTY)

    // The freshest nodeId, read by an in-flight request's own resolution rather
    // than by the render that started it. Assigning during render (not via an
    // effect) keeps this correct even when a response for a previous node
    // resolves after the next node has already been selected: by the time that
    // response's .then runs, this ref already holds the new node, so the stale
    // reply is recognised and dropped instead of overwriting the new panel. This
    // is the same class of bug useOverlayPolling.ts guards against with its own
    // in-flight/live checks — a response outliving the context that asked for it.
    const currentNodeId = useRef(nodeId)
    currentNodeId.current = nodeId

    const load = useCallback((cursor: string | null) => {
        const requestedFor = nodeId
        let url: string

        try {
            url = pageUrl(template, requestedFor, cursor)
        } catch (reason: unknown) {
            if (currentNodeId.current !== requestedFor) {
                return
            }

            setState((previous) => ({ ...previous, loading: false, error: String(reason) }))

            return
        }

        void send('GET', url).then((result) => {
            // Drop a reply for a node that is no longer selected rather than
            // writing its rows into the panel now showing a different node.
            if (currentNodeId.current !== requestedFor) {
                return
            }

            if (!result.ok) {
                setState((previous) => ({
                    ...previous,
                    loading: false,
                    error: `Could not load the subjects at this node (HTTP ${result.status}).`,
                }))

                return
            }

            const rows = rowsFrom(result.data)
            const next = typeof result.data?.next_cursor === 'string' ? result.data.next_cursor : null

            setState((previous) => ({
                rows: cursor === null ? rows : [...previous.rows, ...rows],
                nextCursor: next,
                loading: false,
                error: null,
            }))
        })
    }, [nodeId, template])

    useEffect(() => {
        setState(EMPTY)
        load(null)
    }, [load])

    return (
        <div className="space-y-2 p-3">
            <h3 className="text-xs font-semibold text-foreground">Subjects at {nodeId}</h3>
            {state.error !== null && <p role="alert" className="text-[11px] text-destructive">{state.error}</p>}
            {state.loading && <p className="text-[11px] text-muted-foreground">Loading…</p>}
            {!state.loading && state.error === null && state.rows.length === 0 && (
                <p className="text-[11px] text-muted-foreground">
                    {reached
                        ? 'No subjects are here now. This node was reached earlier in the run; the counts on its card show what happened to the subjects that were here.'
                        : 'This node was never reached by this run, so no subject has ever been here.'}
                </p>
            )}
            <ul className="space-y-1">
                {state.rows.map((row) => (
                    <li key={row.id} className="text-[11px] text-muted-foreground">
                        {row.subject_type} #{row.subject_id}
                        {row.last_error !== null && <span className="text-destructive"> — {row.last_error}</span>}
                    </li>
                ))}
            </ul>
            {state.nextCursor !== null && (
                <button
                    type="button"
                    onClick={() => load(state.nextCursor)}
                    className="rounded border border-border px-2 py-1 text-[11px]"
                >
                    Load more
                </button>
            )}
        </div>
    )
}
