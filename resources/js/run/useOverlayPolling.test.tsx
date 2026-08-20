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
        // Two responses in flight across one interval boundary. Counterfactual:
        // setSnapshot(next) against the render closure rather than deciding from
        // the value being replaced, and a slow earlier response resurrects a
        // finished run — restarting polling on a run that has ended.
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(Response.json(envelope(true, 0)))
            .mockResolvedValue(Response.json(envelope(false, 3)))
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(() => useOverlayPolling(URL, normalizeOverlay(envelope(false, 9)), 1000))

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(result.current.snapshot.terminal).toBe(true))

        await act(async () => { await vi.advanceTimersByTimeAsync(5000) })

        expect(result.current.snapshot.terminal).toBe(true)
        expect(result.current.snapshot.nodes.wait!.waiting).toBe(0)
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
})
