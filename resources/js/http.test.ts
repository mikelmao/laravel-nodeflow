import { afterEach, describe, expect, it, vi } from 'vitest'
import { csrfHeaders, optionsUrl, send, subjectsUrl } from './http'

afterEach(() => {
    document.head.innerHTML = ''
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'
})

describe('csrfHeaders', () => {
    // Laravel's `web` group sets XSRF-TOKEN on every response and
    // VerifyCsrfToken::tokensMatch() decrypts the X-XSRF-TOKEN header, so the
    // cookie is the primary source. Counterfactual: send it as X-CSRF-TOKEN
    // instead and every write is a 419 that looks like a permissions problem.
    it('prefers the XSRF-TOKEN cookie and sends it back url-decoded', () => {
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('abc==')}; path=/`

        expect(csrfHeaders()).toEqual({ 'X-XSRF-TOKEN': 'abc==' })
    })

    // Counterfactual: drop the meta fallback and a host with a stateless
    // middleware stack cannot save at all.
    it('falls back to the csrf-token meta tag', () => {
        document.head.innerHTML = '<meta name="csrf-token" content="from-meta">'

        expect(csrfHeaders()).toEqual({ 'X-CSRF-TOKEN': 'from-meta' })
    })

    // Counterfactual: return {'X-XSRF-TOKEN': undefined} and fetch throws on the
    // header value rather than the request failing with a readable 419.
    it('sends no token header when there is no token to send', () => {
        expect(csrfHeaders()).toEqual({})
    })
})

describe('send', () => {
    // Counterfactual: call response.json() unconditionally and a 419 - which
    // Laravel renders as HTML - throws a SyntaxError, so the session-expired
    // path reports a JSON parse failure.
    it('resolves with the status even when the body is not json', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(new Response(
                '<html>Page Expired</html>',
                { status: 419, headers: { 'Content-Type': 'text/html' } },
            )),
        )

        await expect(send('PUT', '/draft', {})).resolves.toEqual({
            ok: false,
            status: 419,
            data: null,
        })
    })

    // Counterfactual: throw on !response.ok and the 409 conflict path and both
    // 422 shapes all arrive as exceptions instead of as data.
    it('resolves rather than throws on a 409', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue(Response.json({ draft_revision: 4 }, { status: 409 })),
        )

        const result = await send('PUT', '/draft', {})

        expect(result.ok).toBe(false)
        expect(result.status).toBe(409)
        expect(result.data).toEqual({ draft_revision: 4 })
    })

    // Accept application/json prevents Laravel validation redirect.
    // Counterfactual: drop the header and publish 422 arrives as 302.
    it('asks for json and sends the body as json', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ draft_revision: 1 }))
        vi.stubGlobal('fetch', fetchMock)
        document.cookie = `XSRF-TOKEN=${encodeURIComponent('through-send==')}; path=/`

        await send('PUT', '/draft', { graph: { start: '' } })

        const [url, init] = fetchMock.mock.calls[0]!
        expect(url).toBe('/draft')
        expect(init.method).toBe('PUT')
        expect(init.credentials).toBe('same-origin')
        expect(init.headers.Accept).toBe('application/json')
        expect(init.headers['X-Requested-With']).toBe('XMLHttpRequest')
        // Counterfactual: remove ...csrfHeaders() and isolated helper tests
        // still pass, but real writes 419. This pins their integration.
        expect(init.headers['X-XSRF-TOKEN']).toBe('through-send==')
        expect(init.body).toBe(JSON.stringify({ graph: { start: '' } }))
    })

    // Counterfactual: send a body on GET and fetch rejects with TypeError.
    it('sends no body on a GET', async () => {
        const fetchMock = vi.fn().mockResolvedValue(Response.json({ options: {} }))
        vi.stubGlobal('fetch', fetchMock)

        await send('GET', '/options')

        expect(fetchMock.mock.calls[0]![1].body).toBeUndefined()
    })
})

describe('optionsUrl', () => {
    // Counterfactual: interpolate without encodeURIComponent and a slash in a
    // node type addresses a different route.
    it('substitutes both sentinels, url-encoded', () => {
        const template = 'https://app.test/admin/flows/12/nodes/__NODEFLOW_TYPE__/fields/__NODEFLOW_FIELD__/options'

        expect(optionsUrl(template, 'yaya.send/message', 'template')).toBe(
            'https://app.test/admin/flows/12/nodes/yaya.send%2Fmessage/fields/template/options',
        )
    })

    // A missing sentinel is a server contract change. Counterfactual: return
    // the unchanged template and turn that defect into a mysterious 404.
    it('throws when the template has lost its placeholders', () => {
        expect(() => optionsUrl(
            '/flows/12/nodes/x/fields/y/options',
            'a',
            'b',
        )).toThrow(/__NODEFLOW_TYPE__/)
    })
})

describe('subjectsUrl', () => {
    // Counterfactual: interpolate without encodeURIComponent and a node id
    // containing a slash — which the graph permits — addresses a different
    // route entirely.
    it('substitutes the node sentinel, url-encoded', () => {
        const template = 'https://app.test/admin/runs/9/nodes/__NODEFLOW_NODE__/subjects'

        expect(subjectsUrl(template, 'wait/24h')).toBe(
            'https://app.test/admin/runs/9/nodes/wait%2F24h/subjects',
        )
    })

    // A missing sentinel is a server contract change, not a client bug to
    // paper over. Counterfactual: return the template unchanged and the drill-
    // down 404s with no explanation of why.
    it('throws by name when the template has lost its placeholder', () => {
        expect(() => subjectsUrl('/runs/9/nodes/wait/subjects', 'wait')).toThrow(/__NODEFLOW_NODE__/)
    })
})
