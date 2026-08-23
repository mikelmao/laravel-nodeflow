import { controlFor } from '../controls'
import type { ControlMap } from '../controls'
import { FieldControlIdProvider } from '../controls/FieldControlId'
import { useFieldOptions } from '../controls/useFieldOptions'
import { useId } from 'react'
import type { FieldPayload, NodeCardData, NodeErrorEntry, NodeTypePayload } from '../graph/types'

type FieldRowProps = {
    id: string
    nodeType: string
    field: FieldPayload
    value: unknown
    controls: ControlMap
    errors: string[]
    onChange: (value: unknown) => void
}

function FieldRow({ id, nodeType, field, value, controls, errors, onChange }: FieldRowProps) {
    const controlId = `nf-${useId().replace(/:/g, '')}`
    const loaded = useFieldOptions(nodeType, field)
    const Control = controlFor(field.type, controls)
    const fieldErrors = loaded.error === null ? errors : [...errors, loaded.error]

    return (
        <div id={id} data-nodeflow-field-key={field.key}>
            <FieldControlIdProvider id={controlId}>
                <Control
                    field={field}
                    value={value}
                    onChange={onChange}
                    errors={fieldErrors}
                    options={loaded.options}
                    optionsLoading={loaded.loading}
                />
            </FieldControlIdProvider>
        </div>
    )
}

function NodeIssueList({ entries }: { entries: NodeErrorEntry[] }) {
    return (
        <ul role="alert" className="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
            {entries.map((entry, index) => <li key={`${entry.message}-${index}`}>{entry.message}</li>)}
        </ul>
    )
}

function UnknownNodeNotice({ type }: { type: string }) {
    return (
        <p role="alert" className="rounded-md border border-destructive/50 p-3 text-sm text-destructive">
            Node type “{type}” is not registered. Its configuration cannot be edited.
        </p>
    )
}

function nodeConfigFieldId(instanceId: string, nodeId: string, field: string): string {
    return `node-config-${instanceId}-${encodeURIComponent(nodeId)}-${encodeURIComponent(field)}`
}

export type ConfigPanelProps = {
    node: NodeCardData
    def?: NodeTypePayload
    controls: ControlMap
    errors: NodeErrorEntry[]
    onConfigChange: (key: string, value: unknown) => void
}

/** Field content only: metadata and node-level actions belong to NodeInspector. */
export function ConfigPanel({ node, def, controls, errors, onConfigChange }: ConfigPanelProps) {
    const instanceId = useId().replace(/:/g, '')
    const nodeErrors = errors.filter((entry) => entry.field === null)
    const fieldRowProps = (definitionField: FieldPayload): FieldRowProps => {
        const hasValue = Object.prototype.hasOwnProperty.call(node.config, definitionField.key)
        const value = hasValue ? node.config[definitionField.key] : definitionField.default
        const fieldErrors = errors
            .filter((entry) => entry.field === definitionField.key)
            .map((entry) => entry.message)

        return {
            id: nodeConfigFieldId(instanceId, node.id, definitionField.key),
            nodeType: node.type,
            field: definitionField,
            value,
            controls,
            errors: fieldErrors,
            onChange: (next) => onConfigChange(definitionField.key, next),
        }
    }

    return (
        <div className="space-y-5" aria-label="Node configuration">
            {nodeErrors.length > 0 && <NodeIssueList entries={nodeErrors} />}
            {def === undefined
                ? <UnknownNodeNotice type={node.type} />
                : def.fields.map((definitionField) => (
                    <FieldRow key={JSON.stringify([node.id, definitionField.key])} {...fieldRowProps(definitionField)} />
                ))}
        </div>
    )
}
