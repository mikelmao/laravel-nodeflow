import { act, renderHook, waitFor } from '@testing-library/react'
import { StrictMode, type PropsWithChildren } from 'react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { normalizeOverlay } from './overlay'
import { useOverlayPolling } from './useOverlayPolling'

const URL = '/nodeflow/runs/9/overlay'

function envelope(terminal: boolean, waiting: number, status = terminal ? 'completed' : 'running') {
    return { status, terminal, nodes: { wait: { reached: true, byOutput: {}, waiting, failed: 0, error: null } } }
}

function StrictModeWrapper({ children }: PropsWithChildren) {
    return <StrictMode>{children}</StrictMode>
}

beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }))
afterEach(() => vi.useRealTimers())

describe('useOverlayPolling', () => {
    it('replaces the snapshot on each interval', async () => {
        // Counterfactual: fetch once on mount and the view is a still image.
        const fetchMock = vi.fn().mockResolvedValue(Response.json(envelope(false, 4)))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        expect(result.current.snapshot.nodes.wait!.waiting).toBe(9)

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.snapshot.nodes.wait!.waiting).toBe(4))
        expect(fetchMock.mock.calls[0]![0]).toBe(URL)
    })

    it('stops polling once a response is terminal', async () => {
        // Counterfactual: poll on a bare interval with no terminal check and a
        // finished run is requested forever, for as long as the tab is open.
        const fetchMock = vi.fn().mockResolvedValue(Response.json(envelope(true, 0)))
        vi.stubGlobal('fetch', fetchMock)

        renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))

        await act(async () => { await vi.advanceTimersByTimeAsync(5000) })

        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('issues no request at all when the run is already finished', async () => {
        // The common case: an operator opening a completed run. Counterfactual:
        // start the interval unconditionally and every historical run costs a
        // request per interval for nothing.
        const fetchMock = vi.fn().mockResolvedValue(Response.json(envelope(true, 0)))
        vi.stubGlobal('fetch', fetchMock)

        renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(true, 0)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(10_000) })

        expect(fetchMock).not.toHaveBeenCalled()
    })

    it('polls once per interval under Strict Mode, not twice', async () => {
        // Strict Mode replays effect setup and cleanup. Counterfactual: keep the
        // interval id in a module or a ref that cleanup nulls without setup
        // restoring it, and the replayed setup leaves an orphaned interval —
        // doubling the request rate against a six-figure aggregate.
        const fetchMock = vi.fn().mockResolvedValue(Response.json(envelope(false, 1)))
        vi.stubGlobal('fetch', fetchMock)

        renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000), {
            wrapper: StrictModeWrapper,
        })

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    })

    it('never lets a late non-terminal response overwrite a terminal one', async () => {
        // tick() only guards against overlapping requests (inFlight); it does
        // not check snapshot.terminal. inFlight clears the instant a response
        // settles, before React has necessarily committed the resulting state
        // and torn down the interval via the effect's snapshot.terminal
        // dependency. A single continuous advance with no intermediate
        // render-yield lands further ticks in that real (if narrow) gap,
        // rather than letting a waitFor in between close it first.
        // Counterfactual: setSnapshot(next) against the render closure rather
        // than deciding from the value being replaced — proven by running this
        // exact scenario with that change: the trailing non-terminal response
        // both resurrects the finished run and, because it flips
        // snapshot.terminal back to false, restarts polling indefinitely.
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json(envelope(true, 0)))
            .mockResolvedValue(Response.json(envelope(false, 3)))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(6000) })

        expect(result.current.snapshot.terminal).toBe(true)
        expect(result.current.snapshot.nodes.wait!.waiting).toBe(0)
    })

    it('drops a stale response after the url changes while a request is pending', async () => {
        // The live flag's actually-reachable job. Full unmount turned out NOT
        // to exercise it: React 18 already refuses to apply a state update on
        // an unmounted root, silently and without warning, so a test built
        // around unmount passed whether or not the `if (!live) return` guard
        // was present — verified by removing the guard and re-running that
        // version of this test, which still passed. The live flag's real
        // audience is a request from an effect instance that gets superseded
        // by a prop change while still mounted: the old effect's cleanup sets
        // its own `live` to false, but the component (and its state) is very
        // much still alive, so nothing but this flag stops the stale
        // response from writing into the current run's state. Counterfactual:
        // no guard, and a run 9 response that arrives after the hook has
        // moved on to polling run 10 overwrites run 10's state with run 9's.
        let releaseFirst: (value: Response) => void = () => undefined
        const pendingFirst = new Promise<Response>((resolve) => { releaseFirst = resolve })
        const fetchMock = vi.fn()
            .mockReturnValueOnce(pendingFirst)
            .mockResolvedValue(Response.json(envelope(false, 7)))
        vi.stubGlobal('fetch', fetchMock)

        const { result, rerender } = renderHook(
            ({ url }) => useOverlayPolling(url, normalizeOverlay(envelope(false, 9)), 1000),
            { initialProps: { url: URL } },
        )

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        expect(fetchMock).toHaveBeenCalledTimes(1)

        await act(async () => { rerender({ url: '/nodeflow/runs/10/overlay' }) })

        // The stale run-9 request settles only after the hook has moved on.
        await act(async () => { releaseFirst(Response.json(envelope(true, 0))) })

        expect(result.current.snapshot.terminal).toBe(false)
        expect(result.current.error).toBeNull()
    })

    it('stops and reports when the run is gone or the gate was revoked', async () => {
        // Counterfactual: retry every status and a deleted run or a revoked
        // permission produces an endless stream of 404s.
        const fetchMock = vi.fn().mockResolvedValue(Response.json({}, { status: 403 }))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.error).toMatch(/403/))

        await act(async () => { await vi.advanceTimersByTimeAsync(5000) })

        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    it('surfaces a network failure and keeps polling', async () => {
        // send() only rejects on a genuine network failure (http.ts's own
        // contract: HTTP statuses resolve as data). Counterfactual: no .catch()
        // on the request chain, so the rejection never reaches .then(), error
        // stays null forever, and Vitest reports an unhandled rejection.
        const fetchMock = vi.fn()
            .mockRejectedValueOnce(new Error('network down'))
            .mockResolvedValue(Response.json(envelope(false, 5)))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.error).toMatch(/network down/))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.snapshot.nodes.wait!.waiting).toBe(5))
        expect(result.current.error).toBeNull()
    })

    it('keeps polling through a server error while surfacing it', async () => {
        // A 500 or a dropped connection is usually transient. Counterfactual:
        // stop on any failure and one blip freezes a stale overlay that still
        // looks live.
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json({}, { status: 500 }))
            .mockResolvedValue(Response.json(envelope(false, 2)))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.error).toMatch(/500/))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.snapshot.nodes.wait!.waiting).toBe(2))
        expect(result.current.error).toBeNull()
    })

    it('does not stack requests when the server is slower than the interval', async () => {
        // The inFlight guard, which no other test reaches: every other case's
        // fetch mock resolves as an immediate microtask, so a tick never
        // overlaps a still-pending request. Counterfactual: delete the
        // `if (inFlight.current !== null) return` guard in tick() and a server
        // slower than the interval accumulates one request per tick — against an
        // endpoint that runs two grouped aggregates over six-figure tables.
        let release: (value: Response) => void = () => undefined
        const pending = new Promise<Response>((resolve) => { release = resolve })
        const fetchMock = vi.fn().mockReturnValueOnce(pending).mockResolvedValue(Response.json(envelope(false, 1)))
        vi.stubGlobal('fetch', fetchMock)

        renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        // Three interval boundaries pass while the first request is still open.
        await act(async () => { await vi.advanceTimersByTimeAsync(3000) })

        expect(fetchMock).toHaveBeenCalledTimes(1)

        // Once it settles, polling resumes normally.
        await act(async () => { release(Response.json(envelope(false, 4))) })
        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })

        expect(fetchMock).toHaveBeenCalledTimes(2)
    })
})
