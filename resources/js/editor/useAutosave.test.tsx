import { act, renderHook, waitFor } from '@testing-library/react'
import { startTransition, StrictMode, Suspense, type PropsWithChildren } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Graph } from '../graph/types'
import { useAutosave } from './useAutosave'

const URL = '/flows/12/draft'

function graph(start: string): Graph {
    return {
        start,
        nodes: [{ id: start, type: 'core.exit', config: {}, position: { x: 0, y: 0 } }],
        edges: [],
    }
}

function okOnce(revision: number) {
    return vi.fn().mockResolvedValue(Response.json({ draft_revision: revision }))
}

function StrictModeWrapper({ children }: PropsWithChildren) {
    return <StrictMode><Suspense fallback={null}>{children}</Suspense></StrictMode>
}

beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }))
afterEach(() => vi.useRealTimers())

describe('useAutosave', () => {
    // Counterfactual: save on graph object identity => first render autosaves untouched graph.
    it('does not save a graph that has not changed', async () => {
        const fetchMock = okOnce(8)
        vi.stubGlobal('fetch', fetchMock)
        const never = new Promise<void>(() => undefined)
        const { result, rerender } = renderHook(
            (props: { url: string; initialRevision: number; graph: Graph; suspend?: boolean }) => {
                const autosave = useAutosave({ ...props, debounceMs: 500 })

                if (props.suspend) {
                    throw never
                }

                return autosave
            },
            { initialProps: { url: URL, initialRevision: 0, graph: graph('a'), suspend: false }, wrapper: StrictModeWrapper },
        )
        await act(async () => {
            vi.advanceTimersByTime(2000)
        })
        expect(fetchMock).not.toHaveBeenCalled()

        const oldPrepare = result.current.preparePublish
        const oldFinish = result.current.finishPublish

        rerender({ url: URL, initialRevision: 0, graph: graph('pending-a'), suspend: false })
        act(() => {
            startTransition(() => rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-flow'), suspend: true }))
        })
        await act(async () => vi.advanceTimersByTime(500))
        // Counterfactual: mutating flow refs during B's abandoned render cancels A's committed pending save.
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(fetchMock.mock.calls[0]![0]).toBe(URL)
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body)).toMatchObject({ graph: { start: 'pending-a' }, draft_revision: 0 })

        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-flow'), suspend: false })
        await act(async () => vi.advanceTimersByTime(2000))
        // Counterfactual: treating graph/url changes alike autosaves one flow's mounted graph into another flow.
        expect(fetchMock).toHaveBeenCalledTimes(1)
        await act(async () => expect(oldPrepare()).resolves.toBe(false))
        act(() => oldFinish(99))
        // Counterfactual: callbacks captured by the previous page can replace the new flow's revision or barrier.
        expect(result.current.revision).toBe(7)

        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-edit'), suspend: false })
        await act(async () => vi.advanceTimersByTime(500))
        expect(fetchMock).toHaveBeenCalledTimes(2)
        // Counterfactual: an old run closure writes a genuine new-flow edit through the previous flow's endpoint and token.
        expect(fetchMock.mock.calls[1]![0]).toBe('/flows/13/draft')
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(7)
    })

    // Counterfactual: drop clearTimeout => every keystroke gets its own request.
    it('coalesces two changes inside the debounce window into one save', async () => {
        const fetchMock = okOnce(1)
        vi.stubGlobal('fetch', fetchMock)
        const { rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 500 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        rerender({ graph: graph('c') })
        await act(async () => {
            vi.advanceTimersByTime(600)
        })
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).graph.start).toBe('c')
    })

    // Token roundtrip. Counterfactual: timestamp/nothing => every post-first save is stale.
    it('sends the revision it holds and adopts the one it is given', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(Response.json({ draft_revision: 1 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.revision).toBe(1))
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).draft_revision).toBe(0)
        expect(result.current.status).toBe('saved')
        const lastSavedAt = result.current.lastSavedAt
        rerender({ graph: graph('c') })
        // Counterfactual: leaving "saved" visible after another edit tells the author unsaved work is durable.
        expect(result.current.status).toBe('idle')
        expect(result.current.lastSavedAt).toBe(lastSavedAt)
        rerender({ graph: graph('b') })
        // Counterfactual: clearing the pending body without restoring status leaves a clean graph looking dirty.
        expect(result.current.status).toBe('saved')
        expect(result.current.lastSavedAt).toBe(lastSavedAt)
        rerender({ graph: graph('c') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.revision).toBe(2))
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(1)
    })

    // Edit during network. Counterfactual: clear pending after response loses edit; concurrent second request with same token 409s.
    it('queues only the latest graph while one save is in flight, then sends it with the returned revision', async () => {
        let resolveFirst!: (response: Response) => void
        const first = new Promise<Response>((resolve) => {
            resolveFirst = resolve
        })
        const fetchMock = vi.fn().mockReturnValueOnce(first).mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(1)
        rerender({ graph: graph('c') })
        rerender({ graph: graph('latest') })
        await act(async () => resolveFirst(Response.json({ draft_revision: 1 })))
        // Counterfactual: the old request's success labels the newer queued edit as durable.
        expect(result.current.status).toBe('idle')
        expect(fetchMock).toHaveBeenCalledTimes(1)
        await act(async () => vi.advanceTimersByTime(9))
        expect(fetchMock).toHaveBeenCalledTimes(1)
        await act(async () => vi.advanceTimersByTime(1))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body)).toMatchObject({ graph: { start: 'latest' }, draft_revision: 1 })
        await waitFor(() => expect(result.current.revision).toBe(2))
    })

    // Counterfactual: leave abandoned C pending after return to active B => completion saves abandoned edit.
    it('clears a queued edit when the graph returns to the active request body', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => {
            resolveSave = resolve
        }))
        vi.stubGlobal('fetch', fetchMock)
        const { rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        rerender({ graph: graph('abandoned') })
        rerender({ graph: graph('b') })
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => vi.advanceTimersByTime(100))
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // Counterfactual: treat 409 as ordinary failure => retry forever or silently overwrite a colleague.
    it('stops on a 409 and exposes the newer graph rather than discarding either side', async () => {
        const theirs: Graph = {
            start: null,
            nodes: [
                { id: 'plain', type: 'core.exit' },
                { id: 'nullable', type: 'core.exit', config: null, position: { x: 1.5, y: -2 } },
                { id: 'array', type: 'core.exit', config: [] },
                { id: 'object', type: 'core.exit', config: {} },
            ],
            edges: [
                { from: 'plain', to: 'nullable' },
                { from: 'nullable', to: 'array', output: null },
                { from: 'array', to: 'object', output: 'default' },
            ],
        }
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ message: 'Someone else edited this flow.', graph: theirs, draft_revision: 9 }, { status: 409 })))
        const { result, rerender, unmount } = renderHook(
            (props: { url: string; initialRevision: number; graph: Graph }) => useAutosave({ ...props, debounceMs: 10 }),
            { initialProps: { url: URL, initialRevision: 0, graph: graph('a') } },
        )
        rerender({ url: URL, initialRevision: 0, graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('conflict'))
        expect(result.current.conflict?.graph).toEqual(theirs)
        expect(result.current.conflict?.revision).toBe(9)
        expect(result.current.message).toContain('Someone else edited')
        const callsSoFar = (globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length
        rerender({ url: URL, initialRevision: 0, graph: graph('mine-again') })
        await act(async () => {
            vi.advanceTimersByTime(500)
        })
        expect((globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length).toBe(callsSoFar)

        const oldResolve = result.current.resolveConflict
        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-flow') })
        expect(result.current).toMatchObject({ status: 'idle', revision: 7, conflict: null, message: null })
        act(() => oldResolve('mine'))
        await act(async () => vi.advanceTimersByTime(50))
        // Counterfactual: resolving a conflict captured by the previous flow dirties or changes the new flow.
        expect(result.current).toMatchObject({ status: 'idle', revision: 7, conflict: null, message: null })
        expect((globalThis.fetch as ReturnType<typeof vi.fn>).mock.calls.length).toBe(callsSoFar)

        unmount()

        // Counterfactual: Number('9') accepts a response whose concurrency token is not the documented integer.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ draft_revision: '9' })))
        const malformedSuccess = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        malformedSuccess.rerender({ graph: graph('malformed-success') })
        await act(async () => vi.advanceTimersByTime(50))
        await waitFor(() => expect(malformedSuccess.result.current.status).toBe('error'))
        expect(malformedSuccess.result.current.message).toMatch(/invalid draft response/i)
        expect(malformedSuccess.result.current.revision).toBe(0)
        malformedSuccess.unmount()

        for (const payload of [
            { message: 'Conflict', graph: graph('theirs'), draft_revision: '9' },
            { message: 'Conflict', graph: { start: null, nodes: [{ id: 'broken', type: 'core.exit', config: 1 }], edges: [] }, draft_revision: 9 },
        ]) {
            // Counterfactual: a cast fabricates conflict state from a malformed token or graph that FlowEditor cannot safely mount.
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(payload, { status: 409 })))
            const malformedConflict = renderHook(
                (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
                { initialProps: { graph: graph('a') } },
            )
            malformedConflict.rerender({ graph: graph('mine') })
            await act(async () => vi.advanceTimersByTime(50))
            await waitFor(() => expect(malformedConflict.result.current.status).toBe('error'))
            expect(malformedConflict.result.current.message).toMatch(/invalid conflict response/i)
            expect(malformedConflict.result.current.conflict).toBeNull()
            malformedConflict.unmount()
        }
    })

    // Counterfactual: mine leaves revision untouched => retry 409 forever.
    it('resolving with "mine" adopts their revision and saves mine over it', async () => {
        const fetchMock = vi
            .fn()
            .mockResolvedValueOnce(Response.json({ message: 'Conflict', graph: graph('theirs'), draft_revision: 9 }, { status: 409 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 10 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('conflict'))
        act(() => result.current.resolveConflict('mine'))
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.revision).toBe(10))
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(9)
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).graph.start).toBe('mine')
    })

    // Counterfactual: raw response baseline differs from canonical toCanvas() output and abandoned mine is immediately saved back.
    it('resolving with "theirs" saves nothing when the mounted graph canonicalises their wire shape', async () => {
        const theirs: Graph = { start: 'theirs', nodes: [{ id: 'theirs', type: 'core.exit' }], edges: null }
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ message: 'Conflict', graph: theirs, draft_revision: 9 }, { status: 409 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('mine') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('conflict'))
        const canonical = graph('theirs')
        act(() => result.current.resolveConflict('theirs', canonical))
        rerender({ graph: canonical })
        await act(async () => {
            vi.advanceTimersByTime(500)
        })
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(result.current.status).toBe('idle')
    })

    // Counterfactual: parse a 419 as JSON => SyntaxError instead of reload guidance.
    it('reports an expired session in words the author can act on', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('<html>Page Expired</html>', { status: 419 })))
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(result.current.status).toBe('error'))
        expect(result.current.message).toMatch(/session/i)
    })

    // Publish does not reset revision. Counterfactual: drop finishPublish adoption => next save 409s an empty graph.
    it('adopts the revision publish hands back', async () => {
        const fetchMock = okOnce(6)
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        act(() => result.current.finishPublish(5))
        rerender({ graph: graph('b') })
        await act(async () => {
            vi.advanceTimersByTime(50)
        })
        await waitFor(() => expect(fetchMock).toHaveBeenCalled())
        expect(JSON.parse(fetchMock.mock.calls[0]![1].body).draft_revision).toBe(5)

        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        rerender({ graph: graph('queued-after-invalid-publish') })
        act(() => result.current.finishPublish(Number.NaN))
        expect(result.current.status).toBe('error')
        expect(result.current.message).toMatch(/invalid publish revision/i)
        expect(result.current.revision).toBe(6)
        await act(async () => vi.advanceTimersByTime(100))
        // Counterfactual: adopting NaN both corrupts the token and sends a queued PUT with it.
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // Counterfactual: releasing the barrier when prepare resolves allows a PUT to cross the POST and recreate a draft.
    it('holds edits behind the publish barrier until finishPublish releases them', async () => {
        let resolveSave!: (response: Response) => void
        const first = new Promise<Response>((resolve) => {
            resolveSave = resolve
        })
        const fetchMock = vi.fn()
            .mockReturnValueOnce(first)
            .mockResolvedValueOnce(Response.json({ draft_revision: 2 }))
            .mockResolvedValueOnce(Response.json({ draft_revision: 3 }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(1)
        const preparation = result.current.preparePublish()
        let overlappingPreparation: boolean | null = null
        const overlapping = result.current.preparePublish().then((ready) => {
            overlappingPreparation = ready
        })
        await act(async () => Promise.resolve())
        // Counterfactual: a second prepare awaiting the same PUT can later publish against refs owned by the first caller.
        expect(overlappingPreparation).toBe(false)
        await act(async () => overlapping)
        rerender({ graph: graph('during-publish') })
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => expect(preparation).resolves.toBe(true))
        // Counterfactual: a prepare after the first is ready can replace its target and launch a PUT before its POST finishes.
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(false))
        await act(async () => vi.advanceTimersByTime(100))
        // Counterfactual: overlapping ownership lets the later prepare cross the first caller's still-active barrier.
        expect(fetchMock).toHaveBeenCalledTimes(1)
        const finishFirst = result.current.finishPublish
        const prepareFirst = result.current.preparePublish
        let sameBatchPreparation!: Promise<boolean>
        act(() => {
            finishFirst(1)
            sameBatchPreparation = prepareFirst()
        })
        // Counterfactual: React has not rerendered yet, so P1's prepare callback can try to reclaim its spent lease.
        await act(async () => expect(sameBatchPreparation).resolves.toBe(false))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        expect(fetchMock).toHaveBeenCalledTimes(2)
        expect(result.current.revision).toBe(2)

        const finishSecond = result.current.finishPublish
        rerender({ graph: graph('during-second-publish') })
        act(() => finishFirst(99))
        expect(result.current.revision).toBe(2)
        await act(async () => vi.advanceTimersByTime(100))
        // Counterfactual: P1's delayed finish releases P2's barrier and poisons its revision.
        expect(fetchMock).toHaveBeenCalledTimes(2)

        act(() => finishSecond(2))
        await act(async () => vi.advanceTimersByTime(10))
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))
        // Counterfactual: replacing afterPublish loses the edit queued behind the owning publish caller's target.
        expect(JSON.parse(fetchMock.mock.calls[2]![1].body)).toMatchObject({ graph: { start: 'during-second-publish' }, draft_revision: 2 })

        await waitFor(() => expect(result.current.revision).toBe(3))
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        act(() => result.current.finishPublish(3))
    })

    // Counterfactual: stale afterPublish creates a draft that is no longer on screen.
    it('clears a post-publish edit when the graph returns to the publish target', async () => {
        const fetchMock = okOnce(1)
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        rerender({ graph: graph('abandoned') })
        rerender({ graph: graph('a') })
        act(() => result.current.finishPublish(0))
        await act(async () => vi.advanceTimersByTime(100))
        expect(fetchMock).not.toHaveBeenCalled()
    })

    // Counterfactual: merely awaiting active requests lets POST overtake an edit still inside its debounce.
    it('preparePublish force-flushes and awaits an unexpired debounce', async () => {
        let resolveSave!: (response: Response) => void
        const fetchMock = vi.fn(() => new Promise<Response>((resolve) => {
            resolveSave = resolve
        }))
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('b') })
        let prepared: boolean | null = null
        const preparation = result.current.preparePublish().then((value) => {
            prepared = value
        })
        await act(async () => Promise.resolve())
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(prepared).toBeNull()
        await act(async () => resolveSave(Response.json({ draft_revision: 1 })))
        await act(async () => preparation)
        expect(prepared).toBe(true)
        expect(result.current.revision).toBe(1)
    })

    // Counterfactual: true would let Task 8 publish through an unresolved conflict.
    it('preparePublish returns false when draft saving halts on a conflict', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json({ message: 'Conflict', graph: graph('theirs'), draft_revision: 9 }, { status: 409 })))
        const { result, rerender } = renderHook(
            (props: { graph: Graph }) => useAutosave({ url: URL, initialRevision: 0, graph: props.graph, debounceMs: 10 }),
            { initialProps: { graph: graph('a') } },
        )
        rerender({ graph: graph('mine') })
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(false))
        expect(result.current.status).toBe('conflict')
    })

    // Counterfactual: request completion launches a queued PUT after editor unmount.
    it('invalidates an in-flight request and its queued graph on unmount', async () => {
        let resolveOldSave!: (response: Response) => void
        let resolveNewSave!: (response: Response) => void
        const oldSave = new Promise<Response>((resolve) => {
            resolveOldSave = resolve
        })
        const newSave = new Promise<Response>((resolve) => {
            resolveNewSave = resolve
        })
        const fetchMock = vi.fn().mockReturnValueOnce(oldSave).mockReturnValueOnce(newSave)
        vi.stubGlobal('fetch', fetchMock)
        const { result, rerender, unmount } = renderHook(
            (props: { url: string; initialRevision: number; graph: Graph }) => useAutosave({ ...props, debounceMs: 10 }),
            { initialProps: { url: URL, initialRevision: 0, graph: graph('a') } },
        )
        rerender({ url: URL, initialRevision: 0, graph: graph('b') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(1)

        const oldFinish = result.current.finishPublish
        let oldPrepared: boolean | null = null
        const oldPreparation = result.current.preparePublish().then((ready) => {
            oldPrepared = ready
        })
        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-flow') })
        await act(async () => Promise.resolve())
        // Counterfactual: navigation leaves the old preparation awaiting a request that no longer owns editor state.
        expect(oldPrepared).toBe(false)
        await act(async () => oldPreparation)
        act(() => oldFinish(99))
        // Counterfactual: the previous flow's finish callback adopts its publish token into the newly mounted flow.
        expect(result.current.revision).toBe(7)

        await act(async () => resolveOldSave(Response.json({ draft_revision: 1 })))
        await act(async () => vi.advanceTimersByTime(100))
        // Counterfactual: an invalidated completion updates state or launches the navigation graph as a queued PUT.
        expect(fetchMock).toHaveBeenCalledTimes(1)
        expect(result.current.status).toBe('idle')
        expect(result.current.revision).toBe(7)

        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('new-edit') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(2)
        // Counterfactual: a real edit after navigation reuses the old request's URL or revision.
        expect(fetchMock.mock.calls[1]![0]).toBe('/flows/13/draft')
        expect(JSON.parse(fetchMock.mock.calls[1]![1].body).draft_revision).toBe(7)

        rerender({ url: '/flows/13/draft', initialRevision: 7, graph: graph('queued') })
        await act(async () => vi.advanceTimersByTime(20))
        expect(fetchMock).toHaveBeenCalledTimes(2)
        unmount()
        await act(async () => resolveNewSave(Response.json({ draft_revision: 8 })))
        expect(fetchMock).toHaveBeenCalledTimes(2)
    })

    it('resets the publish lease when an owning publish identity changes without changing the draft URL', async () => {
        const { result, rerender } = renderHook(
            (props: { identity: string }) => useAutosave({ url: URL, sessionIdentity: props.identity, initialRevision: 4, graph: graph('stable'), debounceMs: 10 }),
            { initialProps: { identity: 'publish-old' } },
        )
        const oldFinish = result.current.finishPublish
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))

        rerender({ identity: 'publish-new' })
        await act(async () => expect(result.current.preparePublish()).resolves.toBe(true))
        act(() => oldFinish(99))
        expect(result.current.revision).toBe(4)
    })
})
