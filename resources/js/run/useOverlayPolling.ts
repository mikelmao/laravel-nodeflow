import { useEffect, useRef, useState } from 'react'
import type { OverlaySnapshot } from '../graph/types'
import { send } from '../http'
import { normalizeOverlay } from './overlay'

/**
 * Statuses that mean "there is nothing here to poll for", as opposed to "try
 * again". The run is gone, or this viewer may no longer see it; retrying is
 * noise and, on a shared dashboard left open overnight, a lot of it.
 */
const HALTING_STATUSES = [401, 403, 404, 419]

const DEFAULT_INTERVAL_MS = 5000

export type OverlayPolling = { snapshot: OverlaySnapshot; error: string | null }

/**
 * Poll a run's overlay until the run is terminal.
 *
 * Interval polling rather than broadcasting, because the package does not own
 * queue or messaging infrastructure and requiring Echo would dictate a
 * websocket stack to the host (spec E8). A plain JSON endpoint over the
 * package's own send() rather than an Inertia partial reload, because nothing
 * under resources/js imports Inertia and E4's whole point is that components
 * consume server-authored URLs without knowing the page framework (E14).
 */
export function useOverlayPolling(
    url: string,
    initial: OverlaySnapshot,
    intervalMs: number = DEFAULT_INTERVAL_MS,
): OverlayPolling {
    const [snapshot, setSnapshot] = useState<OverlaySnapshot>(initial)
    const [error, setError] = useState<string | null>(null)
    const [halted, setHalted] = useState(false)

    // Strict Mode replays setup and cleanup, so nothing that must survive a
    // replay may live in a variable cleanup clears. The in-flight marker lives
    // on the hook instance and is only ever cleared by the request that owns
    // it, so a replayed setup sees the true state rather than a blank one.
    const inFlight = useRef<Promise<unknown> | null>(null)

    useEffect(() => {
        // A finished run is polled zero times, not once. The effect re-runs when
        // snapshot.terminal flips, so this is also how polling stops.
        if (snapshot.terminal || halted) {
            return
        }

        let live = true

        const tick = () => {
            // A server slower than the interval must not accumulate requests.
            if (inFlight.current !== null) {
                return
            }

            const request = send('GET', url)
            inFlight.current = request

            void request
                .then((result) => {
                    if (!live) {
                        return
                    }

                    if (!result.ok) {
                        setError(`Could not refresh this run (HTTP ${result.status}).`)

                        if (HALTING_STATUSES.includes(result.status)) {
                            setHalted(true)
                        }

                        return
                    }

                    let next: OverlaySnapshot

                    try {
                        next = normalizeOverlay(result.data)
                    } catch (reason: unknown) {
                        setError(`Could not refresh this run: ${String(reason)}`)

                        return
                    }

                    setError(null)
                    // Decided against the value being replaced, not against a
                    // render-time closure: two responses can be in flight across
                    // an interval boundary, and a late non-terminal one must not
                    // resurrect a run that has already finished — which would
                    // restart polling as well as lie about the run.
                    setSnapshot((previous) => (previous.terminal ? previous : next))
                })
                .finally(() => {
                    if (inFlight.current === request) {
                        inFlight.current = null
                    }
                })
        }

        const id = setInterval(tick, intervalMs)

        return () => {
            live = false
            clearInterval(id)
        }
    }, [halted, intervalMs, snapshot.terminal, url])

    return { snapshot, error }
}
