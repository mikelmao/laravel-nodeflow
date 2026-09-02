import { createContext, useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { parseFactCatalogue, type FactCatalogue } from './types'

export type FactProviderEndpoint = {
    key: string
    url: string
    contractVersion?: number
    headers?: Record<string, string>
}

export type FactsConfig = { providers: FactProviderEndpoint[] }

export type FactCataloguesState = {
    catalogues: FactCatalogue[]
    loading: boolean
    error: string | null
    retry: () => void
}

const emptyState: FactCataloguesState = {
    catalogues: [], loading: false, error: null, retry: () => undefined,
}

export const FactCataloguesContext = createContext<FactCataloguesState>(emptyState)
const factCatalogueTimeoutMs = 10_000

export function FactCataloguesProvider({ config, children }: { config?: FactsConfig; children: ReactNode }) {
    const [catalogues, setCatalogues] = useState<FactCatalogue[]>([])
    const [loading, setLoading] = useState(false)
    const [error, setError] = useState<string | null>(null)
    const [attempt, setAttempt] = useState(0)
    const retry = useCallback(() => setAttempt((current) => current + 1), [])
    const requestKey = JSON.stringify(config?.providers ?? [])

    useEffect(() => {
        const providers = config?.providers ?? []
        if (providers.length === 0) {
            setCatalogues([])
            setLoading(false)
            setError(null)
            return
        }

        const abort = new AbortController()
        let active = true
        const timeout = globalThis.setTimeout(() => abort.abort(), factCatalogueTimeoutMs)
        setLoading(true)
        setError(null)

        void Promise.all(providers.map(async (provider) => {
            const response = await fetch(provider.url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', ...(provider.headers ?? {}) },
                signal: abort.signal,
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)
            return parseFactCatalogue(await response.json(), provider.key, provider.contractVersion ?? 1)
        })).then((next) => {
            if (active) setCatalogues(next.sort((left, right) => left.provider.localeCompare(right.provider)))
        }).catch(() => {
            if (active) {
                abort.abort()
                setCatalogues([])
                setError('Could not load fact values.')
            }
        }).finally(() => {
            globalThis.clearTimeout(timeout)
            if (active) setLoading(false)
        })

        return () => {
            active = false
            globalThis.clearTimeout(timeout)
            abort.abort()
        }
    }, [attempt, requestKey])

    const state = useMemo<FactCataloguesState>(
        () => ({ catalogues, loading, error, retry }),
        [catalogues, loading, error, retry],
    )

    return <FactCataloguesContext.Provider value={state}>{children}</FactCataloguesContext.Provider>
}
