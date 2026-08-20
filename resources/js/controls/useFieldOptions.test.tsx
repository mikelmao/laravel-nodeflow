import { act, renderHook, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import {
    FieldOptionsContext,
    type FieldOptionsSource,
    fieldOptionsKey,
    useFieldOptions,
} from './useFieldOptions'

const TEMPLATE = '/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'
const NEXT_TEMPLATE = '/v2/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return {
        key: 'template',
        type: 'select',
        label: 'Template',
        help: null,
        default: null,
        required: false,
        options: {},
        dynamic_options: false,
        ...overrides,
    }
}

function wrapper(cache = new Map<string, Record<string, string>>()) {
    return ({ children }: { children: ReactNode }) => (
        <FieldOptionsContext.Provider value={{ template: TEMPLATE, cache }}>
            {children}
        </FieldOptionsContext.Provider>
    )
}

describe('useFieldOptions', () => {
    // Counterfactual: concatenate the strings with a separator and distinct
    // node-type/field-key tuples cross-wire tenant-scoped option results.
    it('encodes the node type and field key as an unambiguous tuple', () => {
        expect(fieldOptionsKey('a b', 'c')).not.toBe(fieldOptionsKey('a', 'b c'))
        expect(fieldOptionsKey('app.send', 'template')).not.toBe(fieldOptionsKey('app.send', 'channel'))
    })

    // Counterfactual: fetch static fields and the options endpoint returns a
    // 404 because it exists only for fields with an OptionSource.
    it('does not fetch for a field whose options are static', () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(
            () => useFieldOptions('app.send', field({ options: { a: 'A' } })),
            { wrapper: wrapper() },
        )

        expect(fetchMock).not.toHaveBeenCalled()
        expect(result.current.options).toEqual({ a: 'A' })
        expect(result.current.loading).toBe(false)
    })

    // Counterfactual: read data instead of data.options and the list is empty;
    // start a request on every effect setup and Strict Mode issues two GETs.
    it('fetches once for a dynamic field and unwraps the options key', async () => {
        let resolveFetch!: (response: Response) => void
        const pending = new Promise<Response>((resolve) => {
            resolveFetch = resolve
        })
        const fetchMock = vi.fn().mockReturnValue(pending)
        vi.stubGlobal('fetch', fetchMock)

        const { result } = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
            { reactStrictMode: true, wrapper: wrapper() },
        )

        await act(async () => {
            resolveFetch(Response.json({ options: { t1: 'Welcome' } }))
        })
        expect(fetchMock).toHaveBeenCalledTimes(1)
        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.options).toEqual({ t1: 'Welcome' })
        expect(result.current.error).toBeNull()
    })

    // Counterfactual: omit the per-editor cache and every node click refetches
    // the same field options from the tenant-scoped endpoint.
    it('serves a second field of the same type and key from the cache', async () => {
        const fetchMock = vi.fn().mockResolvedValue(
            Response.json({ options: { t1: 'Welcome' } }),
        )
        vi.stubGlobal('fetch', fetchMock)
        const cache = new Map<string, Record<string, string>>()

        const first = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
            { wrapper: wrapper(cache) },
        )
        await waitFor(() => expect(first.result.current.loading).toBe(false))

        const second = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
            { wrapper: wrapper(cache) },
        )

        expect(second.result.current.options).toEqual({ t1: 'Welcome' })
        expect(second.result.current.loading).toBe(false)
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // The hook stays mounted across node and provider changes. Counterfactual:
    // key state by pair alone and a same-pair tenant/cache replacement exposes
    // the old editor's choices; trust a cache after its template changes and
    // stale entries survive while the new endpoint is never requested.
    it('keeps cached options scoped to the pair, cache, and template', async () => {
        let resolveFetch!: (response: Response) => void
        const pending = new Promise<Response>((resolve) => {
            resolveFetch = resolve
        })
        const fetchMock = vi.fn().mockReturnValue(pending)
        vi.stubGlobal('fetch', fetchMock)

        const firstCache = new Map<string, Record<string, string>>([
            [fieldOptionsKey('app.first', 'template'), { old: 'Old' }],
            [fieldOptionsKey('app.second', 'template'), { current: 'Current' }],
        ])
        let source: FieldOptionsSource = { template: TEMPLATE, cache: firstCache }
        const MutableSource = ({ children }: { children: ReactNode }) => (
            <FieldOptionsContext.Provider value={source}>
                {children}
            </FieldOptionsContext.Provider>
        )
        const renderedOptions: Array<Record<string, string>> = []
        const { result, rerender } = renderHook(
            ({ nodeType }) => {
                const current = useFieldOptions(nodeType, field({ dynamic_options: true }))
                renderedOptions.push(current.options)

                return current
            },
            { initialProps: { nodeType: 'app.first' }, wrapper: MutableSource },
        )

        expect(result.current.options).toEqual({ old: 'Old' })

        const pairChangeAt = renderedOptions.length
        rerender({ nodeType: 'app.second' })
        expect(result.current.options).toEqual({ current: 'Current' })
        expect(renderedOptions.slice(pairChangeAt)).not.toContainEqual({ old: 'Old' })
        expect(result.current.loading).toBe(false)
        expect(result.current.error).toBeNull()

        const freshCache = new Map<string, Record<string, string>>([
            [fieldOptionsKey('app.second', 'template'), { fresh: 'Fresh' }],
        ])
        source = { template: TEMPLATE, cache: freshCache }
        const cacheChangeAt = renderedOptions.length
        rerender({ nodeType: 'app.second' })
        expect(result.current.options).toEqual({ fresh: 'Fresh' })
        expect(renderedOptions.slice(cacheChangeAt)).not.toContainEqual({ current: 'Current' })

        source = { template: TEMPLATE, cache: freshCache }
        rerender({ nodeType: 'app.second' })
        expect(result.current.options).toEqual({ fresh: 'Fresh' })
        expect(fetchMock).not.toHaveBeenCalled()

        source = { template: NEXT_TEMPLATE, cache: freshCache }
        const templateChangeAt = renderedOptions.length
        rerender({ nodeType: 'app.second' })
        expect(result.current.options).toEqual({})
        expect(renderedOptions.slice(templateChangeAt)).not.toContainEqual({ fresh: 'Fresh' })
        expect(result.current.loading).toBe(true)
        expect(freshCache.has(fieldOptionsKey('app.second', 'template'))).toBe(false)
        expect(fetchMock).toHaveBeenCalledWith(
            '/v2/flows/12/nodes/app.second/fields/template/options',
            expect.any(Object),
        )

        await act(async () => {
            resolveFetch(Response.json({ options: { newest: 'Newest' } }))
        })
        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.options).toEqual({ newest: 'Newest' })
        expect(freshCache.get(fieldOptionsKey('app.second', 'template'))).toEqual({ newest: 'Newest' })
        expect(fetchMock).toHaveBeenCalledTimes(1)
    })

    // Counterfactual: let an obsolete request update state and a slow response
    // from the old pair overwrites the choices for the current field.
    it('ignores a stale response after the node type and field pair changes', async () => {
        const pending = new Map<string, (response: Response) => void>()
        vi.stubGlobal(
            'fetch',
            vi.fn((url: string | URL | Request) => new Promise<Response>((resolve) => {
                pending.set(String(url), resolve)
            })),
        )
        const { result, rerender } = renderHook(
            ({ nodeType }) => useFieldOptions(nodeType, field({ dynamic_options: true })),
            { initialProps: { nodeType: 'app.first' }, wrapper: wrapper() },
        )

        rerender({ nodeType: 'app.second' })
        await act(async () => {
            pending.get('/flows/12/nodes/app.second/fields/template/options')!(
                Response.json({ options: { current: 'Current' } }),
            )
        })
        await waitFor(() => expect(result.current.options).toEqual({ current: 'Current' }))

        await act(async () => {
            pending.get('/flows/12/nodes/app.first/fields/template/options')!(
                Response.json({ options: { stale: 'Stale' } }),
            )
        })

        expect(result.current.options).toEqual({ current: 'Current' })
    })

    // Named errors are never indistinguishable from an empty select.
    // Counterfactual: cast data.options (or default it to {}) and missing,
    // array-shaped, or non-string choices become successful cached data.
    it('reports HTTP and malformed payload failures as named errors', async () => {
        const failedCache = new Map<string, Record<string, string>>()
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(Response.json({ message: 'Nope' }, { status: 500 })),
        )
        const { result, unmount } = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
            { wrapper: wrapper(failedCache) },
        )

        await waitFor(() => expect(result.current.loading).toBe(false))
        expect(result.current.error).toContain('500')
        expect(result.current.options).toEqual({})
        expect(failedCache.size).toBe(0)
        unmount()

        const expectContractError = async (payload: unknown) => {
            const cache = new Map<string, Record<string, string>>()
            vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(payload)))
            const hook = renderHook(
                () => useFieldOptions('app.send', field({ dynamic_options: true })),
                { wrapper: wrapper(cache) },
            )

            await waitFor(() => expect(hook.result.current.loading).toBe(false))
            expect(hook.result.current.error).toContain('options payload')
            expect(hook.result.current.options).toEqual({})
            expect(cache.size).toBe(0)
            hook.unmount()
        }

        await expectContractError({})
        await expectContractError({ options: [] })
        await expectContractError({ options: { template: 42 } })
    })

    // Counterfactual: call optionsUrl outside the guarded lifecycle path and a
    // malformed server template throws from the effect instead of naming the field error.
    it('reports a malformed URL template as a named field error', async () => {
        const Broken = ({ children }: { children: ReactNode }) => (
            <FieldOptionsContext.Provider value={{ template: '/no/sentinels', cache: new Map() }}>
                {children}
            </FieldOptionsContext.Provider>
        )
        const { result } = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
            { wrapper: Broken },
        )

        await waitFor(() => expect(result.current.loading).toBe(false))

        expect(result.current.error).toContain('__NODEFLOW_TYPE__')
        expect(result.current.options).toEqual({})
    })

    // Counterfactual: silently return {} without a provider and a wiring defect
    // looks exactly like a legitimate tenant with no available choices.
    it('names a missing options provider rather than pretending the list is empty', () => {
        const { result } = renderHook(
            () => useFieldOptions('app.send', field({ dynamic_options: true })),
        )

        expect(result.current.loading).toBe(false)
        expect(result.current.options).toEqual({})
        expect(result.current.error).toContain('FieldOptionsContext')
    })
})
