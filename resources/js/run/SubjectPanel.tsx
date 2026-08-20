import { useCallback, useEffect, useState } from 'react'
import type { RunSubjectRow } from '../graph/types'
import { send, subjectsUrl } from '../http'

export type SubjectPanelProps = { template: string; nodeId: string }

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
 * list who passed through nor list a node's failures (spec E15). The empty
 * state therefore says "no subjects are here now" rather than showing a bare
 * empty list, because an operator reading nothing at all would take it for a
 * node the run never reached — a different fact entirely.
 */
export function SubjectPanel({ template, nodeId }: SubjectPanelProps) {
    const [state, setState] = useState<PanelState>(EMPTY)

    const load = useCallback((cursor: string | null) => {
        let url: string

        try {
            url = pageUrl(template, nodeId, cursor)
        } catch (reason: unknown) {
            setState((previous) => ({ ...previous, loading: false, error: String(reason) }))

            return
        }

        void send('GET', url).then((result) => {
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
                    No subjects are here now. This node may have released everyone already.
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
