export type HttpResult = {
    ok: boolean
    status: number
    data: Record<string, unknown> | null
}

export type HttpMethod = 'GET' | 'PUT' | 'POST'

const TYPE_PLACEHOLDER = '__NODEFLOW_TYPE__'
const FIELD_PLACEHOLDER = '__NODEFLOW_FIELD__'

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

/** Substitute both required sentinels or throw a named contract error. */
export function optionsUrl(template: string, nodeType: string, fieldKey: string): string {
    if (!template.includes(TYPE_PLACEHOLDER) || !template.includes(FIELD_PLACEHOLDER)) {
        throw new Error(
            `The options URL template is missing ${TYPE_PLACEHOLDER} or ${FIELD_PLACEHOLDER}: received "${template}". The server's urls.options prop has changed shape.`,
        )
    }

    return template
        .replace(TYPE_PLACEHOLDER, encodeURIComponent(nodeType))
        .replace(FIELD_PLACEHOLDER, encodeURIComponent(fieldKey))
}
