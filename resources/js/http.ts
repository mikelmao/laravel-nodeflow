export type HttpResult = {
    ok: boolean
    status: number
    data: Record<string, unknown> | null
}

export type HttpMethod = 'GET' | 'PUT' | 'POST'

const TYPE_PLACEHOLDER = '__NODEFLOW_TYPE__'
const FIELD_PLACEHOLDER = '__NODEFLOW_FIELD__'
const NODE_PLACEHOLDER = '__NODEFLOW_NODE__'

/**
 * Read CSRF from Laravel's decoded XSRF cookie, with the host page's meta tag
 * as a fallback. The helper deliberately has no axios or Inertia dependency.
 */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length)

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie) }
    }

    const meta = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')

    return meta ? { 'X-CSRF-TOKEN': meta } : {}
}

/**
 * HTTP statuses resolve as data so 409, 419, and 422 remain renderable control
 * flow. Only network failures reject; a non-JSON response body becomes null.
 */
export async function send(
    method: HttpMethod,
    url: string,
    body?: unknown,
): Promise<HttpResult> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...csrfHeaders(),
        },
        body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
    })

    let data: Record<string, unknown> | null = null
    try {
        data = (await response.json()) as Record<string, unknown>
    } catch {
        data = null
    }

    return { ok: response.ok, status: response.status, data }
}

/**
 * Substitute server-authored URL sentinels, url-encoding each value.
 *
 * A missing sentinel throws by name rather than returning the template: the
 * server owns these URLs (E4), so an absent placeholder means its contract
 * changed, and silently sending the unsubstituted template turns that into a
 * mysterious 404 far from the cause.
 */
export function substituteSentinels(template: string, replacements: Record<string, string>): string {
    let url = template

    for (const [sentinel, value] of Object.entries(replacements)) {
        if (!url.includes(sentinel)) {
            throw new Error(
                `The URL template is missing ${sentinel}: received "${template}". The server's urls prop has changed shape.`,
            )
        }

        url = url.replace(sentinel, encodeURIComponent(value))
    }

    return url
}

export function optionsUrl(template: string, nodeType: string, fieldKey: string): string {
    return substituteSentinels(template, {
        [TYPE_PLACEHOLDER]: nodeType,
        [FIELD_PLACEHOLDER]: fieldKey,
    })
}

export function subjectsUrl(template: string, nodeId: string): string {
    return substituteSentinels(template, { [NODE_PLACEHOLDER]: nodeId })
}
