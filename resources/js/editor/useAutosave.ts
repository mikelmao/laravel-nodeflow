import { useCallback, useEffect, useRef, useState } from 'react'
import type { Graph } from '../graph/types'
import { send } from '../http'

export type AutosaveStatus = 'idle' | 'saving' | 'saved' | 'conflict' | 'error'

export type DraftConflict = { graph: Graph; revision: number }

export type Autosave = {
    status: AutosaveStatus
    revision: number
    message: string | null
    conflict: DraftConflict | null
    lastSavedAt: number | null
    /** Serialize publish after every accepted draft PUT; false means conflict/error halted saving. */
    preparePublish(): Promise<boolean>
    /** Release the PUT barrier; a revision means publish succeeded, omission means it failed. */
    finishPublish(revision?: number): void
    /**
     * 'mine' adopts the server's revision and immediately saves the author's own
     * graph over theirs. 'theirs' adopts the revision and saves nothing - the
     * caller supplies the canonical graph it actually mounted, which can differ
     * from a valid response whose nullable/omitted containers are normalised.
     */
    resolveConflict(choice: 'mine' | 'theirs', acceptedGraph?: Graph): void
}

const EMPTY_GRAPH: Graph = { start: '', nodes: [], edges: [] }

/**
 * Debounced draft autosave (5.9), with the 409 as a state rather than an error.
 *
 * Change detection is by serialised comparison, not object identity: the editor
 * rebuilds its graph on every render, so identity cannot tell an edit from a
 * re-render and a hook keyed on it would autosave forever on an untouched
 * canvas. Canvas positions remain untouched through the round trip: a real drag
 * is a graph edit, while a re-render at the same coordinates serialises exactly
 * the same way.
 *
 * The token is draft_revision, an integer, and never draft_updated_at: Laravel
 * stores timestamps at second precision and a debounced autosave saves several
 * times per second, so a timestamp token silently stops detecting.
 *
 * A 409 halts the loop. Continuing would either keep failing or, once the
 * revision were adopted silently, overwrite exactly the colleague's work the 409
 * exists to protect.
 */
export function useAutosave({
    url,
    initialRevision,
    graph,
    debounceMs = 1000,
}: {
    url: string
    initialRevision: number
    graph: Graph
    debounceMs?: number
}): Autosave {
    const serialised = JSON.stringify(graph)

    const revision = useRef(initialRevision)
    /** The serialisation the server is known to hold. */
    const baseline = useRef(serialised)
    /** The serialisation waiting to be sent, if any. */
    const pending = useRef<string | null>(null)
    /** When the current pending edit's debounce expires. */
    const pendingDueAt = useRef<number | null>(null)
    /** The request allowed to update state, and the promise publish must await. */
    const activeRequest = useRef<{ id: number; generation: number; body: string; done: Promise<void> } | null>(null)
    const requestSequence = useRef(0)
    /** Invalidates older responses when publish adopts a newer token. */
    const generation = useRef(0)
    /** True from preparePublish until finishPublish: no draft PUT may cross the POST. */
    const publishBarrier = useRef(false)
    const publishTarget = useRef<string | null>(null)
    /** Edits made while POST /publish is in flight become the next draft. */
    const afterPublish = useRef<string | null>(null)
    const mounted = useRef(true)
    const halted = useRef(false)
    const conflict = useRef<DraftConflict | null>(null)
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
    /** Bumped by resolveConflict so the watcher effect reconsiders without the graph changing. */
    const [nudge, setNudge] = useState(0)

    const [state, setState] = useState<{
        status: AutosaveStatus
        revision: number
        message: string | null
        conflict: DraftConflict | null
        lastSavedAt: number | null
    }>({ status: 'idle', revision: initialRevision, message: null, conflict: null, lastSavedAt: null })

    useEffect(() => {
        mounted.current = true

        return () => {
            mounted.current = false
            generation.current += 1
            publishBarrier.current = false
            publishTarget.current = null
            afterPublish.current = null
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
        }
    }, [])

    const run = useCallback((force = false): Promise<void> => {
        if (activeRequest.current !== null) {
            return activeRequest.current.done
        }

        if (halted.current || pending.current === null || !mounted.current || (publishBarrier.current && !force)) {
            return Promise.resolve()
        }

        const body = pending.current
        pending.current = null
        pendingDueAt.current = null
        const requestId = ++requestSequence.current
        const requestGeneration = generation.current
        let finish!: () => void
        const done = new Promise<void>((resolve) => {
            finish = resolve
        })

        activeRequest.current = { id: requestId, generation: requestGeneration, body, done }
        setState((current) => ({ ...current, status: 'saving', message: null }))

        const stillOwnsRequest = () =>
            mounted.current &&
            generation.current === requestGeneration &&
            activeRequest.current?.id === requestId &&
            activeRequest.current.generation === requestGeneration

        void (async () => {
            try {
                const result = await send('PUT', url, { graph: JSON.parse(body) as Graph, draft_revision: revision.current })

                if (!stillOwnsRequest()) {
                    return
                }

                if (result.status === 409) {
                    halted.current = true
                    conflict.current = {
                        graph: (result.data?.graph as Graph | undefined) ?? EMPTY_GRAPH,
                        revision: Number(result.data?.draft_revision ?? revision.current),
                    }
                    setState((current) => ({
                        ...current,
                        status: 'conflict',
                        conflict: conflict.current,
                        message: typeof result.data?.message === 'string' ? result.data.message : 'Someone else edited this flow.',
                    }))

                    return
                }

                if (result.status === 419) {
                    halted.current = true
                    setState((current) => ({ ...current, status: 'error', message: 'Your session expired before this draft could be saved. Reload the page and check your last few changes.' }))

                    return
                }

                if (!result.ok) {
                    halted.current = true
                    setState((current) => ({
                        ...current,
                        status: 'error',
                        message: `The server refused this draft (HTTP ${result.status}). Your changes are still on screen but are not saved.`,
                    }))

                    return
                }

                revision.current = Number(result.data?.draft_revision ?? revision.current)
                baseline.current = body
                setState((current) => ({ ...current, status: 'saved', revision: revision.current, message: null, lastSavedAt: Date.now() }))
            } catch (reason: unknown) {
                if (stillOwnsRequest()) {
                    halted.current = true
                    setState((current) => ({ ...current, status: 'error', message: `Could not reach the server to save this draft: ${String(reason)}` }))
                }
            } finally {
                if (activeRequest.current?.id === requestId) {
                    activeRequest.current = null
                }
                finish()

                // Preserve the new edit's own debounce. If its timer already
                // expired while this request was active, delay is zero.
                if (mounted.current && pending.current !== null && !halted.current && !publishBarrier.current) {
                    const delay = Math.max(0, (pendingDueAt.current ?? Date.now()) - Date.now())

                    if (timer.current !== null) {
                        clearTimeout(timer.current)
                    }
                    timer.current = setTimeout(() => void run(), delay)
                }
            }
        })()

        return done
    }, [url])

    useEffect(() => {
        if (publishBarrier.current) {
            afterPublish.current = serialised === publishTarget.current ? null : serialised

            return
        }

        const represented = activeRequest.current?.body ?? baseline.current

        if (serialised === represented) {
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }

            return
        }

        if (halted.current) {
            return
        }

        pending.current = serialised
        pendingDueAt.current = Date.now() + debounceMs

        if (timer.current !== null) {
            clearTimeout(timer.current)
        }

        timer.current = setTimeout(() => void run(), debounceMs)

        return () => {
            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
        }
    }, [serialised, debounceMs, run, nudge])

    // A tab hidden mid-debounce would otherwise lose the pending edit. fetch on
    // pagehide is unreliable; visibilitychange fires early enough to be honoured.
    useEffect(() => {
        const flush = () => {
            if (document.visibilityState === 'hidden' && pending.current !== null && !halted.current && mounted.current && !publishBarrier.current) {
                if (timer.current !== null) {
                    clearTimeout(timer.current)
                }

                pendingDueAt.current = Date.now()
                void run()
            }
        }

        document.addEventListener('visibilitychange', flush)

        return () => document.removeEventListener('visibilitychange', flush)
    }, [run])

    const resolveConflict = useCallback((choice: 'mine' | 'theirs', acceptedGraph?: Graph) => {
        const theirs = conflict.current

        if (theirs === null) {
            return
        }

        if (choice === 'theirs' && acceptedGraph === undefined) {
            throw new Error('resolveConflict("theirs") requires the canonical graph mounted by the caller.')
        }

        revision.current = theirs.revision
        generation.current += 1
        conflict.current = null
        halted.current = false
        pending.current = null
        pendingDueAt.current = null

        // 'theirs': the caller has replaced its canvas with the canonical graph
        // it can actually mount. Use that exact local baseline, not a valid raw
        // response whose null/omitted containers toCanvas() normalised.
        // 'mine': blank the baseline so the watcher sees the author's graph as a
        // change and saves it over theirs, with their revision as the token.
        baseline.current = choice === 'theirs' ? JSON.stringify(acceptedGraph) : ''

        setState((current) => ({ ...current, status: 'idle', conflict: null, message: null, revision: theirs.revision }))
        setNudge((count) => count + 1)
    }, [])

    const finishPublish = useCallback((nextRevision?: number) => {
        if (!publishBarrier.current) {
            return
        }

        if (nextRevision !== undefined) {
            revision.current = nextRevision
            baseline.current = publishTarget.current ?? baseline.current
            setState((current) => ({ ...current, revision: nextRevision }))
        }

        publishBarrier.current = false
        publishTarget.current = null

        const queued = afterPublish.current
        afterPublish.current = null

        if (queued !== null && queued !== baseline.current && !halted.current && mounted.current) {
            pending.current = queued
            pendingDueAt.current = Date.now() + debounceMs

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
            timer.current = setTimeout(() => void run(), debounceMs)
        }
    }, [debounceMs, run])

    const preparePublish = useCallback(async (): Promise<boolean> => {
        publishBarrier.current = true
        publishTarget.current = serialised
        afterPublish.current = null

        if (timer.current !== null) {
            clearTimeout(timer.current)
        }

        // A PUT already handed to fetch can still mutate the server even if the
        // client ignores its response. Publish must therefore wait for it.
        if (activeRequest.current !== null) {
            await activeRequest.current.done
        }

        if (halted.current || !mounted.current) {
            finishPublish()

            return false
        }

        // Flush the exact graph captured by the publish click. Later edits are
        // held separately by the barrier and become a new draft after POST ends.
        if (publishTarget.current !== baseline.current) {
            pending.current = publishTarget.current
            pendingDueAt.current = Date.now()
            await run(true)
        }

        if (halted.current || !mounted.current) {
            finishPublish()

            return false
        }

        return true
    }, [finishPublish, run, serialised])

    return { ...state, preparePublish, finishPublish, resolveConflict }
}
