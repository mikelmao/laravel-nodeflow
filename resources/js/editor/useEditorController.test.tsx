import { act, renderHook, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CanvasActions } from '../canvas/Canvas'
import type { EditorUrls, Graph, NodeTypePayload, TriggerNodeTypePayload } from '../graph/types'
import { useEditorController } from './useEditorController'

afterEach(() => vi.unstubAllGlobals())

const flow = { id: 1, name: 'Studio', status: 'draft', version: 3, draft_revision: 7, draft_updated_at: null }
const urls = {
    draft: '/draft', publish: '/publish', validate: '/validate', options: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
    trigger_options: '/trigger-options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
    trigger_source_options: '/trigger-source-options/__NODEFLOW_TYPE__/__NODEFLOW_SOURCE__/__NODEFLOW_FIELD__',
    rotate_webhook_secret: '/webhook-secret/rotate',
}
const send: NodeTypePayload = {
    kind: 'executable',
    type: 'app.send', label: 'Send Message', group: 'Messaging', icon: null, description: null,
    outputs: ['sent'], fields: [], default_config: {}, cardinality: ['subject'],
}
const exit: NodeTypePayload = {
    kind: 'executable',
    type: 'core.exit', label: 'Exit', group: 'Core', icon: null, description: null,
    outputs: [], fields: [], default_config: {}, cardinality: ['subject'],
}
const webhook: TriggerNodeTypePayload = {
    kind: 'trigger', type: 'custom.webhook', driver: 'webhook', label: 'Webhook', icon: null, description: null,
    outputs: ['started'], fields: [], default_config: { source: 'orders' }, compatible_source_keys: ['orders'],
}
const event: TriggerNodeTypePayload = {
    kind: 'trigger', type: 'custom.event', driver: 'event', label: 'Laravel event', icon: null, description: null,
    outputs: ['started'], fields: [], default_config: { source: 'order.placed' }, compatible_source_keys: ['order.placed'],
}
const graph: Graph = {
    start: 'send1',
    nodes: [{ id: 'send1', type: 'app.send', config: {}, position: { x: 0, y: 0 } }],
    edges: [],
}

function controller(overrides: Partial<Parameters<typeof useEditorController>[0]> = {}) {
    return renderHook(() => useEditorController({
        flow,
        graph,
        palette: [send, exit],
        trigger_nodes: [webhook, event],
        trigger_sources: {
            webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }],
            event: [{ key: 'order.placed', driver: 'event', label: 'Order placed', icon: null, description: null, fields: [], default_config: {} }],
        },
        webhook: null,
        urls,
        autosaveDebounceMs: 1,
        ...overrides,
    }))
}

describe('useEditorController', () => {
    it.each([
        ['an unknown persisted source', 'source.missing'],
        ['a valid registered source', 'source.a'],
    ])('rebuilds %s from registry-owned node fields and the selected source only', async (_label, initialSource) => {
        const trigger: TriggerNodeTypePayload = {
            kind: 'trigger', type: 'custom.source-cleanup', driver: 'host', label: 'Source cleanup', icon: null, description: null,
            outputs: ['started'], compatible_source_keys: ['source.a', 'source.b'],
            fields: [
                { key: 'source', type: 'select', label: 'Source', help: null, default: null, required: true, options: {}, dynamic_options: false },
                { key: 'node.mode', type: 'text', label: 'Mode', help: null, default: 'created', required: false, options: {}, dynamic_options: false },
            ],
            default_config: { source: null, 'node.mode': 'created' },
        }
        const sources = {
            host: [
                { key: 'source.a', driver: 'host', label: 'A', icon: null, description: null, fields: [{ key: 'a.only', type: 'text', label: 'A only', help: null, default: null, required: false, options: {}, dynamic_options: false }], default_config: { 'a.only': 'a' } },
                { key: 'source.b', driver: 'host', label: 'B', icon: null, description: null, fields: [
                    { key: 'filters.status', type: 'select', label: 'Status', help: null, default: 'open', required: false, options: {}, dynamic_options: true },
                    { key: 'b.only', type: 'text', label: 'B only', help: null, default: null, required: false, options: {}, dynamic_options: false },
                ], default_config: { 'filters.status': 'open', 'b.only': { nested: ['fresh'] }, injected: 'drop-me' } },
            ],
        } satisfies Parameters<typeof useEditorController>[0]['trigger_sources']
        const configuredGraph: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: trigger.type, config: {
                source: initialSource,
                'node.mode': 'updated',
                'a.only': 'stale-a',
                'filters.status': 'stale-or-unknown',
                orphan: { secret: 'stale' },
            }, position: { x: 31, y: 47 } }],
            edges: [],
        }
        const fetchMock = vi.fn(async (url: string, _init?: RequestInit) => url === urls.publish
            ? Response.json({ version: 4, draft_revision: 8 })
            : Response.json({ draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({ graph: configuredGraph, trigger_nodes: [trigger], trigger_sources: sources })

        act(() => view.result.current.actions.configureTriggerSource('hook', 'source.b'))
        act(() => view.result.current.actions.closeConfigTransaction())
        const sourceDefault = sources.host[1]!.default_config['b.only'] as { nested: string[] }
        sourceDefault.nested.push('outside')

        const expected = {
            source: 'source.b',
            'node.mode': 'updated',
            'filters.status': 'open',
            'b.only': { nested: ['fresh'] },
        }
        expect(view.result.current.document.nodes[0]).toMatchObject({ position: { x: 31, y: 47 }, data: { config: expected } })
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document.nodes[0]?.data.config).toEqual(configuredGraph.nodes?.[0]?.config)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document.nodes[0]?.data.config).toEqual(expected)

        await act(async () => view.result.current.actions.publish())
        const publishCall = fetchMock.mock.calls.find(([url]) => url === urls.publish)
        expect(publishCall).toBeDefined()
        const body = JSON.parse(String(publishCall?.[1]?.body)) as { graph: Graph }
        expect(body.graph.nodes?.[0]?.config).toEqual(expected)
        const draftPayloads = fetchMock.mock.calls
            .filter(([url]) => url === urls.draft)
            .map(([, init]) => JSON.parse(String(init?.body)) as { graph: Graph })
        expect(draftPayloads.map((payload) => payload.graph.nodes?.[0]?.config)).toContainEqual(expected)
    })

    it('adds exactly one trigger as start and requires explicit replacement', () => {
        const view = controller({ graph: { start: '', nodes: [], edges: [] } })

        act(() => view.result.current.actions.addTrigger(webhook, { x: 20, y: 30 }))
        const first = view.result.current.document
        expect(first).toMatchObject({
            startId: 'webhook1',
            nodes: [{ id: 'webhook1', position: { x: 20, y: 30 }, data: { kind: 'trigger', type: 'custom.webhook', config: { source: 'orders' } } }],
        })

        act(() => view.result.current.actions.addTrigger(event, { x: 50, y: 60 }))
        expect(view.result.current.document).toBe(first)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toMatchObject({ startId: '', nodes: [], edges: [] })
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document).toEqual(first)

        act(() => view.result.current.actions.replaceTrigger(event))
        expect(view.result.current.document).toMatchObject({
            startId: 'webhook1',
            nodes: [{ id: 'webhook1', position: { x: 20, y: 30 }, data: { kind: 'trigger', type: 'custom.event', config: { source: 'order.placed' } } }],
        })
        expect(view.result.current.document.nodes.filter((node) => node.data.kind === 'trigger')).toHaveLength(1)
    })

    it('rejects add and replace actions when no allow-listed source is registered', () => {
        const view = controller({
            graph: { start: '', nodes: [], edges: [] },
            trigger_sources: { webhook: [], event: [] },
        })

        act(() => view.result.current.actions.addTrigger(webhook))
        act(() => view.result.current.actions.replaceTrigger(event))

        expect(view.result.current.document).toMatchObject({ startId: '', nodes: [], edges: [] })
    })

    it('materializes contributed defaults for a trigger with a preselected source', () => {
        const filters = { rules: [{ status: 'open' }] }
        const preselected = { ...webhook, default_config: { source: 'orders', node_owned: true } }
        const view = controller({
            graph: { start: '', nodes: [], edges: [] },
            trigger_nodes: [preselected],
            trigger_sources: { webhook: [{
                key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null,
                fields: [{ key: 'filters', type: 'text', label: 'Filters', help: null, default: null, required: false, options: {}, dynamic_options: false }],
                default_config: { filters, node_owned: 'source-must-not-overwrite' },
            }] },
        })

        act(() => view.result.current.actions.addTrigger(preselected))
        filters.rules[0]!.status = 'outside'

        expect(view.result.current.document.nodes[0]?.data.config).toEqual({
            source: 'orders',
            node_owned: true,
            filters: { rules: [{ status: 'open' }] },
        })
    })

    it('deep-clones defaults, configured values, and history snapshots', () => {
        const triggerDefault = { source: 'orders', filters: [{ values: ['open'] }] }
        const executableDefault = { delivery: { channels: ['mail'] } }
        const nestedWebhook: TriggerNodeTypePayload = { ...webhook, default_config: triggerDefault }
        const nestedSend: NodeTypePayload = { ...send, default_config: executableDefault }
        const view = controller({ graph: { start: '', nodes: [], edges: [] }, palette: [nestedSend, exit], trigger_nodes: [nestedWebhook, event] })

        act(() => view.result.current.actions.addTrigger(nestedWebhook))
        act(() => view.result.current.actions.addNode(nestedSend))
        const afterAdds = view.result.current.document
        triggerDefault.filters[0]!.values.push('closed')
        executableDefault.delivery.channels.push('sms')
        expect(afterAdds.nodes[0]!.data.config).toEqual({ source: 'orders', filters: [{ values: ['open'] }] })
        expect(afterAdds.nodes[1]!.data.config).toEqual({ delivery: { channels: ['mail'] } })

        const configured = { rules: [{ tags: ['vip'] }] }
        act(() => view.result.current.actions.configure('webhook1', 'nested', configured))
        act(() => view.result.current.actions.closeConfigTransaction())
        const firstCommit = view.result.current.document
        configured.rules[0]!.tags.push('mutated-outside')
        expect(firstCommit.nodes[0]!.data.config).toMatchObject({ nested: { rules: [{ tags: ['vip'] }] } })

        act(() => view.result.current.actions.configure('webhook1', 'nested', { rules: [{ tags: ['second'] }] }))
        act(() => view.result.current.actions.closeConfigTransaction())
        expect(view.result.current.document).not.toBe(firstCommit)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toEqual(firstCommit)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document.nodes[0]!.data.config).toMatchObject({ nested: { rules: [{ tags: ['second'] }] } })
    })

    it('isolates nested graph and config input while committing and autosaving once', async () => {
        const fetchMock = vi.fn(async () => Response.json({ draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        const input: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: 'custom.webhook', config: { nested: { tags: ['original'] } }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = controller({ graph: input })
        const initial = view.result.current.document
        const inputConfig = input.nodes![0]!.config as { nested: { tags: string[] } }
        inputConfig.nested.tags.push('outside')
        expect(initial.nodes[0]!.data.config).toEqual({ nested: { tags: ['original'] } })

        const value = { tags: ['changed'] }
        act(() => view.result.current.actions.configure('hook', 'nested', value))
        act(() => view.result.current.actions.closeConfigTransaction())
        value.tags.push('outside')
        expect(view.result.current.document.nodes[0]!.data.config).toEqual({ nested: { tags: ['changed'] } })
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))

        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toEqual(initial)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document.nodes[0]!.data.config).toEqual({ nested: { tags: ['changed'] } })
    })

    it('replaces a trigger without losing its position or sole outgoing target and undoes once', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: { source: 'orders' }, position: { x: 10, y: 15 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 300, y: 15 } },
                ],
                edges: [{ from: 'hook', to: 'send1', output: 'started' }],
            },
        })
        const before = view.result.current.document

        act(() => view.result.current.actions.replaceTrigger(event))
        expect(view.result.current.document).toMatchObject({
            startId: 'hook',
            nodes: [
                { id: 'hook', position: { x: 10, y: 15 }, data: { kind: 'trigger', type: 'custom.event' } },
                { id: 'send1', data: { kind: 'executable', type: 'app.send' } },
            ],
            edges: [{ source: 'hook', sourceHandle: 'started', target: 'send1', label: 'started' }],
        })
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toEqual(before)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document.nodes[0]?.data.type).toBe('custom.event')
    })

    it('deletes a trigger with incident edges, clears start, and restores the exact graph through history', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                ],
                edges: [{ from: 'hook', to: 'send1', output: 'started' }],
            },
        })
        const before = view.result.current.document

        act(() => view.result.current.actions.deleteNode('hook'))
        expect(view.result.current.document).toMatchObject({ startId: '', nodes: [{ id: 'send1' }], edges: [] })
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toEqual(before)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document).toMatchObject({ startId: '', nodes: [{ id: 'send1' }], edges: [] })
    })

    it('accepts only a trigger started edge to an executable and leaves invalid connections as identity no-ops', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                    { id: 'unknown1', type: 'not.registered', config: {}, position: { x: 400, y: 0 } },
                ],
                edges: [],
            },
        })

        for (const connection of [
            { source: 'send1', sourceHandle: 'sent', target: 'hook', targetHandle: null },
            { source: 'hook', sourceHandle: 'started', target: 'hook', targetHandle: null },
            { source: 'hook', sourceHandle: 'wrong', target: 'send1', targetHandle: null },
            { source: 'hook', sourceHandle: 'started', target: 'unknown1', targetHandle: null },
        ]) {
            const before = view.result.current.document
            act(() => view.result.current.actions.connect(connection))
            expect(view.result.current.document).toBe(before)
        }

        act(() => view.result.current.actions.connect({ source: 'hook', sourceHandle: 'started', target: 'send1', targetHandle: null }))
        const connected = view.result.current.document
        expect(connected.edges).toHaveLength(1)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document.edges).toEqual([])
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document).toEqual(connected)
    })

    it('keeps max-output and cycle rejection when connecting trigger-aware documents', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'first', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                    { id: 'second', type: 'app.send', config: {}, position: { x: 400, y: 0 } },
                ],
                edges: [
                    { from: 'hook', to: 'first', output: 'started' },
                    { from: 'first', to: 'second', output: 'sent' },
                ],
            },
        })

        for (const connection of [
            { source: 'hook', sourceHandle: 'started', target: 'second', targetHandle: null },
            { source: 'second', sourceHandle: 'sent', target: 'first', targetHandle: null },
        ]) {
            const before = view.result.current.document
            act(() => view.result.current.actions.connect(connection))
            expect(view.result.current.document).toBe(before)
        }
        expect(view.result.current.document.edges).toHaveLength(2)
    })

    it('collapses malformed multiple triggers during explicit replacement and refuses injected extras', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 10, y: 20 } },
                    { id: 'duplicate', type: 'custom.event', config: {}, position: { x: 20, y: 30 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 300, y: 20 } },
                ],
                edges: [{ from: 'hook', to: 'send1', output: 'malformed' }],
            },
        })

        act(() => view.result.current.actions.replaceTrigger(event))
        expect(view.result.current.document.nodes.filter((node) => node.data.kind === 'trigger')).toHaveLength(1)
        expect(view.result.current.document).toMatchObject({
            startId: 'hook',
            nodes: [{ id: 'hook', position: { x: 10, y: 20 }, data: { type: 'custom.event' } }, { id: 'send1' }],
            edges: [{ source: 'hook', sourceHandle: 'started', target: 'send1' }],
        })

        const before = view.result.current.document
        const injected = { ...before.nodes[0]!, id: 'injected', data: { ...before.nodes[0]!.data, id: 'injected' } }
        act(() => view.result.current.actions.nodesChange([{ type: 'add', item: injected }]))
        expect(view.result.current.document).toBe(before)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document.nodes.filter((node) => node.data.kind === 'trigger')).toHaveLength(2)
    })

    it('retains the start-selected trigger when replacing a malformed multi-trigger draft', () => {
        const malformed: Graph = {
            start: 'active-trigger',
            nodes: [
                { id: 'first-trigger', type: 'custom.webhook', config: { source: 'first' }, position: { x: 10, y: 20 } },
                { id: 'active-trigger', type: 'custom.event', config: { source: 'active' }, position: { x: 40, y: 50 } },
                { id: 'first-target', type: 'app.send', config: {}, position: { x: 300, y: 20 } },
                { id: 'active-target', type: 'app.send', config: {}, position: { x: 300, y: 50 } },
            ],
            edges: [
                { from: 'first-trigger', to: 'first-target', output: 'started' },
                { from: 'active-trigger', to: 'active-target', output: 'legacy-output' },
            ],
        }
        const view = controller({ graph: malformed })
        const before = view.result.current.document

        act(() => view.result.current.actions.replaceTrigger(webhook))
        const replaced = view.result.current.document
        expect(replaced).toMatchObject({
            startId: 'active-trigger',
            nodes: [
                { id: 'active-trigger', position: { x: 40, y: 50 }, data: { type: 'custom.webhook', config: { source: 'orders' } } },
                { id: 'first-target' },
                { id: 'active-target' },
            ],
            edges: [{ source: 'active-trigger', sourceHandle: 'started', target: 'active-target', label: 'started' }],
        })
        expect(replaced.nodes.map((node) => node.id)).not.toContain('first-trigger')

        act(() => view.result.current.actions.addTrigger(event))
        expect(view.result.current.document).toBe(replaced)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toEqual(before)
        act(() => view.result.current.actions.redo())
        expect(view.result.current.document).toEqual(replaced)
    })

    it('autosaves one start-selected malformed trigger replacement', async () => {
        const fetchMock = vi.fn(async (_url: string, _options?: RequestInit) => Response.json({ draft_revision: 8 }))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({
            graph: {
                start: 'active-trigger',
                nodes: [
                    { id: 'first-trigger', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'active-trigger', type: 'custom.event', config: {}, position: { x: 0, y: 100 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 300, y: 100 } },
                ],
                edges: [{ from: 'active-trigger', to: 'send1', output: 'started' }],
            },
        })

        act(() => view.result.current.actions.replaceTrigger(webhook))

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
        const body = JSON.parse(String((fetchMock.mock.calls[0]?.[1] as RequestInit | undefined)?.body)) as { graph: Graph }
        expect(body.graph.start).toBe('active-trigger')
        expect(body.graph.nodes?.find((node) => node.id === 'active-trigger')).toMatchObject({ type: 'custom.webhook', position: { x: 0, y: 100 } })
        expect(body.graph.nodes?.map((node) => node.id)).not.toContain('first-trigger')
    })

    it('clears malformed executable starts whenever a recognized trigger is deleted or removed', () => {
        const malformed: Graph = {
            start: 'send1',
            nodes: [
                { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
            ],
            edges: [{ from: 'hook', to: 'send1', output: 'started' }],
        }
        const direct = controller({ graph: malformed })
        const directBefore = direct.result.current.document

        act(() => direct.result.current.actions.deleteNode('hook'))
        expect(direct.result.current.document).toMatchObject({ startId: '', nodes: [{ id: 'send1' }], edges: [] })
        act(() => direct.result.current.actions.undo())
        expect(direct.result.current.document).toEqual(directBefore)
        act(() => direct.result.current.actions.redo())
        expect(direct.result.current.document.startId).toBe('')

        const changed = controller({ graph: malformed })
        const changedBefore = changed.result.current.document
        act(() => changed.result.current.actions.nodesChange([{ id: 'hook', type: 'remove' }]))
        expect(changed.result.current.document).toMatchObject({ startId: '', nodes: [{ id: 'send1' }], edges: [] })
        act(() => changed.result.current.actions.undo())
        expect(changed.result.current.document).toEqual(changedBefore)
    })

    it('preserves a valid trigger start when deleting an ordinary executable', () => {
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                    { id: 'exit1', type: 'core.exit', config: {}, position: { x: 400, y: 0 } },
                ],
                edges: [
                    { from: 'hook', to: 'send1', output: 'started' },
                    { from: 'send1', to: 'exit1', output: 'sent' },
                ],
            },
        })

        act(() => view.result.current.actions.deleteNode('send1'))

        expect(view.result.current.document).toMatchObject({ startId: 'hook', nodes: [{ id: 'hook' }, { id: 'exit1' }], edges: [] })
    })

    it('does not autosave an invalid connection no-op', async () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                ],
                edges: [],
            },
        })
        const before = view.result.current.document

        act(() => view.result.current.actions.connect({ source: 'send1', sourceHandle: 'sent', target: 'hook', targetHandle: null }))
        expect(view.result.current.document).toBe(before)
        await new Promise((resolve) => setTimeout(resolve, 10))
        expect(fetchMock).not.toHaveBeenCalled()
    })

    it('rejects generic node add and replace changes without history or autosave', async () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'send1', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                ],
                edges: [{ from: 'hook', to: 'send1', output: 'started' }],
            },
        })
        const before = view.result.current.document
        const injected = { ...before.nodes[0]!, id: 'injected', data: { ...before.nodes[0]!.data, id: 'injected' } }

        act(() => view.result.current.actions.nodesChange([{ type: 'add', item: injected }]))
        expect(view.result.current.document).toBe(before)
        act(() => view.result.current.actions.nodesChange([{ type: 'replace', id: 'send1', item: injected }]))
        expect(view.result.current.document).toBe(before)
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toBe(before)
        await new Promise((resolve) => setTimeout(resolve, 10))
        expect(fetchMock).not.toHaveBeenCalled()
    })

    it('rejects generic edge add and replace changes that bypass connection rules', async () => {
        const fetchMock = vi.fn()
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({
            graph: {
                start: 'hook',
                nodes: [
                    { id: 'hook', type: 'custom.webhook', config: {}, position: { x: 0, y: 0 } },
                    { id: 'first', type: 'app.send', config: {}, position: { x: 200, y: 0 } },
                    { id: 'second', type: 'app.send', config: {}, position: { x: 400, y: 0 } },
                ],
                edges: [
                    { from: 'hook', to: 'first', output: 'started' },
                    { from: 'first', to: 'second', output: 'sent' },
                ],
            },
        })
        const before = view.result.current.document
        const existing = before.edges[0]!
        const attempts = [
            { type: 'add' as const, item: { ...existing, id: 'target-trigger', source: 'first', sourceHandle: 'sent', target: 'hook' } },
            { type: 'add' as const, item: { ...existing, id: 'wrong-trigger-handle', source: 'hook', sourceHandle: 'wrong', target: 'second' } },
            { type: 'add' as const, item: { ...existing, id: 'max-output', source: 'hook', sourceHandle: 'started', target: 'second' } },
            { type: 'replace' as const, id: existing.id, item: { ...existing, source: 'second', sourceHandle: 'sent', target: 'first' } },
        ]

        for (const change of attempts) {
            act(() => view.result.current.actions.edgesChange([change]))
            expect(view.result.current.document).toBe(before)
        }
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document).toBe(before)
        await new Promise((resolve) => setTimeout(resolve, 10))
        expect(fetchMock).not.toHaveBeenCalled()
    })

    // A regression to node-count ids or a fixed add coordinate makes two rapid adds collide/overlap.
    it('adds collision-safe executable nodes without promoting one to graph start', () => {
        const empty = controller({ graph: { start: null, nodes: [], edges: [] } })
        act(() => empty.result.current.actions.addNode(send, { x: 20, y: 30 }))
        expect(empty.result.current.document).toMatchObject({ startId: '', nodes: [{ id: 'send1', position: { x: 20, y: 30 }, data: { kind: 'executable', isStart: false } }] })
        act(() => empty.result.current.actions.addNode(send, { x: 20, y: 30 }))
        expect(empty.result.current.document.nodes.map((node) => node.id)).toEqual(['send1', 'send2'])
        expect(empty.result.current.document.nodes[1]!.position).not.toEqual({ x: 20, y: 30 })

        const populated = controller()
        act(() => populated.result.current.actions.addNode(exit, { x: 500, y: 75 }))
        expect(populated.result.current.document.startId).toBe('send1')
        expect(populated.result.current.document.nodes.at(-1)).toMatchObject({ position: { x: 500, y: 75 } })
    })

    // Connection gestures without a declared source output must never manufacture a publishable edge.
    it('accepts only declared, non-duplicate output connections and keeps selection outside graph history', () => {
        const view = controller()
        act(() => view.result.current.actions.addNode(exit, { x: 500, y: 0 }))
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: null, targetHandle: null }))
        expect(view.result.current.document.edges).toHaveLength(0)
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: 'sent', targetHandle: null }))
        act(() => view.result.current.actions.connect({ source: 'send1', target: 'exit1', sourceHandle: 'sent', targetHandle: null }))
        expect(view.result.current.document.edges).toHaveLength(1)
        act(() => view.result.current.actions.selectNode('send1'))
        expect(view.result.current.selected?.id).toBe('send1')
        act(() => view.result.current.actions.undo())
        expect(view.result.current.selected?.id).toBe('send1')
    })

    // A completed drag is one author action even though xyflow emits several position fragments.
    it('coalesces a drag into one undo and maps canvas actions into controller props', () => {
        const view = controller()
        const canvas: CanvasActions = { fit: vi.fn(), centerNode: vi.fn(), screenToFlowPosition: vi.fn(() => ({ x: 321, y: 123 })), viewportCenter: vi.fn(() => ({ x: 321, y: 123 })) }
        act(() => view.result.current.actions.registerCanvas(canvas))
        act(() => view.result.current.actions.nodesChange([
            { id: 'send1', type: 'position', position: { x: 100, y: 20 }, dragging: true },
            { id: 'send1', type: 'position', position: { x: 200, y: 30 }, dragging: false },
        ]))
        expect(view.result.current.document.nodes[0]!.position).toEqual({ x: 200, y: 30 })
        act(() => view.result.current.actions.undo())
        expect(view.result.current.document.nodes[0]!.position).toEqual({ x: 0, y: 0 })
        act(() => view.result.current.actions.addAtViewportCenter(exit))
        expect(view.result.current.document.nodes.at(-1)?.position).toEqual({ x: 321, y: 123 })
        expect(view.result.current.canvasProps.deleteKeyCode).toBeNull()
    })

    it('keeps legacy canvas actions usable when viewportCenter is absent', () => {
        const view = controller()
        const screenToFlowPosition = vi.fn(() => ({ x: 777, y: 333 }))
        const legacyCanvas: CanvasActions = { fit: vi.fn(), centerNode: vi.fn(), screenToFlowPosition }
        act(() => view.result.current.actions.registerCanvas(legacyCanvas))
        act(() => view.result.current.actions.addAtViewportCenter(exit))

        expect(screenToFlowPosition).toHaveBeenCalledOnce()
        expect(view.result.current.document.nodes.at(-1)?.position).toEqual({ x: 777, y: 333 })
    })

    // Validate has its own endpoint and sequence; it is not a disguised save or publish operation.
    it('posts only the canonical graph to validate and ignores a late response after an edit', async () => {
        let resolveValidation!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => url === urls.validate
            ? new Promise<Response>((resolve) => { resolveValidation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller()
        act(() => { void view.result.current.actions.validate() })
        act(() => view.result.current.actions.addNode(exit, { x: 300, y: 0 }))
        await act(async () => resolveValidation(Response.json({ valid: true, warnings: [] })))
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.validate)).toHaveLength(1)
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
        expect(view.result.current.toolbarProps.validation.status).toBe('unchecked')
    })

    // A publish 422 is newer and more actionable than a preceding valid validate response.
    it('maps a semantic publish failure across notices, overview, canvas and the selected field', async () => {
        const sendWithTemplate: NodeTypePayload = {
            ...send,
            fields: [{ key: 'template', type: 'text', label: 'Template', help: null, default: null, required: true, options: {}, dynamic_options: false }],
        }
        const fetchMock = vi.fn((url: string, _options?: RequestInit) => {
            if (url === urls.validate) return Promise.resolve(Response.json({ valid: true, warnings: ['The exit path is slow.'] }))
            if (url === urls.publish) return Promise.resolve(Response.json({
                errors: ['The flow cannot be published.'],
                node_errors: [
                    { node: 'send1', field: 'template', message: 'Template is required.' },
                    { node: 'send1', field: null, message: 'Send message is incomplete.' },
                    { node: null, field: null, message: 'A removed branch is invalid.' },
                ],
            }, { status: 422 }))
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const view = controller({ palette: [sendWithTemplate, exit] })

        await act(async () => view.result.current.actions.validate())
        act(() => view.result.current.actions.selectNode('send1'))
        await act(async () => view.result.current.actions.publish())

        const publishBody = JSON.parse(String(fetchMock.mock.calls.find(([url]) => url === urls.publish)![1]?.body))
        expect(publishBody.draft_revision).toBe(7)

        expect(view.result.current.noticeProps.graphMessages).toEqual(expect.arrayContaining([
            'The flow cannot be published.',
            'A removed branch is invalid.',
        ]))
        expect(view.result.current.flowOverviewProps.errors).toEqual(expect.arrayContaining([
            'The flow cannot be published.',
            'A removed branch is invalid.',
        ]))
        expect(view.result.current.flowOverviewProps.warnings).toEqual(['The exit path is slow.'])
        expect(view.result.current.canvasProps.nodeErrors?.send1).toEqual(expect.arrayContaining([
            'template: Template is required.',
            'Send message is incomplete.',
        ]))
        expect(view.result.current.nodeInspectorProps?.errors).toEqual(expect.arrayContaining([
            expect.objectContaining({ field: 'template', message: 'Template is required.' }),
            expect.objectContaining({ field: null, message: 'Send message is incomplete.' }),
        ]))
        expect(view.result.current.flowOverviewProps.issues).toEqual(expect.arrayContaining([
            expect.objectContaining({ message: 'Template is required.', placeable: true }),
            expect.objectContaining({ message: 'A removed branch is invalid.', placeable: false }),
        ]))
        act(() => view.result.current.actions.focusIssue('send1', 'template'))
        expect(view.result.current.nodeInspectorProps?.issueToFocus).toEqual(expect.objectContaining({
            message: 'Template is required.',
        }))
    })

    // A response for an older document must not announce a version it did not publish.
    it('suppresses a successful publish response after the document generation changes', async () => {
        let resolvePublish!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => url === urls.publish
            ? new Promise<Response>((resolve) => { resolvePublish = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const view = controller()

        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1))
        act(() => view.result.current.actions.addNode(exit, { x: 300, y: 0 }))
        await act(async () => resolvePublish(Response.json({ version: 4, draft_revision: 8 })))

        expect(view.result.current.toolbarProps.publishedVersion).toBe(3)
        expect(view.result.current.toolbarProps.publish.status).not.toBe('published')
        expect(view.result.current.noticeProps.publish?.status).not.toBe('published')
    })

    it('keeps React Flow select changes out of graph history while projecting node and edge selection', () => {
        const connected: Graph = { ...graph, edges: [{ from: 'send1', to: 'exit1', output: 'sent' }], nodes: [...(graph.nodes ?? []), { id: 'exit1', type: 'core.exit', config: {}, position: { x: 300, y: 0 } }] }
        const view = controller({ graph: connected })
        const edgeId = view.result.current.document.edges[0]!.id

        act(() => view.result.current.actions.nodesChange([{ id: 'send1', type: 'select', selected: true }]))
        expect(view.result.current.selected?.id).toBe('send1')
        expect(view.result.current.document.nodes[0]).not.toHaveProperty('selected')
        act(() => view.result.current.actions.nodesChange([{ id: 'send1', type: 'select', selected: false }]))
        expect(view.result.current.selected).toBeUndefined()
        expect(view.result.current.view.inspectorOpen).toBe(true)

        act(() => view.result.current.actions.edgesChange([{ id: edgeId, type: 'select', selected: true }]))
        expect(view.result.current.view.selectedEdgeId).toBe(edgeId)
        expect(view.result.current.canvasProps.edges[0]).toMatchObject({ id: edgeId, selected: true })
        expect(view.result.current.document.edges[0]).not.toHaveProperty('selected')
        act(() => view.result.current.actions.edgesChange([{ id: edgeId, type: 'remove' }]))
        expect(view.result.current.view.selectedEdgeId).toBeNull()
    })

    it('invalidates a pending validation when validation becomes unavailable', async () => {
        let resolveValidation!: (response: Response) => void
        vi.stubGlobal('fetch', vi.fn(() => new Promise<Response>((resolve) => { resolveValidation = resolve })))
        let currentUrls: EditorUrls = urls
        const view = renderHook(() => useEditorController({
            flow,
            graph,
            palette: [send, exit],
            trigger_nodes: [webhook, event],
            trigger_sources: { webhook: [], event: [] },
            webhook: null,
            urls: currentUrls,
            autosaveDebounceMs: 1,
        }))
        act(() => { void view.result.current.actions.validate() })
        currentUrls = { ...urls, validate: undefined }
        view.rerender()
        await act(async () => view.result.current.actions.validate())
        await act(async () => resolveValidation(Response.json({ valid: true, warnings: [] })))
        expect(view.result.current.toolbarProps.validation.status).toBe('failed')
        expect(view.result.current.noticeProps.validationMessage).toMatch(/unavailable/i)
    })

    it('invalidates pending validation and publish responses when their URL props change', async () => {
        let resolveValidation!: (response: Response) => void
        let resolvePublish!: (response: Response) => void
        let resolveNewPublish!: (response: Response) => void
        let currentUrls: EditorUrls = urls
        vi.stubGlobal('fetch', vi.fn((url: string) => {
            if (url === urls.validate) return new Promise<Response>((resolve) => { resolveValidation = resolve })
            if (url === urls.publish) return new Promise<Response>((resolve) => { resolvePublish = resolve })
            if (url === '/new-publish') return new Promise<Response>((resolve) => { resolveNewPublish = resolve })
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        }))
        const view = renderHook(() => useEditorController({ flow, graph, palette: [send, exit], trigger_nodes: [webhook, event], trigger_sources: { webhook: [], event: [] }, webhook: null, urls: currentUrls, autosaveDebounceMs: 1 }))
        act(() => { void view.result.current.actions.validate(); void view.result.current.actions.publish() })
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))
        currentUrls = { ...urls, validate: '/new-validate', publish: '/new-publish' }
        view.rerender()
        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolveNewPublish).toBeTypeOf('function'))
        await act(async () => { resolveValidation(Response.json({ valid: true, warnings: [] })); resolvePublish(Response.json({ version: 4, draft_revision: 8 })) })

        expect(view.result.current.toolbarProps.validation.status).toBe('unchecked')
        expect(view.result.current.toolbarProps.publishedVersion).toBe(3)
        await act(async () => resolveNewPublish(Response.json({ version: 5, draft_revision: 9 })))
        expect(view.result.current.toolbarProps.publishedVersion).toBe(5)
    })

    it('does not let a superseded publish response resurrect its webhook secret', async () => {
        let resolveOld!: (response: Response) => void
        let resolveNew!: (response: Response) => void
        let currentUrls: EditorUrls = urls
        vi.stubGlobal('fetch', vi.fn((url: string) => {
            if (url === urls.publish) return new Promise<Response>((resolve) => { resolveOld = resolve })
            if (url === '/new-publish') return new Promise<Response>((resolve) => { resolveNew = resolve })
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        }))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: null,
            urls: currentUrls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolveOld).toBeTypeOf('function'))

        currentUrls = { ...urls, publish: '/new-publish' }
        view.rerender()
        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolveNew).toBeTypeOf('function'))
        await act(async () => resolveNew(Response.json({
            version: 5,
            draft_revision: 9,
            webhook_url: 'https://example.test/hooks/new',
            webhook_secret: 'new-secret',
        })))
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('new-secret')

        await act(async () => resolveOld(Response.json({
            version: 4,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/old',
            webhook_secret: 'old-secret',
        })))
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('new-secret')
        expect(view.result.current.nodeInspectorProps?.webhook?.endpoint_url).toBe('https://example.test/hooks/new')
    })

    it('clears a disclosed publish secret when metadata identifies a different endpoint credential', async () => {
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/old', active: false, secret_rotated_at: null as string | null }
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.publish
            ? Promise.resolve(Response.json({
                version: 4,
                draft_revision: 8,
                webhook_url: 'https://example.test/hooks/published',
                webhook_secret: 'transient-secret',
            }))
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        await act(async () => view.result.current.actions.publish())
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('transient-secret')

        currentWebhook = {
            endpoint_url: 'https://example.test/hooks/refreshed',
            active: true,
            secret_rotated_at: '2026-08-24T16:00:00Z',
        }
        view.rerender()

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('reconciles metadata props received during rotation without clobbering the newer rotation result', async () => {
        let resolveRotation!: (response: Response) => void
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/refreshed', active: false, secret_rotated_at: null as string | null }
        const fetchMock = vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { resolveRotation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(resolveRotation).toBeTypeOf('function'))

        currentWebhook = {
            endpoint_url: 'https://example.test/hooks/refreshed',
            active: true,
            secret_rotated_at: '2026-08-24T15:30:00Z',
        }
        view.rerender()
        await act(async () => resolveRotation(Response.json({
            secret: 'new-secret',
            rotated_at: '2026-08-24T16:00:00Z',
        })))

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual({
            endpoint_url: 'https://example.test/hooks/refreshed',
            active: true,
            secret_rotated_at: '2026-08-24T16:00:00Z',
        })
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('new-secret')
    })

    it.each([
        ['newer then stale', ['2026-08-24T17:00:00Z', '2026-08-24T15:00:00Z'], '2026-08-24T16:00:00Z'],
        ['stale then newer', ['2026-08-24T15:00:00Z', '2026-08-24T17:00:00Z'], '2026-08-24T17:00:00Z'],
    ])('uses the credential watermark only for disclosure safety when props arrive %s', async (_label, observations, expectedRotatedAt) => {
        let resolveRotation!: (response: Response) => void
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { resolveRotation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(resolveRotation).toBeTypeOf('function'))

        for (const [index, rotatedAt] of observations.entries()) {
            currentWebhook = { ...currentWebhook, active: index === 0, secret_rotated_at: rotatedAt }
            view.rerender()
        }
        await act(async () => resolveRotation(Response.json({ secret: 'local-secret-16', rotated_at: '2026-08-24T16:00:00Z' })))

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual({
            endpoint_url: 'https://example.test/hooks/current',
            active: false,
            secret_rotated_at: expectedRotatedAt,
        })
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('applies the same pending credential watermark to publish disclosures', async () => {
        let resolvePublish!: (response: Response) => void
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T16:00:00Z' }
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.publish
            ? new Promise<Response>((resolve) => { resolvePublish = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))

        currentWebhook = { ...currentWebhook, active: true, secret_rotated_at: '2026-08-24T17:00:00Z' }
        view.rerender()
        currentWebhook = { ...currentWebhook, active: false, secret_rotated_at: '2026-08-24T15:00:00Z' }
        view.rerender()
        await act(async () => resolvePublish(Response.json({
            version: 4,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/current',
            webhook_secret: 'publish-secret-16',
        })))

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual({
            endpoint_url: 'https://example.test/hooks/current',
            active: false,
            secret_rotated_at: '2026-08-24T16:00:00Z',
        })
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('retains a local rotation disclosure across equal pending identity updates', async () => {
        let resolveRotation!: (response: Response) => void
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { resolveRotation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(resolveRotation).toBeTypeOf('function'))

        currentWebhook = { ...currentWebhook, active: false, secret_rotated_at: '2026-08-24T16:00:00.000Z' }
        view.rerender()
        currentWebhook = { ...currentWebhook, active: true, secret_rotated_at: '2026-08-24T16:00:00Z' }
        view.rerender()
        await act(async () => resolveRotation(Response.json({ secret: 'equal-secret', rotated_at: '2026-08-24T16:00:00Z' })))

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('equal-secret')
    })

    it.each([
        ['different endpoint then equal local identity', [
            { endpoint_url: 'https://example.test/hooks/external', active: true, secret_rotated_at: '2026-08-24T17:00:00Z' },
            { endpoint_url: 'https://example.test/hooks/current', active: false, secret_rotated_at: '2026-08-24T16:00:00Z' },
        ]],
        ['ambiguous timestamp then stale identity', [
            { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: 'invalid' },
            { endpoint_url: 'https://example.test/hooks/current', active: false, secret_rotated_at: '2026-08-24T15:00:00Z' },
        ]],
    ])('keeps pending credential invalidation sticky for %s', async (_label, observations) => {
        let resolveRotation!: (response: Response) => void
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        const fetchMock = vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { resolveRotation = resolve })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(resolveRotation).toBeTypeOf('function'))

        for (const observation of observations) {
            currentWebhook = observation
            view.rerender()
        }
        await act(async () => resolveRotation(Response.json({ secret: 'must-not-resurrect', rotated_at: '2026-08-24T16:00:00Z' })))

        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(fetchMock.mock.calls.filter(([url]) => url === urls.rotate_webhook_secret)).toHaveLength(2))
        await act(async () => resolveRotation(Response.json({ secret: 'fresh-after-fence', rotated_at: '2026-08-24T18:00:00Z' })))
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('fresh-after-fence')
    })

    it('uses the latest compatible display metadata after a different credential invalidates the local secret', async () => {
        const rotations: Array<(response: Response) => void> = []
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/a', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        const fetchMock = vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { rotations.push(resolve) })
            : Promise.resolve(Response.json({ draft_revision: 8 })))
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(rotations).toHaveLength(1))

        currentWebhook = { endpoint_url: 'https://example.test/hooks/b', active: true, secret_rotated_at: '2026-08-24T17:00:00Z' }
        view.rerender()
        currentWebhook = { endpoint_url: 'https://example.test/hooks/a', active: false, secret_rotated_at: '2026-08-24T16:00:00Z' }
        view.rerender()
        await act(async () => rotations[0]?.(Response.json({ secret: 'unsafe-local-secret', rotated_at: '2026-08-24T16:00:00Z' })))

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(rotations).toHaveLength(2))
        await act(async () => rotations[1]?.(Response.json({ secret: 'next-operation-secret', rotated_at: '2026-08-24T18:00:00Z' })))
        currentWebhook = { ...currentWebhook, secret_rotated_at: '2026-08-24T18:00:00Z' }
        view.rerender()

        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('next-operation-secret')
    })

    it('honors a latest null observation and does not bind the next rotation to a removed endpoint', async () => {
        const rotations: Array<(response: Response) => void> = []
        let currentWebhook: Parameters<typeof useEditorController>[0]['webhook'] = {
            endpoint_url: 'https://example.test/hooks/a',
            active: true,
            secret_rotated_at: '2026-08-24T14:00:00Z',
        }
        vi.stubGlobal('fetch', vi.fn((url: string) => url === urls.rotate_webhook_secret
            ? new Promise<Response>((resolve) => { rotations.push(resolve) })
            : Promise.resolve(Response.json({ draft_revision: 8 }))))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(rotations).toHaveLength(1))

        currentWebhook = { endpoint_url: 'https://example.test/hooks/a', active: false, secret_rotated_at: '2026-08-24T15:00:00Z' }
        view.rerender()
        currentWebhook = null
        view.rerender()
        await act(async () => rotations[0]?.(Response.json({ secret: 'removed-endpoint-secret', rotated_at: '2026-08-24T16:00:00Z' })))

        expect(view.result.current.nodeInspectorProps?.webhook).toBeNull()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(rotations).toHaveLength(2))
        await act(async () => rotations[1]?.(Response.json({ secret: 'must-not-bind-stale-endpoint', rotated_at: '2026-08-24T18:00:00Z' })))
        expect(view.result.current.nodeInspectorProps?.webhook).toBeNull()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('orders post-rotation metadata without regressing or retaining an invalid disclosure', async () => {
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        vi.stubGlobal('fetch', vi.fn(async (url: string) => url === urls.rotate_webhook_secret
            ? Response.json({ secret: 'secret-b', rotated_at: '2026-08-24T16:00:00Z' })
            : Response.json({ draft_revision: 8 })))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('secret-b'))

        currentWebhook = { ...currentWebhook, active: false, secret_rotated_at: '2026-08-24T15:00:00Z' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhook).toEqual({ ...currentWebhook, secret_rotated_at: '2026-08-24T16:00:00Z' })
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('secret-b')

        currentWebhook = { ...currentWebhook, active: true, secret_rotated_at: '2026-08-24T16:00:00Z' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('secret-b')

        currentWebhook = { ...currentWebhook, secret_rotated_at: '2026-08-24T17:00:00Z' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhook).toEqual(currentWebhook)
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('clears a rotated disclosure on endpoint or ambiguous timestamp identity changes', async () => {
        let currentWebhook = { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: '2026-08-24T14:00:00Z' }
        vi.stubGlobal('fetch', vi.fn(async (url: string) => url === urls.rotate_webhook_secret
            ? Response.json({ secret: 'rotated-secret', rotated_at: '2026-08-24T16:00:00Z' })
            : Response.json({ draft_revision: 8 })))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('rotated-secret'))

        currentWebhook = { endpoint_url: 'https://example.test/hooks/replaced', active: true, secret_rotated_at: '2026-08-24T16:00:00Z' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('rotated-secret'))
        currentWebhook = { ...currentWebhook, secret_rotated_at: 'not-a-timestamp' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('retains a first-publish disclosure only across the same null timestamp boundary', async () => {
        let currentWebhook: Parameters<typeof useEditorController>[0]['webhook'] = null
        vi.stubGlobal('fetch', vi.fn(async (url: string) => url === urls.publish
            ? Response.json({ version: 4, draft_revision: 8, webhook_url: 'https://example.test/hooks/first', webhook_secret: 'first-secret' })
            : Response.json({ draft_revision: 8 })))
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = renderHook(() => useEditorController({
            flow,
            graph: triggered,
            palette: [send, exit],
            trigger_nodes: [webhook],
            trigger_sources: { webhook: [{ key: 'orders', driver: 'webhook', label: 'Orders', icon: null, description: null, fields: [], default_config: {} }] },
            webhook: currentWebhook,
            urls,
            autosaveDebounceMs: 1,
        }))
        act(() => view.result.current.actions.selectNode('hook'))
        await act(async () => view.result.current.actions.publish())
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('first-secret')

        currentWebhook = { endpoint_url: 'https://example.test/hooks/first', active: true, secret_rotated_at: null }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('first-secret')

        currentWebhook = { ...currentWebhook, secret_rotated_at: 'invalid' }
        view.rerender()
        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
    })

    it('blocks publish while rotation owns the credential operation and still displays the rotation secret', async () => {
        let resolvePublish!: (response: Response) => void
        let resolveRotation!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => {
            if (url === urls.publish) return new Promise<Response>((resolve) => { resolvePublish = resolve })
            if (url === urls.rotate_webhook_secret) return new Promise<Response>((resolve) => { resolveRotation = resolve })
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = controller({
            graph: triggered,
            webhook: { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: null },
        })
        act(() => view.result.current.actions.selectNode('hook'))

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(resolveRotation).toBeTypeOf('function'))
        act(() => { void view.result.current.actions.publish() })
        await act(async () => { await Promise.resolve() })

        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(0)
        expect(resolvePublish).toBeUndefined()
        await act(async () => resolveRotation(Response.json({
            secret: 'rotated-secret',
            rotated_at: '2026-08-24T15:00:00Z',
        })))

        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('rotated-secret')
        expect(view.result.current.nodeInspectorProps?.webhook?.secret_rotated_at).toBe('2026-08-24T15:00:00Z')
    })

    it('blocks rotation while publish owns the credential operation', async () => {
        let resolvePublish!: (response: Response) => void
        let resolveRotation!: (response: Response) => void
        const fetchMock = vi.fn((url: string) => {
            if (url === urls.publish) return new Promise<Response>((resolve) => { resolvePublish = resolve })
            if (url === urls.rotate_webhook_secret) return new Promise<Response>((resolve) => { resolveRotation = resolve })
            return Promise.resolve(Response.json({ draft_revision: 8 }))
        })
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = controller({
            graph: triggered,
            webhook: { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: null },
        })
        act(() => view.result.current.actions.selectNode('hook'))

        act(() => { void view.result.current.actions.publish() })
        await waitFor(() => expect(resolvePublish).toBeTypeOf('function'))
        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())

        expect(fetchMock.mock.calls.filter(([url]) => url === urls.rotate_webhook_secret)).toHaveLength(0)
        expect(resolveRotation).toBeUndefined()
        await act(async () => resolvePublish(Response.json({
            version: 4,
            draft_revision: 8,
            webhook_url: 'https://example.test/hooks/current',
        })))

        expect(view.result.current.nodeInspectorProps?.webhookSecret).toBeNull()
        expect(view.result.current.nodeInspectorProps?.webhook?.secret_rotated_at).toBeNull()
    })

    it('releases the shared credential barrier after rotation and publish failures', async () => {
        let rotations = 0
        const fetchMock = vi.fn(async (url: string) => {
            if (url === urls.rotate_webhook_secret) {
                rotations += 1
                return rotations === 1
                    ? Response.json({ message: 'private failure details' }, { status: 500 })
                    : Response.json({ secret: 'recovered-secret', rotated_at: '2026-08-24T16:30:00Z' })
            }
            if (url === urls.publish) return Response.json({ message: 'publish failed' }, { status: 500 })
            return Response.json({ draft_revision: 8 })
        })
        vi.stubGlobal('fetch', fetchMock)
        const triggered: Graph = {
            start: 'hook',
            nodes: [{ id: 'hook', type: webhook.type, config: { source: 'orders' }, position: { x: 0, y: 0 } }],
            edges: [],
        }
        const view = controller({
            graph: triggered,
            webhook: { endpoint_url: 'https://example.test/hooks/current', active: true, secret_rotated_at: null },
        })
        act(() => view.result.current.actions.selectNode('hook'))

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(view.result.current.nodeInspectorProps?.webhookRotationError).toMatch(/could not rotate/i))
        await act(async () => view.result.current.actions.publish())
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.publish)).toHaveLength(1)

        act(() => view.result.current.nodeInspectorProps?.onRotateWebhookSecret?.())
        await waitFor(() => expect(view.result.current.nodeInspectorProps?.webhookSecret).toBe('recovered-secret'))
        expect(fetchMock.mock.calls.filter(([url]) => url === urls.rotate_webhook_secret)).toHaveLength(2)
    })
})
