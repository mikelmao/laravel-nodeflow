import { act, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useContext } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { FactCataloguesContext, FactCataloguesProvider } from './FactCataloguesContext'

const response = (key: string) => ({
    contract_version: 1,
    revision: `${key}-revision`,
    facts: [{
        key: 'profile.segment', version: 1, label: `${key} segment`, type: 'text',
        capabilities: ['runtime_condition'], operators: { runtime_condition: ['equals'] },
        options: [], missing_behavior: 'route_no',
    }],
})

function Probe() {
    const state = useContext(FactCataloguesContext)
    return <div>
        <span>{state.loading ? 'loading' : state.error ?? state.catalogues.map((item) => item.provider).join(',')}</span>
        <button type="button" onClick={state.retry}>Retry</button>
    </div>
}

afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
})

describe('FactCataloguesProvider', () => {
    it('loads multiple configured providers and qualifies their definitions', async () => {
        const fetch = vi.fn(async (url: string) => ({
            ok: true,
            json: async () => response(url.includes('alpha') ? 'alpha' : 'zulu'),
        }))
        vi.stubGlobal('fetch', fetch)

        render(<FactCataloguesProvider config={{ providers: [
            { key: 'zulu', url: '/facts/zulu' },
            { key: 'alpha', url: '/facts/alpha' },
        ] }}><Probe /></FactCataloguesProvider>)

        expect(screen.getByText('loading')).toBeInTheDocument()
        expect(await screen.findByText('alpha,zulu')).toBeInTheDocument()
        expect(fetch).toHaveBeenCalledTimes(2)
    })

    it('clears partial data on failure and retries all providers', async () => {
        let attempt = 0
        const fetch = vi.fn(async () => {
            attempt++
            if (attempt === 1) return { ok: false, status: 503, json: async () => ({}) }
            return { ok: true, json: async () => response('alpha') }
        })
        vi.stubGlobal('fetch', fetch)

        render(<FactCataloguesProvider config={{ providers: [{ key: 'alpha', url: '/facts/alpha' }] }}>
            <Probe />
        </FactCataloguesProvider>)

        expect(await screen.findByText('Could not load fact values.')).toBeInTheDocument()
        await userEvent.click(screen.getByRole('button', { name: 'Retry' }))
        await waitFor(() => expect(screen.getByText('alpha')).toBeInTheDocument())
        expect(fetch).toHaveBeenCalledTimes(2)
    })

    it('fails a stalled catalogue request after a bounded deadline', async () => {
        vi.useFakeTimers()
        const signals: AbortSignal[] = []
        vi.stubGlobal('fetch', vi.fn((_url: string, init: RequestInit) => {
            const signal = init.signal as AbortSignal
            signals.push(signal)
            return new Promise((_resolve, reject) => signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError'))))
        }))

        render(<FactCataloguesProvider config={{ providers: [{ key: 'alpha', url: '/facts/alpha' }] }}>
            <Probe />
        </FactCataloguesProvider>)

        await act(async () => vi.advanceTimersByTimeAsync(10_000))

        expect(screen.getByText('Could not load fact values.')).toBeInTheDocument()
        expect(signals).toHaveLength(1)
        expect(signals[0]?.aborted).toBe(true)
    })

    it('cancels sibling catalogue requests when one provider fails', async () => {
        let hangingSignal: AbortSignal | undefined
        vi.stubGlobal('fetch', vi.fn((url: string, init: RequestInit) => {
            if (url.includes('failed')) return Promise.resolve({ ok: false, status: 503, json: async () => ({}) })
            hangingSignal = init.signal as AbortSignal
            return new Promise((_resolve, reject) => hangingSignal?.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError'))))
        }))

        render(<FactCataloguesProvider config={{ providers: [
            { key: 'failed', url: '/facts/failed' },
            { key: 'hanging', url: '/facts/hanging' },
        ] }}><Probe /></FactCataloguesProvider>)

        expect(await screen.findByText('Could not load fact values.')).toBeInTheDocument()
        expect(hangingSignal?.aborted).toBe(true)
    })
})
