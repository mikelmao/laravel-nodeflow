import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Graph, GraphComponentPayload, NodeTypePayload, RunSummary, RunUrls } from '../graph/types'
import { FlowRun } from './FlowRun'

const urls: RunUrls = {
    overlay: '/nodeflow/runs/9/overlay',
    subjects: '/nodeflow/runs/9/nodes/__NODEFLOW_NODE__/subjects',
}

const run: RunSummary = {
    id: 9, status: 'running', terminal: false, strategy: 'cohort', is_test: false,
    started_via: 'test.fake', trigger_node_id: 'trigger',
    started_at: null, ended_at: null, error: null, version: 1, flow: { id: 1, name: 'Flood alert' },
}

const graph: Graph = {
    start: 'sent',
    nodes: [
        { id: 'sent', type: 'app.send', config: {}, position: { x: 0, y: 0 } },
        { id: 'segment', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
        { id: 'nobody', type: 'app.send', config: {}, position: { x: 400, y: 0 } },
    ],
    edges: [],
}

const palette: Omit<NodeTypePayload, 'kind'>[] = [{
    type: 'app.send', label: 'Send message', group: 'Messaging', icon: null,
    description: null, outputs: ['sent', 'failed'], fields: [], default_config: {}, cardinality: ['audience'],
}]

function overlay() {
    return {
        status: 'running',
        terminal: false,
        nodes: {
            sent: { reached: true, byOutput: { sent: 2 }, waiting: 0, failed: 1, error: 'Timeout: gateway' },
            segment: { reached: true, byOutput: { unmatched: 0 }, waiting: 0, failed: 0, error: null },
            nobody: { reached: false, byOutput: {}, waiting: 0, failed: 0, error: null },
        },
    }
}

function subjectsPage(ids: string[], next: string | null) {
    return Response.json({
        node: 'segment',
        data: ids.map((id) => ({
            id: Number(id), subject_type: 'user', subject_id: id, status: 'active',
            current_node_id: 'segment', last_error: null, exited_at: null,
        })),
        next_cursor: next,
    })
}

beforeEach(() => vi.useFakeTimers({ shouldAdvanceTime: true }))
afterEach(() => vi.useRealTimers())

describe('FlowRun', () => {
    it('preserves custom trigger definitions while normalizing legacy executables', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))
        const componentPalette = [
            {
                kind: 'trigger', type: 'custom.trigger', driver: 'custom', label: 'Custom trigger', icon: null,
                description: null, outputs: ['started'], fields: [], default_config: {}, compatible_source_keys: [],
            },
            ...palette,
        ] satisfies (GraphComponentPayload | Omit<NodeTypePayload, 'kind'>)[]
        const triggerGraph: Graph = {
            start: 'trigger',
            nodes: [
                { id: 'trigger', type: 'custom.trigger', config: {}, position: { x: 0, y: 0 } },
                { id: 'sent', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
            ],
            edges: [{ from: 'trigger', to: 'sent', output: 'started' }],
        }
        const renderers = {
            'custom.trigger': ({ data, def }: { data: { kind: string | null }; def: GraphComponentPayload | undefined }) => <span data-testid="trigger-kind">{data.kind}:{def?.kind}</span>,
            'app.send': ({ data, def }: { data: { kind: string | null }; def: GraphComponentPayload | undefined }) => <span data-testid="executable-kind">{data.kind}:{def?.kind}</span>,
        }

        render(<FlowRun run={run} graph={triggerGraph} palette={componentPalette} overlay={{ ...overlay(), nodes: {} }} urls={urls} nodeRenderers={renderers} />)

        expect(screen.getByTestId('trigger-kind')).toHaveTextContent('trigger:trigger')
        expect(screen.getByTestId('executable-kind')).toHaveTextContent('executable:executable')
    })

    /**
     * The DOM half of the counterfactual RunOverlayTest kills server-side and
     * overlay.test.ts kills at the data level. All three are needed: a correct
     * payload rendered identically is still the same misreading in front of the
     * operator. Counterfactual: dim from a count and 'segment' — reached,
     * released nobody — looks exactly like 'nobody', which was never touched.
     */
    it('shows an explicit zero on a reached node and no badge at all on a never-reached one', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        expect(screen.getByTestId('nodeflow-badges-segment')).toHaveTextContent('unmatched 0')
        expect(screen.queryByTestId('nodeflow-badges-nobody')).toBeNull()
    })

    it('renders a nodes overlay error through the cards own error list', () => {
        // Counterfactual: add a second error mechanism for run errors and a
        // host renderer override can hide them, which NodeCard's own list
        // structurally cannot.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        expect(screen.getByText('Timeout: gateway')).toBeInTheDocument()
    })

    it('mounts the canvas read-only, with no draggable or selectable nodes', () => {
        // Counterfactual: leave interactive at its default and an operator can
        // drag a node on a frozen, immutable graph — implying an edit the view
        // has no endpoint to save.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))

        const { container } = render(
            <FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />,
        )

        expect(container.querySelector('.react-flow')).not.toBeNull()
        // Scoped to node elements, not '.draggable' bare: xyflow's own pane
        // (react-flow__pane) always carries a "draggable" class for canvas
        // panning, independent of nodesDraggable — panning a frozen graph's
        // viewport is still allowed in read-only mode. An unscoped selector
        // would match that pane and fail even when no node is draggable.
        expect(container.querySelectorAll('.react-flow__node.draggable').length).toBe(0)
        expect(container.querySelectorAll('.selectable').length).toBe(0)
    })

    it('does not expose a node-type drop path on the frozen run canvas', () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))
        const { container } = render(
            <FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />,
        )
        const pane = container.querySelector('.react-flow__pane')!
        const dataTransfer = {
            getData: (mime: string) => mime === 'application/x-nodeflow-node-type' ? 'app.send' : '',
        } as DataTransfer

        // A run canvas has no graph mutation callback and explicitly disables
        // the Canvas drop path, so even a valid editor payload is not accepted.
        expect(fireEvent.drop(pane, { dataTransfer, clientX: 12, clientY: 34 })).toBe(true)
        expect(container.querySelectorAll('.react-flow__node')).toHaveLength(graph.nodes?.length ?? 0)
    })

    it('refreshes its badges from the polled overlay', async () => {
        // Counterfactual: render the initial prop and never re-read the hook's
        // snapshot, and the counts freeze at page load while requests continue.
        const moved = { ...overlay(), nodes: { ...overlay().nodes, segment: { reached: true, byOutput: { unmatched: 4 }, waiting: 0, failed: 0, error: null } } }
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(moved)))

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} pollIntervalMs={1000} />)

        await act(async () => { await vi.advanceTimersByTimeAsync(1000) })
        await waitFor(() => expect(screen.getByTestId('nodeflow-badges-segment')).toHaveTextContent('unmatched 4'))
    })

    it('lists the subjects at a clicked node and pages through them', async () => {
        // Counterfactual: build the subjects URL by concatenation and a host
        // prefix breaks the drill-down with no test noticing.
        const fetchMock = vi.fn()
            .mockImplementation((requested: string) => requested.includes('/subjects')
                ? Promise.resolve(requested.includes('cursor=') ? subjectsPage(['3'], null) : subjectsPage(['1', '2'], 'cur2'))
                : Promise.resolve(Response.json(overlay())))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        // 'segment' is the node id text, chosen because all three fixture nodes
        // share the type 'app.send' and therefore render the same 'Send message'
        // label — getByText on that label would match three elements at once.
        // Select the React Flow wrapper: normal card content intentionally does
        // not expose raw node IDs, and the human label is shared by all nodes.
        await act(async () => { screen.getByTestId('rf__node-segment').click() })
        await waitFor(() => expect(screen.getByText('user #1')).toBeInTheDocument())

        expect(fetchMock.mock.calls.some(([u]) => u === '/nodeflow/runs/9/nodes/segment/subjects')).toBe(true)

        await act(async () => { screen.getByRole('button', { name: /load more/i }).click() })
        await waitFor(() => expect(screen.getByText('user #3')).toBeInTheDocument())
        expect(screen.getByText('user #1')).toBeInTheDocument()
    })

    it('says nobody is here now rather than implying the node was never reached', async () => {
        // Two different facts an operator must not confuse: a reached node that
        // is now empty, versus a node the run never touched. Counterfactual: an
        // empty list with no message reads as the latter.
        const fetchMock = vi.fn().mockImplementation((requested: string) => requested.includes('/subjects')
            ? Promise.resolve(subjectsPage([], null))
            : Promise.resolve(Response.json(overlay())))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        await act(async () => { screen.getByTestId('rf__node-segment').click() })
        await waitFor(() => expect(screen.getByText(/no subjects are here now/i)).toBeInTheDocument())
    })

    it('says this run has no record here rather than asserting the node was never reached', async () => {
        // The mirror image of the previous test, and the one the original
        // brief itself got wrong: asserting a never-reached node was "never
        // reached by this run" is false in the most common case there is —
        // core.exit returns NodeResult::empty(), so it writes no
        // node_executions row, and once its subjects have moved on this
        // branch is exactly what every completed demo run hits for the node
        // every subject exited through. The fix is to say what the run has a
        // record of, not what happened: this must hold even when subjects
        // did pass through and left no trace, so it must not claim "never
        // reached" or "already released everyone" — both assert more than an
        // absent record supports.
        const fetchMock = vi.fn().mockImplementation((requested: string) => requested.includes('/subjects')
            ? Promise.resolve(subjectsPage([], null))
            : Promise.resolve(Response.json(overlay())))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        await act(async () => { screen.getByTestId('rf__node-nobody').click() })
        await waitFor(() => expect(screen.getByText(/no record of any subject having been here/i)).toBeInTheDocument())
        expect(screen.queryByText(/already released everyone/i)).toBeNull()
        expect(screen.queryByText(/never reached/i)).toBeNull()
    })

    it('says only that a reached node is now empty, not how the subjects left', async () => {
        // Counterfactual: word the reached branch as "released everyone" (or
        // "passed through") and this fails, because RunOverlay's byOutput and
        // failed buckets are disjoint -- output === null is the failure
        // bucket, not a declared output -- and SubjectExiter::exit() is a
        // third path that nulls current_node_id with no output taken at all.
        // reached: true, byOutput: {}, failed: 5 is exactly the state where
        // nobody was released through an output: five subjects failed at this
        // node and none advanced. The panel cannot tell failure, cancellation,
        // and advancement apart, so its wording must hold for all three.
        const failedOnly = {
            ...overlay(),
            nodes: { ...overlay().nodes, segment: { reached: true, byOutput: {}, waiting: 0, failed: 5, error: null } },
        }
        const fetchMock = vi.fn().mockImplementation((requested: string) => requested.includes('/subjects')
            ? Promise.resolve(subjectsPage([], null))
            : Promise.resolve(Response.json(failedOnly)))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={failedOnly} urls={urls} />)

        await act(async () => { screen.getByTestId('rf__node-segment').click() })
        await waitFor(() => expect(screen.getByText(/reached earlier in the run/i)).toBeInTheDocument())
        expect(screen.queryByText(/released/i)).toBeNull()
        expect(screen.queryByText(/passed through it/i)).toBeNull()
    })

    it('does not let a slow first-page reply overwrite the panel of a node selected after it', async () => {
        // Counterfactual: remove the per-request liveness guard in
        // SubjectPanel.tsx (the `currentNodeId.current !== requestedFor`
        // check inside load()'s .then) and this fails, because the stale
        // 'sent' reply below then overwrites 'segment's rows once it
        // resolves. This is the third response-outliving-its-context guard
        // in this plan (after useOverlayPolling.ts's two), proved the same
        // way: hold a request open, switch context out from under it, and
        // show the late reply lose.
        let resolveSent: (value: Response) => void = () => {}
        const sentPromise = new Promise<Response>((resolve) => { resolveSent = resolve })

        const fetchMock = vi.fn().mockImplementation((requested: string) => {
            if (requested.includes('/nodes/sent/subjects')) {
                return sentPromise
            }
            if (requested.includes('/nodes/segment/subjects')) {
                return Promise.resolve(subjectsPage(['9'], null))
            }
            return Promise.resolve(Response.json(overlay()))
        })
        vi.stubGlobal('fetch', fetchMock)

        const { container } = render(
            <FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />,
        )

        // 'sent' is ambiguous by text (it also labels four output handles), so
        // click the react-flow node wrapper directly by its data-id, the same
        // way the canvas itself identifies nodes.
        await act(async () => {
            fireEvent.click(container.querySelector('.react-flow__node[data-id="sent"]')!)
        })

        // Switch to 'segment' before 'sent's request resolves.
        await act(async () => {
            fireEvent.click(container.querySelector('.react-flow__node[data-id="segment"]')!)
        })
        await waitFor(() => expect(screen.getByText('user #9')).toBeInTheDocument())

        // Now let the stale 'sent' reply resolve, after 'segment' is selected.
        await act(async () => {
            resolveSent(subjectsPage(['999'], null))
            await Promise.resolve()
            await Promise.resolve()
        })

        expect(screen.getByText('user #9')).toBeInTheDocument()
        expect(screen.queryByText('user #999')).toBeNull()
    })

    it('surfaces a network failure instead of leaving the panel stuck on "Loading…"', async () => {
        // Counterfactual: remove SubjectPanel's .catch() (there wasn't one)
        // and this fails — send() rejects only on a network failure
        // (http.ts's own contract), so a dropped connection here left
        // `loading: true`, `error: null`, and "Loading…" on screen forever,
        // plus an unhandled rejection in the console. useOverlayPolling.ts
        // already guards its own request this same way; this is the same
        // guard for the subject drill-down's request.
        const fetchMock = vi.fn().mockImplementation((requested: string) => requested.includes('/subjects')
            ? Promise.reject(new Error('network down'))
            : Promise.resolve(Response.json(overlay())))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        await act(async () => { screen.getByTestId('rf__node-segment').click() })

        await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/could not load the subjects/i))
        expect(screen.queryByText('Loading…')).toBeNull()
    })

    it('does not double-fetch a page when "Load more" is clicked twice before it disables', async () => {
        // Counterfactual: remove the pagingInFlight guard in
        // SubjectPanel.tsx's load() and this fails — two clicks issued before
        // React commits the disabled button both fire a request for the same
        // cursor, and both replies append, landing page 2 twice (duplicated
        // rows, duplicate React key warnings).
        let pageTwoRequests = 0
        const fetchMock = vi.fn().mockImplementation((requested: string) => {
            if (requested.includes('/subjects') && requested.includes('cursor=')) {
                pageTwoRequests += 1

                return Promise.resolve(subjectsPage(['3'], null))
            }
            if (requested.includes('/subjects')) {
                return Promise.resolve(subjectsPage(['1', '2'], 'cur2'))
            }

            return Promise.resolve(Response.json(overlay()))
        })
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        await act(async () => { screen.getByTestId('rf__node-segment').click() })
        await waitFor(() => expect(screen.getByText('user #1')).toBeInTheDocument())

        const button = screen.getByRole('button', { name: /load more/i })

        // Both clicks fire within the same synchronous pass, before the
        // button's `disabled` attribute could possibly have committed —
        // exactly the race a double click produces.
        await act(async () => {
            fireEvent.click(button)
            fireEvent.click(button)
        })

        await waitFor(() => expect(screen.getByText('user #3')).toBeInTheDocument())
        expect(pageTwoRequests).toBe(1)
        expect(screen.getAllByText('user #3')).toHaveLength(1)
    })

    it('lets a host restyle a run card without losing its badges or errors', () => {
        // The same guarantee the editor has: a renderer owns the body only.
        // Counterfactual: let the override replace the whole card and a themed
        // run view silently loses every count.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(Response.json(overlay())))

        render(
            <FlowRun
                run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls}
                nodeRenderers={{ 'app.send': ({ data }) => <p>custom {data.id}</p> }}
            />,
        )

        expect(screen.getByText('custom segment')).toBeInTheDocument()
        expect(screen.getByTestId('nodeflow-badges-segment')).toHaveTextContent('unmatched 0')
        expect(screen.getByText('Timeout: gateway')).toBeInTheDocument()
    })
})
