import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { mergeControls, type FieldControlProps } from '../controls'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import type { NodeCardData, NodeTypePayload, TriggerNodeTypePayload, TriggerSourcesPayload } from '../graph/types'
import { FlowOverview, type FlowOverviewProps } from './FlowOverview'
import { NodeInspector } from './NodeInspector'

const node: NodeCardData = {
    id: 'send1',
    type: 'app.send',
    kind: 'executable',
    config: { template: 'Welcome' },
    isStart: false,
}

const definition: NodeTypePayload = {
    kind: 'executable',
    type: 'app.send',
    label: 'Send message',
    group: 'Messaging',
    icon: null,
    description: 'Sends a message.',
    outputs: ['sent', 'bounced'],
    fields: [{
        key: 'template',
        type: 'text',
        label: 'Template',
        help: 'The message body.',
        default: null,
        required: true,
        options: {},
        dynamic_options: false,
    }],
    default_config: {},
    cardinality: ['subject'],
}

function overview(overrides: Partial<FlowOverviewProps> = {}) {
    const props: FlowOverviewProps = {
        flow: { name: 'Welcome sequence' },
        trigger: { label: 'Order placed', type: 'orders.placed' },
        publishedVersion: 4,
        nodeCount: 3,
        connectionCount: 2,
        startNodeId: 'send1',
        validation: { status: 'unchecked' },
        issues: [],
        warnings: [],
        errors: [],
        unknownTypes: [],
        unresolvedOutputs: [],
        ...overrides,
    }
    return render(<FlowOverview {...props} />)
}

function inspector(overrides: Partial<React.ComponentProps<typeof NodeInspector>> = {}) {
    const props: React.ComponentProps<typeof NodeInspector> = {
        node,
        def: definition,
        controls: mergeControls(),
        errors: [],
        onConfigChange: vi.fn(),
        onDelete: vi.fn(),
        ...overrides,
    }
    return render(
        <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
            <NodeInspector {...props} />
        </FieldOptionsContext.Provider>,
    )
}

describe('FlowOverview', () => {
    it('gives a no-selection overview its own semantic region and human flow facts', () => {
        overview()

        expect(screen.getByRole('complementary', { name: 'Flow overview' })).toBeInTheDocument()
        expect(screen.getByRole('heading', { name: 'Welcome sequence' })).toBeInTheDocument()
        expect(screen.getByText('Order placed')).toBeInTheDocument()
        expect(screen.getByText('orders.placed')).toBeInTheDocument()
        expect(screen.getByText('Published version 4')).toBeInTheDocument()
        expect(screen.getByText('3 nodes')).toBeInTheDocument()
        expect(screen.getByText('2 connections')).toBeInTheDocument()
        expect(screen.getByText('Start node: send1')).toBeInTheDocument()
    })

    it('uses explicit empty values for an unpublished, empty flow', () => {
        overview({ publishedVersion: null, nodeCount: 0, connectionCount: 0, startNodeId: null })

        expect(screen.getByText('Not published')).toBeInTheDocument()
        expect(screen.getByText('0 nodes')).toBeInTheDocument()
        expect(screen.getByText('0 connections')).toBeInTheDocument()
        expect(screen.getByText('Start node: None')).toBeInTheDocument()
    })

    it.each([
        ['unchecked', 'Not validated yet'],
        ['checking', 'Checking flow readiness'],
        ['valid', 'Ready to publish'],
        ['warning', 'Ready with warnings'],
        ['invalid', 'Needs attention before publishing'],
        ['failed', 'Validation could not complete'],
    ] as const)('describes the %s validation state distinctly', (status, copy) => {
        overview({ validation: { status } })

        expect(screen.getByText(copy)).toBeInTheDocument()
    })

    it('announces trigger readiness changes as a polite status', () => {
        const view = overview({ triggerReadiness: 'The selected trigger source is not compatible with this trigger.' })
        const readiness = screen.getByRole('status', { name: 'Flow readiness' })
        expect(readiness).toHaveAttribute('aria-live', 'polite')
        expect(readiness).toHaveTextContent(/not compatible/i)

        view.rerender(<FlowOverview {...{
            flow: { name: 'Welcome sequence' },
            trigger: { label: 'Order placed', type: 'orders.placed' },
            triggerReadiness: null,
            publishedVersion: 4,
            nodeCount: 3,
            connectionCount: 2,
            startNodeId: 'send1',
            validation: { status: 'valid' as const },
            issues: [], warnings: [], errors: [], unknownTypes: [], unresolvedOutputs: [],
        }} />)
        expect(readiness).toHaveTextContent('Ready to publish')
    })

    it('keeps local diagnostics and ordered graph issues visible regardless of server validation', () => {
        const onIssueSelect = vi.fn()
        const placeable = { node: 'send1', field: 'template', message: 'Template is required', placeable: true }
        const unplaceable = { node: null, field: null, message: 'No route reaches a finish', placeable: false }
        const unknownNode = { node: 'deleted-node', field: null, message: 'A deleted node has an error', placeable: false }
        overview({
            validation: { status: 'valid' },
            issues: [placeable, unplaceable, unknownNode],
            warnings: ['A branch has no fallback.'],
            errors: ['The graph contains a cycle.'],
            unknownTypes: [{ nodeId: 'legacy1', type: 'legacy.send' }],
            unresolvedOutputs: [{ from: 'send1', to: 'done1' }],
            onIssueSelect,
        })

        expect(screen.getByText('Unknown node type legacy.send on legacy1')).toBeInTheDocument()
        expect(screen.getByText('Connection send1 → done1 has no output')).toBeInTheDocument()
        expect(screen.getByText('A branch has no fallback.')).toBeInTheDocument()
        expect(screen.getByText('The graph contains a cycle.')).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Template is required' })).toBeInTheDocument()
        expect(screen.getByText('No route reaches a finish')).toBeInTheDocument()
        expect(screen.queryByRole('button', { name: 'A deleted node has an error' })).toBeNull()
        expect(within(screen.getByRole('region', { name: 'Issues' })).getAllByRole('listitem').map((item) => item.textContent)).toEqual([
            'Template is required',
            'No route reaches a finish',
            'A deleted node has an error',
        ])

        fireEvent.click(screen.getByRole('button', { name: 'Template is required' }))
        expect(onIssueSelect).toHaveBeenCalledWith(placeable)
    })
})

describe('NodeInspector', () => {
    it('composes compatible source fields after trigger fields and applies source defaults on selection', async () => {
        const user = userEvent.setup()
        const onConfigChange = vi.fn()
        const triggerDef: TriggerNodeTypePayload = {
            kind: 'trigger', type: 'host.trigger', driver: 'host', label: 'Host trigger', icon: null,
            description: 'A custom trigger.', outputs: ['started'], compatible_source_keys: ['source.a'],
            fields: [
                { key: 'source', type: 'select', label: 'Source', help: null, default: null, required: true, options: {}, dynamic_options: false },
                { key: 'event', type: 'select', label: 'Event', help: null, default: 'created', required: true, options: { created: 'Created' }, dynamic_options: false },
            ],
            default_config: { source: null, event: 'created' },
        }
        const sources: TriggerSourcesPayload = { host: [{
            key: 'source.a', driver: 'host', label: 'Source A', icon: null, description: null,
            fields: [{ key: 'filters.status', type: 'select', label: 'Status', help: null, default: 'open', required: false, options: {}, dynamic_options: true }],
            default_config: { 'filters.status': 'open' },
        }] }
        vi.stubGlobal('fetch', vi.fn(async (url: string) => {
            expect(url).toBe('/trigger-source-options/host.trigger/source.a/filters.status')
            return Response.json({ options: { open: 'Open', closed: 'Closed' } })
        }))

        const rendered = inspector({
            node: { id: 'trigger', type: triggerDef.type, kind: 'trigger', config: { source: null, event: 'created' }, isStart: true },
            def: triggerDef,
            triggerSources: sources,
            triggerOptionsTemplate: '/trigger-options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
            triggerSourceOptionsTemplate: '/trigger-source-options/__NODEFLOW_TYPE__/__NODEFLOW_SOURCE__/__NODEFLOW_FIELD__',
            onConfigChange,
        })

        expect(screen.getByLabelText(/Source/)).toBeInTheDocument()
        expect(screen.queryByLabelText('Status')).toBeNull()
        await user.selectOptions(screen.getByLabelText(/Source/), 'source.a')
        expect(onConfigChange).toHaveBeenCalledWith('source', 'source.a')
        expect(onConfigChange).toHaveBeenCalledWith('filters.status', 'open')

        rendered.rerender(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector
                    node={{ id: 'trigger', type: triggerDef.type, kind: 'trigger', config: { source: 'source.a', event: 'created', 'filters.status': 'open' }, isStart: true }}
                    def={triggerDef}
                    controls={mergeControls()}
                    errors={[{ node: 'trigger', field: 'filters.status', message: 'Dotted error' }]}
                    triggerSources={sources}
                    triggerOptionsTemplate="/trigger-options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__"
                    triggerSourceOptionsTemplate="/trigger-source-options/__NODEFLOW_TYPE__/__NODEFLOW_SOURCE__/__NODEFLOW_FIELD__"
                    onConfigChange={onConfigChange}
                    onDelete={vi.fn()}
                />
            </FieldOptionsContext.Provider>,
        )
        expect(await screen.findByLabelText('Status')).toHaveValue('open')
        expect(screen.getByText('Dotted error')).toBeInTheDocument()
        expect(screen.getAllByLabelText(/Source|Event|Status/).map((control) => control.getAttribute('aria-label') ?? control.textContent)).toHaveLength(3)
    })

    it('reports an incompatible selected source without rendering untrusted fields', () => {
        const triggerDef: TriggerNodeTypePayload = {
            kind: 'trigger', type: 'host.trigger', driver: 'host', label: 'Host trigger', icon: null,
            description: null, outputs: ['started'], compatible_source_keys: ['source.allowed'],
            fields: [{ key: 'source', type: 'select', label: 'Source', help: null, default: null, required: true, options: {}, dynamic_options: false }],
            default_config: {},
        }
        inspector({
            node: { id: 'trigger', type: triggerDef.type, kind: 'trigger', config: { source: 'source.unknown' }, isStart: true },
            def: triggerDef,
            triggerSources: { host: [{ key: 'source.unknown', driver: 'host', label: 'Unknown', icon: null, description: null, fields: [{ ...definition.fields[0]!, key: 'unsafe', label: 'Unsafe' }], default_config: {} }] },
        })

        expect(screen.getByRole('alert')).toHaveTextContent(/not compatible/i)
        expect(screen.queryByLabelText('Unsafe')).toBeNull()
    })

    it('reports source-field collisions without rendering duplicate controls', () => {
        const triggerDef: TriggerNodeTypePayload = {
            kind: 'trigger', type: 'host.trigger', driver: 'host', label: 'Host trigger', icon: null,
            description: null, outputs: ['started'], compatible_source_keys: ['source.allowed'],
            fields: [
                { key: 'source', type: 'select', label: 'Source', help: null, default: null, required: true, options: {}, dynamic_options: false },
                { key: 'event', type: 'text', label: 'Event', help: null, default: null, required: false, options: {}, dynamic_options: false },
            ],
            default_config: {},
        }
        inspector({
            node: { id: 'trigger', type: triggerDef.type, kind: 'trigger', config: { source: 'source.allowed' }, isStart: true },
            def: triggerDef,
            triggerSources: { host: [{ key: 'source.allowed', driver: 'host', label: 'Allowed', icon: null, description: null, fields: [{ ...definition.fields[0]!, key: 'event', label: 'Duplicate event' }], default_config: { event: 'unsafe' } }] },
        })

        expect(screen.getByRole('alert')).toHaveTextContent(/source field.*event.*collides/i)
        expect(screen.getAllByLabelText(/Event/)).toHaveLength(1)
        expect(screen.queryByLabelText('Duplicate event')).toBeNull()
    })

    // Moving within a compound control is still editing one field, so it must not split its undo transaction.
    it('only closes configuration when focus leaves a field row', () => {
        const Compound = ({ onChange }: FieldControlProps) => <>
            <input aria-label="Compound first" onChange={(event) => onChange(event.target.value)} />
            <input aria-label="Compound second" onChange={(event) => onChange(event.target.value)} />
        </>
        const onConfigBlur = vi.fn()
        inspector({
            def: { ...definition, fields: [{ ...definition.fields[0]!, key: 'compound', type: 'compound', label: 'Compound' }] },
            controls: mergeControls({ compound: Compound }),
            onConfigBlur,
        })
        const first = screen.getByRole('textbox', { name: 'Compound first' })
        const second = screen.getByRole('textbox', { name: 'Compound second' })

        fireEvent.change(first, { target: { value: 'one' } })
        fireEvent.blur(first, { relatedTarget: second })
        expect(onConfigBlur).not.toHaveBeenCalled()

        fireEvent.change(second, { target: { value: 'two' } })
        fireEvent.blur(second, { relatedTarget: document.body })
        expect(onConfigBlur).toHaveBeenCalledOnce()
    })

    it('starts in Configure and keeps human-facing information ahead of advanced metadata', () => {
        inspector({ errors: [{ node: 'send1', field: 'template', message: 'Template is required' }] })

        const configure = screen.getByRole('tab', { name: 'Configure' })
        const advanced = screen.getByRole('tab', { name: 'Advanced' })
        expect(configure).toHaveAttribute('aria-selected', 'true')
        expect(advanced).toHaveAttribute('aria-selected', 'false')
        expect(screen.getByRole('heading', { name: 'Send message' })).toBeInTheDocument()
        const inspectorHeader = screen.getByRole('complementary', { name: 'Node inspector' }).querySelector('header')
        if (inspectorHeader === null) throw new Error('Expected an inspector header.')
        expect(within(inspectorHeader).getByText('Messaging')).toBeInTheDocument()
        expect(screen.getByLabelText(/Template/)).toHaveValue('Welcome')
        expect(screen.getByText('The message body.')).toBeInTheDocument()
        expect(screen.getByText('Template is required')).toBeInTheDocument()
        const configurePanel = screen.getByRole('tabpanel', { name: 'Configure' })
        expect(within(configurePanel).queryByText('Registered type')).toBeNull()
        expect(screen.queryByRole('button', { name: 'Delete node' })).toBeNull()
        expect(within(configurePanel).queryByText('send1')).toBeNull()
    })

    it('implements linked keyboard-operable tabs and resets Configure for a new selection', () => {
        const rendered = inspector()
        const configure = screen.getByRole('tab', { name: 'Configure' })
        const advanced = screen.getByRole('tab', { name: 'Advanced' })
        const configurePanel = document.getElementById(configure.getAttribute('aria-controls') ?? '')
        const advancedPanel = document.getElementById(advanced.getAttribute('aria-controls') ?? '')
        expect(configurePanel).not.toBeNull()
        expect(advancedPanel).not.toBeNull()
        expect(configurePanel).toHaveAttribute('aria-labelledby', configure.id)
        expect(advancedPanel).toHaveAttribute('aria-labelledby', advanced.id)
        expect(configurePanel).not.toHaveAttribute('hidden')
        expect(advancedPanel).toHaveAttribute('hidden')

        fireEvent.keyDown(configure, { key: 'ArrowRight' })
        expect(advanced).toHaveFocus()
        expect(advanced).toHaveAttribute('aria-selected', 'true')
        expect(screen.getByRole('tabpanel', { name: 'Advanced' })).toBeInTheDocument()
        expect(configurePanel).toHaveAttribute('hidden')
        expect(advancedPanel).not.toHaveAttribute('hidden')
        fireEvent.keyDown(advanced, { key: 'ArrowLeft' })
        expect(configure).toHaveFocus()
        expect(configure).toHaveAttribute('aria-selected', 'true')
        expect(screen.getByRole('tabpanel', { name: 'Configure' })).toBeInTheDocument()
        expect(configurePanel).not.toHaveAttribute('hidden')
        expect(advancedPanel).toHaveAttribute('hidden')
        fireEvent.keyDown(configure, { key: 'End' })
        expect(advanced).toHaveFocus()
        expect(advanced).toHaveAttribute('aria-selected', 'true')
        fireEvent.keyDown(advanced, { key: 'Home' })
        expect(configure).toHaveFocus()
        fireEvent.click(advanced)
        rendered.rerender(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector node={{ ...node, id: 'send2' }} def={definition} controls={mergeControls()} errors={[]} onConfigChange={vi.fn()} onDelete={vi.fn()} />
            </FieldOptionsContext.Provider>,
        )
        expect(screen.getByRole('tab', { name: 'Configure' })).toHaveAttribute('aria-selected', 'true')
        expect(configurePanel).not.toHaveAttribute('hidden')
        expect(advancedPanel).toHaveAttribute('hidden')
    })

    it('opens Configure and focuses a requested field issue', async () => {
        const rendered = inspector()
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        rendered.rerender(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector node={node} def={definition} controls={mergeControls()} errors={[{ node: 'send1', field: 'template', message: 'Required' }]} issueToFocus={{ node: 'send1', field: 'template', message: 'Required' }} onConfigChange={vi.fn()} onDelete={vi.fn()} />
            </FieldOptionsContext.Provider>,
        )

        await waitFor(() => expect(screen.getByRole('tab', { name: 'Configure' })).toHaveAttribute('aria-selected', 'true'))
        expect(screen.getByLabelText(/Template/)).toHaveFocus()
    })

    it('focuses a field issue inside its own inspector when identical nodes are mounted together', async () => {
        const fieldKey = 'constructor"quoted'
        const selected = { ...node, config: { [fieldKey]: 'Welcome' } }
        const def = { ...definition, fields: [{ ...definition.fields[0]!, key: fieldKey }] }
        const firstProps = { node: selected, def, controls: mergeControls(), errors: [], onConfigChange: vi.fn(), onDelete: vi.fn() }
        const secondProps = { ...firstProps, onConfigChange: vi.fn(), onDelete: vi.fn() }
        const rendered = render(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector {...firstProps} />
                <NodeInspector {...secondProps} />
            </FieldOptionsContext.Provider>,
        )
        const inspectors = screen.getAllByRole('complementary', { name: 'Node inspector' })
        const firstControl = inspectors[0]!.querySelector('input')
        const secondControl = inspectors[1]!.querySelector('input')
        const firstLabel = inspectors[0]!.querySelector('label')
        const secondLabel = inspectors[1]!.querySelector('label')
        if (firstControl === null || secondControl === null || firstLabel === null || secondLabel === null) {
            throw new Error('Expected both inspector controls and labels.')
        }
        expect(firstControl.id).not.toBe(secondControl.id)
        expect(firstLabel.htmlFor).toBe(firstControl.id)
        expect(secondLabel.htmlFor).toBe(secondControl.id)
        expect(firstLabel.control).toBe(firstControl)
        expect(secondLabel.control).toBe(secondControl)
        await userEvent.click(secondLabel)
        expect(secondControl).toHaveFocus()

        rendered.rerender(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector {...firstProps} />
                <NodeInspector {...secondProps} issueToFocus={{ node: 'send1', field: fieldKey, message: 'Required' }} />
            </FieldOptionsContext.Provider>,
        )

        await waitFor(() => expect(secondControl).toHaveFocus())
        expect(firstControl).not.toHaveFocus()
    })

    it('places exact developer metadata and destructive actions only in Advanced', () => {
        const onDelete = vi.fn()
        inspector({ onDelete })
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))

        expect(screen.getByText('Node ID')).toBeInTheDocument()
        expect(screen.getByText('send1')).toBeInTheDocument()
        expect(screen.getByText('Registered type')).toBeInTheDocument()
        expect(screen.getByText('app.send')).toBeInTheDocument()
        expect(screen.getByText('Group')).toBeInTheDocument()
        expect(screen.getByText('subject')).toBeInTheDocument()
        expect(screen.getByText('sent, bounced')).toBeInTheDocument()
        expect(screen.queryByRole('button', { name: /start node/i })).toBeNull()
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))
        expect(onDelete).toHaveBeenCalledOnce()
    })

    it('lets an unknown node be deleted from Advanced without exposing make-start', () => {
        const onDelete = vi.fn()
        inspector({ def: undefined, onDelete })

        expect(screen.getByRole('alert')).toHaveTextContent('app.send')
        expect(screen.queryByLabelText('Template')).toBeNull()
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        expect(screen.getByText('app.send')).toBeInTheDocument()
        expect(screen.getAllByText('None')).toHaveLength(3)
        expect(screen.queryByRole('button', { name: /start node/i })).toBeNull()
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))
        expect(onDelete).toHaveBeenCalledOnce()
    })
})
