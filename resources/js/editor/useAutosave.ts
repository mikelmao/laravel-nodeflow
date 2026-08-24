import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
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
    preparePublish(): Promise<number | false>
    /** Release the PUT barrier; a revision means success, while a 409 payload enters conflict state. */
    finishPublish(revision?: number, conflictPayload?: Record<string, unknown> | null): void
    /**
     * 'mine' adopts the server's revision and immediately saves the author's own
     * graph over theirs. 'theirs' adopts the revision and saves nothing - the
     * caller supplies the canonical graph it actually mounted, which can differ
     * from a valid response whose nullable/omitted containers are normalised.
     */
    resolveConflict(choice: 'mine' | 'theirs', acceptedGraph?: Graph): void
}

function isDraftRevision(value: unknown): value is number {
    return typeof value === 'number' && Number.isSafeInteger(value) && value >= 0
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isGraph(value: unknown): value is Graph {
    if (!isObject(value)) {
        return false
    }

    if (value.start !== undefined && value.start !== null && typeof value.start !== 'string') {
        return false
    }

    if (value.nodes !== undefined && value.nodes !== null) {
        if (!Array.isArray(value.nodes) || !value.nodes.every((node) => {
            if (!isObject(node) || typeof node.id !== 'string' || typeof node.type !== 'string') {
                return false
            }

            if (node.config !== undefined && node.config !== null && typeof node.config !== 'object') {
                return false
            }

            return node.position === undefined || (
                isObject(node.position) &&
                typeof node.position.x === 'number' &&
                Number.isFinite(node.position.x) &&
                typeof node.position.y === 'number' &&
                Number.isFinite(node.position.y)
            )
        })) {
            return false
        }
    }

    return value.edges === undefined || value.edges === null || (
        Array.isArray(value.edges) &&
        value.edges.every((edge) => isObject(edge) &&
            typeof edge.from === 'string' &&
            typeof edge.to === 'string' &&
            (edge.output === undefined || edge.output === null || typeof edge.output === 'string'))
    )
}

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
    sessionIdentity = '',
}: {
    url: string
    initialRevision: number
    graph: Graph
    debounceMs?: number
    /** Invalidates a publish lease when its owning endpoint/session changes. */
    sessionIdentity?: string
}): Autosave {
    const serialised = JSON.stringify(graph)

    /** Draft URL plus owner identity prevent callbacks and publish leases crossing sessions. */
    const flowIdentity = useRef({ url, sessionIdentity, epoch: 0 })
    const revision = useRef(initialRevision)
    /** The serialisation the server is known to hold. */
    const baseline = useRef(serialised)
    /** The serialisation waiting to be sent, if any. */
    const pending = useRef<string | null>(null)
    /** When the current pending edit's debounce expires. */
    const pendingDueAt = useRef<number | null>(null)
    /** The request allowed to update state, and the promise publish must await. */
    const activeRequest = useRef<{ id: number; generation: number; body: string; done: Promise<void>; settle: () => void } | null>(null)
    const requestSequence = useRef(0)
    /** Invalidates older responses when publish adopts a newer token. */
    const generation = useRef(0)
    /** The owning publish lease; while present, no draft PUT may cross the POST. */
    const publishBarrier = useRef<number | null>(null)
    /** Synchronously closes the batching gap before React exposes the next lease render. */
    const spentPublishLease = useRef(0)
    const publishTarget = useRef<string | null>(null)
    /** Edits made while POST /publish is in flight become the next draft. */
    const afterPublish = useRef<string | null>(null)
    const mounted = useRef(true)
    const halted = useRef(false)
    const conflict = useRef<DraftConflict | null>(null)
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
    /** Bumped by resolveConflict so the watcher effect reconsiders without the graph changing. */
    const [nudge, setNudge] = useState(0)
    /** Gives every publish generation a callback identity that older finishes cannot own. */
    const [publishLease, setPublishLease] = useState<{ active: number | null; next: number }>({ active: null, next: 1 })

    const [state, setState] = useState<{
        status: AutosaveStatus
        revision: number
        message: string | null
        conflict: DraftConflict | null
        lastSavedAt: number | null
    }>({ status: 'idle', revision: initialRevision, message: null, conflict: null, lastSavedAt: null })

    const ownerEpoch = flowIdentity.current.epoch

    useLayoutEffect(() => {
        if (flowIdentity.current.url === url && flowIdentity.current.sessionIdentity === sessionIdentity) {
            return
        }

        const supersededRequest = activeRequest.current

        flowIdentity.current = { url, sessionIdentity, epoch: flowIdentity.current.epoch + 1 }
        generation.current += 1
        activeRequest.current = null
        supersededRequest?.settle()
        revision.current = initialRevision
        baseline.current = serialised
        pending.current = null
        pendingDueAt.current = null
        publishBarrier.current = null
        publishTarget.current = null
        afterPublish.current = null
        halted.current = false
        conflict.current = null
        setPublishLease((current) => ({ active: null, next: Math.max(current.next, current.active ?? 0) + 1 }))

        if (timer.current !== null) {
            clearTimeout(timer.current)
            timer.current = null
        }

        setState({ status: 'idle', revision: initialRevision, message: null, conflict: null, lastSavedAt: null })
    }, [url, sessionIdentity, initialRevision, serialised])

    useEffect(() => {
        mounted.current = true

        return () => {
            mounted.current = false
            generation.current += 1
            const supersededRequest = activeRequest.current
            activeRequest.current = null
            supersededRequest?.settle()
            publishBarrier.current = null
            publishTarget.current = null
            afterPublish.current = null
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
                timer.current = null
            }
        }
    }, [])

    const run = useCallback((force = false): Promise<void> => {
        if (!mounted.current || flowIdentity.current.epoch !== ownerEpoch) {
            return Promise.resolve()
        }

        if (activeRequest.current !== null) {
            return activeRequest.current.done
        }

        if (halted.current || pending.current === null || !mounted.current || (publishBarrier.current !== null && !force)) {
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

        activeRequest.current = { id: requestId, generation: requestGeneration, body, done, settle: finish }
        setState((current) => ({ ...current, status: 'saving', message: null }))

        const stillOwnsRequest = () =>
            mounted.current &&
            flowIdentity.current.epoch === ownerEpoch &&
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
                    const nextRevision = result.data?.draft_revision
                    const nextGraph = result.data?.graph

                    if (!isDraftRevision(nextRevision) || !isGraph(nextGraph)) {
                        halted.current = true
                        setState((current) => ({
                            ...current,
                            status: 'error',
                            conflict: null,
                            message: 'The draft server returned an invalid conflict response: graph and draft_revision must match the editor protocol.',
                        }))

                        return
                    }

                    halted.current = true
                    conflict.current = {
                        graph: nextGraph,
                        revision: nextRevision,
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

                const nextRevision = result.data?.draft_revision

                if (!isDraftRevision(nextRevision)) {
                    halted.current = true
                    setState((current) => ({
                        ...current,
                        status: 'error',
                        message: 'The draft server returned an invalid draft response: draft_revision must be a non-negative safe integer.',
                    }))

                    return
                }

                revision.current = nextRevision
                baseline.current = body
                const hasNewerEdit = pending.current !== null || afterPublish.current !== null
                setState((current) => ({
                    ...current,
                    status: hasNewerEdit ? 'idle' : 'saved',
                    revision: revision.current,
                    message: null,
                    lastSavedAt: Date.now(),
                }))
            } catch (reason: unknown) {
                if (stillOwnsRequest()) {
                    halted.current = true
                    setState((current) => ({ ...current, status: 'error', message: `Could not reach the server to save this draft: ${String(reason)}` }))
                }
            } finally {
                const ownsRequest = flowIdentity.current.epoch === ownerEpoch &&
                    generation.current === requestGeneration &&
                    activeRequest.current?.id === requestId &&
                    activeRequest.current.generation === requestGeneration

                if (ownsRequest) {
                    activeRequest.current = null
                }
                finish()

                // Preserve the new edit's own debounce. If its timer already
                // expired while this request was active, delay is zero.
                if (ownsRequest && mounted.current && pending.current !== null && !halted.current && publishBarrier.current === null) {
                    const delay = Math.max(0, (pendingDueAt.current ?? Date.now()) - Date.now())

                    if (timer.current !== null) {
                        clearTimeout(timer.current)
                    }
                    timer.current = setTimeout(() => void run(), delay)
                }
            }
        })()

        return done
    }, [ownerEpoch, url])

    useEffect(() => {
        if (flowIdentity.current.epoch !== ownerEpoch) {
            return
        }

        if (publishBarrier.current !== null) {
            afterPublish.current = serialised === publishTarget.current ? null : serialised

            if (afterPublish.current !== null) {
                setState((current) => current.status === 'saved' ? { ...current, status: 'idle' } : current)
            }

            return
        }

        const represented = activeRequest.current?.body ?? baseline.current

        if (serialised === represented) {
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }

            if (activeRequest.current === null && serialised === baseline.current) {
                setState((current) => current.status === 'idle' && current.lastSavedAt !== null
                    ? { ...current, status: 'saved' }
                    : current)
            }

            return
        }

        if (halted.current) {
            return
        }

        pending.current = serialised
        pendingDueAt.current = Date.now() + debounceMs
        setState((current) => current.status === 'saved' ? { ...current, status: 'idle' } : current)

        if (timer.current !== null) {
            clearTimeout(timer.current)
        }

        timer.current = setTimeout(() => void run(), debounceMs)

        return () => {
            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
        }
    }, [serialised, debounceMs, run, nudge, ownerEpoch])

    // A tab hidden mid-debounce would otherwise lose the pending edit. fetch on
    // pagehide is unreliable; visibilitychange fires early enough to be honoured.
    useEffect(() => {
        const flush = () => {
            if (flowIdentity.current.epoch === ownerEpoch && document.visibilityState === 'hidden' && pending.current !== null && !halted.current && mounted.current && publishBarrier.current === null) {
                if (timer.current !== null) {
                    clearTimeout(timer.current)
                }

                pendingDueAt.current = Date.now()
                void run()
            }
        }

        document.addEventListener('visibilitychange', flush)

        return () => document.removeEventListener('visibilitychange', flush)
    }, [run, ownerEpoch])

    const resolveConflict = useCallback((choice: 'mine' | 'theirs', acceptedGraph?: Graph) => {
        if (!mounted.current || flowIdentity.current.epoch !== ownerEpoch) {
            return
        }

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
    }, [ownerEpoch])

    const finishLease = publishLease.active ?? publishLease.next

    const finishPublish = useCallback((nextRevision?: number, conflictPayload?: Record<string, unknown> | null) => {
        if (!mounted.current || flowIdentity.current.epoch !== ownerEpoch || publishBarrier.current !== finishLease) {
            return
        }

        spentPublishLease.current = Math.max(spentPublishLease.current, finishLease)

        const releaseBarrier = () => {
            publishBarrier.current = null
            publishTarget.current = null
            setPublishLease((current) => current.active === finishLease
                ? { active: null, next: Math.max(current.next, finishLease + 1) }
                : current)

            const queued = afterPublish.current
            afterPublish.current = null

            return queued
        }

        if (conflictPayload !== undefined) {
            const conflictRevision = conflictPayload?.draft_revision
            const conflictGraph = conflictPayload?.graph
            const conflictMessage = conflictPayload?.message

            if (!isDraftRevision(conflictRevision) || !isGraph(conflictGraph)) {
                halted.current = true
                conflict.current = null
                releaseBarrier()
                pending.current = null
                pendingDueAt.current = null

                if (timer.current !== null) {
                    clearTimeout(timer.current)
                    timer.current = null
                }

                setState((current) => ({
                    ...current,
                    status: 'error',
                    conflict: null,
                    message: 'The publish server returned an invalid conflict response: graph and draft_revision must match the editor protocol.',
                }))

                return
            }

            halted.current = true
            conflict.current = { graph: conflictGraph, revision: conflictRevision }
            releaseBarrier()
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
                timer.current = null
            }

            setState((current) => ({
                ...current,
                status: 'conflict',
                conflict: conflict.current,
                message: typeof conflictMessage === 'string'
                    ? conflictMessage
                    : 'Someone else edited this flow.',
            }))

            return
        }

        if (nextRevision !== undefined && !isDraftRevision(nextRevision)) {
            halted.current = true
            releaseBarrier()
            pending.current = null
            pendingDueAt.current = null

            if (timer.current !== null) {
                clearTimeout(timer.current)
                timer.current = null
            }

            setState((current) => ({
                ...current,
                status: 'error',
                message: 'The publish server returned an invalid publish revision: draft_revision must be a non-negative safe integer.',
            }))

            return
        }

        if (nextRevision !== undefined) {
            revision.current = nextRevision
            baseline.current = publishTarget.current ?? baseline.current
            setState((current) => ({ ...current, revision: nextRevision }))
        }

        const queued = releaseBarrier()

        if (queued !== null && queued !== baseline.current && !halted.current && mounted.current) {
            pending.current = queued
            pendingDueAt.current = Date.now() + debounceMs

            if (timer.current !== null) {
                clearTimeout(timer.current)
            }
            timer.current = setTimeout(() => void run(), debounceMs)
        }
    }, [debounceMs, run, ownerEpoch, finishLease])

    const preparePublish = useCallback(async (): Promise<number | false> => {
        if (!mounted.current || flowIdentity.current.epoch !== ownerEpoch || publishBarrier.current !== null || finishLease <= spentPublishLease.current) {
            return false
        }

        publishBarrier.current = finishLease
        setPublishLease((current) => current.active === null && current.next === finishLease
            ? { ...current, active: finishLease }
            : current)
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

        if (flowIdentity.current.epoch !== ownerEpoch) {
            return false
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

        if (flowIdentity.current.epoch !== ownerEpoch) {
            return false
        }

        if (halted.current || !mounted.current) {
            finishPublish()

            return false
        }

        return revision.current
    }, [finishPublish, finishLease, run, serialised, ownerEpoch])

    return { ...state, preparePublish, finishPublish, resolveConflict }
}
