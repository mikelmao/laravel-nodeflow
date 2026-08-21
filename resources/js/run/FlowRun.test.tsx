import { act, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { Graph, NodeTypePayload, RunSummary, RunUrls } from '../graph/types'
import { FlowRun } from './FlowRun'

const urls: RunUrls = {
    overlay: '/nodeflow/runs/9/overlay',
    subjects: '/nodeflow/runs/9/nodes/__NODEFLOW_NODE__/subjects',
}

const run: RunSummary = {
    id: 9, status: 'running', terminal: false, strategy: 'cohort', is_test: false,
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

const palette: NodeTypePayload[] = [{
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
        // 'segment' is unique; 'sent' also appears as four output-handle labels.
        await act(async () => { screen.getByText('segment').click() })
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

        await act(async () => { screen.getByText('segment').click() })
        await waitFor(() => expect(screen.getByText(/no subjects are here now/i)).toBeInTheDocument())
    })

    it('says a node was never reached rather than implying it ran and emptied', async () => {
        // The mirror image of the previous test, and the one my brief itself
        // got wrong: a never-reached node must not tell the operator it
        // "already released everyone" — that node never ran at all.
        // Counterfactual: reuse the reached-then-emptied sentence for both
        // states and this fails, because both branches would say the same
        // (false, for 'nobody') thing.
        const fetchMock = vi.fn().mockImplementation((requested: string) => requested.includes('/subjects')
            ? Promise.resolve(subjectsPage([], null))
            : Promise.resolve(Response.json(overlay())))
        vi.stubGlobal('fetch', fetchMock)

        render(<FlowRun run={run} graph={graph} palette={palette} overlay={overlay()} urls={urls} />)

        await act(async () => { screen.getByText('nobody').click() })
        await waitFor(() => expect(screen.getByText(/never reached/i)).toBeInTheDocument())
        expect(screen.queryByText(/already released everyone/i)).toBeNull()
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

        await act(async () => { screen.getByText('segment').click() })
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
