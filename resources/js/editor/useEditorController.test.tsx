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
        trigger_sources: { webhook: [], event: [] },
        webhook: null,
        urls,
        autosaveDebounceMs: 1,
        ...overrides,
    }))
}

describe('useEditorController', () => {
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
})
