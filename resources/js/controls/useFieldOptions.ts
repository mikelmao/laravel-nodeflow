import { createContext, useContext, useEffect, useRef, useState } from 'react'
import type { FieldPayload } from '../graph/types'
import { optionsUrl, send } from '../http'

/**
 * Dynamic option source and per-editor cache. Module-global cache is forbidden:
 * SSR editors/tenants could share answers and tests become order-dependent.
 */
export type FieldOptionsSource = {
    /** urls.options from edit props, sentinels intact. */
    template: string
    cache: Map<string, Record<string, string>>
}

export const FieldOptionsContext = createContext<FieldOptionsSource | null>(null)

const EMPTY: Record<string, string> = {}
const MISSING_PROVIDER = 'Could not load the choices for this field: no FieldOptionsContext provider is mounted.'
const CACHE_TEMPLATE = Symbol('nodeflow.field-options.template')

type TemplateScopedCache = FieldOptionsSource['cache'] & {
    [CACHE_TEMPLATE]?: string
}
type State = {
    key: string
    template: string | null
    cache: Map<string, Record<string, string>> | null
    options: Record<string, string> | null
    loading: boolean
    error: string | null
}
type InFlightRequest = {
    template: string
    cache: Map<string, Record<string, string>>
    key: string
    promise: ReturnType<typeof send>
}

/** Injective cache key for the node-type/field-key tuple. */
export function fieldOptionsKey(nodeType: string, fieldKey: string): string {
    return JSON.stringify([nodeType, fieldKey])
}

/** Validate the successful endpoint body before any value reaches the cache. */
function validatedOptions(data: Record<string, unknown> | null): Record<string, string> {
    const options = data?.options
    if (
        options === null
        || typeof options !== 'object'
        || Array.isArray(options)
        || Object.values(options).some((value) => typeof value !== 'string')
    ) {
        throw new Error(
            'The dynamic options payload must contain an "options" object whose values are strings.',
        )
    }

    return options as Record<string, string>
}

/** Stamp template ownership on the per-editor cache without adding an entry. */
function stampCacheTemplate(cache: FieldOptionsSource['cache'], template: string): void {
    Object.defineProperty(cache, CACHE_TEMPLATE, {
        configurable: true,
        enumerable: false,
        value: template,
        writable: true,
    })
}

/**
 * Fetch only when the field is dynamic. Return a named failure beside the
 * field rather than an empty success. Cache is per context/editor; stale
 * requests cannot update a newly selected field or newly mounted editor.
 */
export function useFieldOptions(
    nodeType: string,
    field: FieldPayload,
): { options: Record<string, string>; loading: boolean; error: string | null } {
    const source = useContext(FieldOptionsContext)
    const template = source?.template ?? null
    const cache = source?.cache ?? null
    const key = fieldOptionsKey(nodeType, field.key)

    // A cache is scoped by its URL template as well as its object identity. The
    // marker lives on the per-editor Map, so ownership survives field unmounts.
    // Render only inspects it: mismatched entries are distrusted synchronously.
    const cacheTemplate = cache === null
        ? undefined
        : (cache as TemplateScopedCache)[CACHE_TEMPLATE]
    const templateChangedForCache = template !== null
        && cache !== null
        && cacheTemplate !== undefined
        && cacheTemplate !== template
    const cached = templateChangedForCache ? undefined : cache?.get(key)

    // Strict Mode replays an effect's setup/cleanup cycle. Keep the pending
    // request on this hook instance so the replay subscribes to one GET.
    const inFlightRequest = useRef<InFlightRequest | null>(null)
    const [state, setState] = useState<State>(() => ({
        key,
        template,
        cache,
        options: cached ?? null,
        loading: field.dynamic_options && cache !== null && cached === undefined,
        error: field.dynamic_options && cache === null ? MISSING_PROVIDER : null,
    }))

    useEffect(() => {
        if (template !== null && cache !== null) {
            // Template replacement invalidates every entry in the retained
            // cache. Clear and stamp before pair lookup or the new GET.
            if (templateChangedForCache) {
                cache.clear()
            }
            stampCacheTemplate(cache, template)
        }

        if (!field.dynamic_options) {
            return
        }

        let live = true

        if (template === null || cache === null) {
            setState({
                key,
                template,
                cache,
                options: null,
                loading: false,
                error: MISSING_PROVIDER,
            })
            return
        }

        const existing = cache.get(key)
        if (existing !== undefined) {
            setState({
                key,
                template,
                cache,
                options: existing,
                loading: false,
                error: null,
            })
            return
        }

        setState({ key, template, cache, options: null, loading: true, error: null })

        let request = inFlightRequest.current
        if (
            request === null
            || request.template !== template
            || request.cache !== cache
            || request.key !== key
        ) {
            let url: string
            try {
                url = optionsUrl(template, nodeType, field.key)
            } catch (reason: unknown) {
                setState({
                    key,
                    template,
                    cache,
                    options: null,
                    loading: false,
                    error: `Could not load the choices for this field: ${String(reason)}`,
                })
                return
            }

            request = {
                template,
                cache,
                key,
                promise: send('GET', url),
            }
            inFlightRequest.current = request
        }

        const activeRequest = request
        activeRequest.promise
            .then((result) => {
                if (!live) {
                    return
                }
                if (!result.ok) {
                    setState({
                        key,
                        template,
                        cache,
                        options: null,
                        loading: false,
                        error: `Could not load the choices for this field (HTTP ${result.status}). The node type or field key may not be registered, or its option source may not implement Nodeflow\\Schema\\OptionSource.`,
                    })
                    return
                }

                const options = validatedOptions(result.data)
                cache.set(key, options)
                setState({ key, template, cache, options, loading: false, error: null })
            })
            .catch((reason: unknown) => {
                if (live) {
                    setState({
                        key,
                        template,
                        cache,
                        options: null,
                        loading: false,
                        error: `Could not load the choices for this field: ${String(reason)}`,
                    })
                }
            })
            .finally(() => {
                // A superseding source may already own the ref. A stale
                // request must never clear that newer request's identity.
                if (inFlightRequest.current === activeRequest) {
                    inFlightRequest.current = null
                }
            })

        // Cleanup marks only this effect subscription stale. The request stays
        // reusable for Strict Mode's immediate setup replay and settles once.
        return () => {
            live = false
        }
    }, [cache, field.dynamic_options, field.key, key, nodeType, template, templateChangedForCache])

    if (!field.dynamic_options) {
        return { options: field.options, loading: false, error: null }
    }

    const stateMatchesSource = state.key === key
        && state.template === template
        && state.cache === cache
    if (!stateMatchesSource) {
        return {
            options: cached ?? EMPTY,
            loading: cache !== null && cached === undefined,
            error: cache === null ? MISSING_PROVIDER : null,
        }
    }

    return {
        options: state.options ?? cached ?? EMPTY,
        loading: state.loading,
        error: state.error,
    }
}
