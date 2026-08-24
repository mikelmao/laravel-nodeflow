import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import { mergeControls } from '../controls'
import type { FieldControlProps } from '../controls/types'
import type { FieldPayload, NodeCardData, NodeErrorEntry, NodeTypePayload } from '../graph/types'
import { ConfigPanel } from './ConfigPanel'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return {
        key: 'template',
        type: 'text',
        label: 'Template',
        help: null,
        default: null,
        required: false,
        options: {},
        dynamic_options: false,
        ...overrides,
    }
}

function definition(overrides: Partial<NodeTypePayload> = {}): NodeTypePayload {
    return {
        kind: 'executable',
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: 'A message is sent.',
        outputs: ['sent'],
        fields: [field()],
        default_config: {},
        cardinality: ['subject'],
        ...overrides,
    }
}

const node: NodeCardData = {
    id: 'send1',
    type: 'app.send',
    kind: 'executable',
    config: { template: 'welcome' },
    isStart: false,
}

function subject(options: {
    selected?: NodeCardData
    def?: NodeTypePayload | undefined
    controls?: Record<string, (props: FieldControlProps) => React.ReactElement | null>
    errors?: NodeErrorEntry[]
    onConfigChange?: (key: string, value: unknown) => void
} = {}) {
    const selected = options.selected ?? node
    const def = Object.prototype.hasOwnProperty.call(options, 'def') ? options.def : definition()
    const controls = options.controls ?? mergeControls()
    const errors = options.errors ?? []
    const onConfigChange = options.onConfigChange ?? vi.fn()

    return render(
        <FieldOptionsContext.Provider
            value={{
                template: '/options/__NODEFLOW_TYPE__/__NODEFLOW_FIELD__',
                cache: new Map(),
            }}
        >
            <ConfigPanel
                node={selected}
                def={def}
                controls={controls}
                errors={errors}
                onConfigChange={onConfigChange}
            />
        </FieldOptionsContext.Provider>,
    )
}

describe('ConfigPanel', () => {
    // The node is authoritative; counterfactual reading definition defaults first replaces a saved value.
    it('renders the actual node value instead of the definition default', () => {
        subject({ def: definition({ fields: [field({ default: 'fallback' })] }) })

        expect(screen.getByLabelText('Template')).toHaveValue('welcome')
        expect(screen.queryByDisplayValue('fallback')).toBeNull()
    })

    // Controls emit each edit; counterfactual buffering locally leaves the graph and autosave stale.
    it('reports clearing and then typing through onConfigChange', () => {
        const onConfigChange = vi.fn()
        subject({ onConfigChange })

        const input = screen.getByLabelText('Template')
        fireEvent.change(input, { target: { value: '' } })
        fireEvent.change(input, { target: { value: 'x' } })

        expect(onConfigChange).toHaveBeenNthCalledWith(1, 'template', '')
        expect(onConfigChange).toHaveBeenNthCalledWith(2, 'template', 'x')
    })

    // An own null means deliberately blank; counterfactual `??` silently restores the definition fallback.
    it('preserves an explicit null config value instead of applying the field default', () => {
        subject({
            selected: { ...node, config: { template: null } },
            def: definition({ fields: [field({ default: 'fallback' })] }),
        })

        expect(screen.getByLabelText('Template')).toHaveValue('')
        expect(screen.queryByDisplayValue('fallback')).toBeNull()
    })

    // Host controls extend the built-ins; counterfactual ignoring overrides makes custom field types unusable.
    it('renders a registered host control', () => {
        const Town = ({ field, value, onChange }: FieldControlProps) => (
            <label>
                {field.label}
                <input value={String(value ?? '')} onChange={(event) => onChange(event.target.value)} />
            </label>
        )
        subject({
            selected: { ...node, config: { destination: 'Bucharest' } },
            def: definition({ fields: [field({ key: 'destination', type: 'town', label: 'Town' })] }),
            controls: mergeControls({ town: Town }),
        })

        expect(screen.getByLabelText('Town')).toHaveValue('Bucharest')
    })

    // Unknown controls must be loud; counterfactual falling back to text silently accepts invalid data.
    it('names an unregistered field type without rendering an input', () => {
        subject({ def: definition({ fields: [field({ type: 'town' })] }) })

        expect(screen.getByRole('alert')).toHaveTextContent('town')
        expect(document.querySelectorAll('input, select, textarea')).toHaveLength(0)
    })

    // Field errors stay beside their field; counterfactual passing every error to every control duplicates messages.
    it('routes template and channel errors only to the matching field', () => {
        subject({
            def: definition({ fields: [field(), field({ key: 'channel', label: 'Channel' })] }),
            errors: [
                { node: 'send1', field: 'template', message: 'template problem' },
                { node: 'send1', field: 'channel', message: 'channel problem' },
            ],
        })

        const template = screen.getByLabelText('Template').closest('div')
        const channel = screen.getByLabelText('Channel').closest('div')
        expect(template).toHaveTextContent('template problem')
        expect(template).not.toHaveTextContent('channel problem')
        expect(channel).toHaveTextContent('channel problem')
        expect(channel).not.toHaveTextContent('template problem')
    })

    // Null-field errors concern the node as a whole; counterfactual filtering them out makes publish failures disappear.
    it('renders a node-level error', () => {
        subject({ errors: [{ node: 'send1', field: null, message: 'node is unreachable' }] })

        expect(screen.getByRole('alert')).toHaveTextContent('node is unreachable')
    })

    // This component owns only configurable content; counterfactual keeping its old aside/actions duplicates inspector policy.
    it('contains the unknown-type notice inside the node configuration region only', () => {
        subject({ def: undefined })

        expect(screen.getByLabelText('Node configuration')).toContainElement(screen.getByRole('alert'))
        expect(screen.queryByRole('complementary')).toBeNull()
        expect(screen.queryByRole('button', { name: /delete|start/i })).toBeNull()
    })
})
