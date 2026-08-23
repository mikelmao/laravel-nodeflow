import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { mergeControls } from '../controls'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import type { NodeCardData, NodeTypePayload } from '../graph/types'
import { FlowOverview, type FlowOverviewProps } from './FlowOverview'
import { NodeInspector } from './NodeInspector'

const node: NodeCardData = {
    id: 'send1',
    type: 'app.send',
    config: { template: 'Welcome' },
    isStart: false,
}

const definition: NodeTypePayload = {
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
        isStart: false,
        onConfigChange: vi.fn(),
        onMakeStart: vi.fn(),
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
                <NodeInspector node={{ ...node, id: 'send2' }} def={definition} controls={mergeControls()} errors={[]} isStart={false} onConfigChange={vi.fn()} onMakeStart={vi.fn()} onDelete={vi.fn()} />
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
                <NodeInspector node={node} def={definition} controls={mergeControls()} errors={[{ node: 'send1', field: 'template', message: 'Required' }]} issueToFocus={{ node: 'send1', field: 'template', message: 'Required' }} isStart={false} onConfigChange={vi.fn()} onMakeStart={vi.fn()} onDelete={vi.fn()} />
            </FieldOptionsContext.Provider>,
        )

        await waitFor(() => expect(screen.getByRole('tab', { name: 'Configure' })).toHaveAttribute('aria-selected', 'true'))
        expect(screen.getByLabelText(/Template/)).toHaveFocus()
    })

    it('focuses a field issue inside its own inspector when identical nodes are mounted together', async () => {
        const fieldKey = 'constructor"quoted'
        const selected = { ...node, config: { [fieldKey]: 'Welcome' } }
        const def = { ...definition, fields: [{ ...definition.fields[0]!, key: fieldKey }] }
        const firstProps = { node: selected, def, controls: mergeControls(), errors: [], isStart: false, onConfigChange: vi.fn(), onMakeStart: vi.fn(), onDelete: vi.fn() }
        const secondProps = { ...firstProps, onConfigChange: vi.fn(), onMakeStart: vi.fn(), onDelete: vi.fn() }
        const rendered = render(
            <FieldOptionsContext.Provider value={{ template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__', cache: new Map() }}>
                <NodeInspector {...firstProps} />
                <NodeInspector {...secondProps} />
            </FieldOptionsContext.Provider>,
        )
        const inspectors = screen.getAllByRole('complementary', { name: 'Node inspector' })
        const firstControl = inspectors[0]!.querySelector('input')
        const secondControl = inspectors[1]!.querySelector('input')
        if (firstControl === null || secondControl === null) throw new Error('Expected both inspector controls.')

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
        const onMakeStart = vi.fn()
        const onDelete = vi.fn()
        inspector({ onMakeStart, onDelete })
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))

        expect(screen.getByText('Node ID')).toBeInTheDocument()
        expect(screen.getByText('send1')).toBeInTheDocument()
        expect(screen.getByText('Registered type')).toBeInTheDocument()
        expect(screen.getByText('app.send')).toBeInTheDocument()
        expect(screen.getByText('Group')).toBeInTheDocument()
        expect(screen.getByText('subject')).toBeInTheDocument()
        expect(screen.getByText('sent, bounced')).toBeInTheDocument()
        fireEvent.click(screen.getByRole('button', { name: 'Make start node' }))
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))
        expect(onMakeStart).toHaveBeenCalledOnce()
        expect(onDelete).toHaveBeenCalledOnce()
    })

    it('shows current-start copy and lets an unknown node be deleted from Advanced', () => {
        const onDelete = vi.fn()
        inspector({ def: undefined, isStart: true, onDelete })

        expect(screen.getByRole('alert')).toHaveTextContent('app.send')
        expect(screen.queryByLabelText('Template')).toBeNull()
        fireEvent.click(screen.getByRole('tab', { name: 'Advanced' }))
        expect(screen.getByText('app.send')).toBeInTheDocument()
        expect(screen.getAllByText('None')).toHaveLength(3)
        expect(screen.getByRole('button', { name: 'Start node' })).toBeDisabled()
        fireEvent.click(screen.getByRole('button', { name: 'Delete node' }))
        expect(onDelete).toHaveBeenCalledOnce()
    })
})
