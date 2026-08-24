import { controlFor } from '../controls'
import type { ControlMap } from '../controls'
import { FieldControlIdProvider } from '../controls/FieldControlId'
import { FieldOptionsContext, useFieldOptions, type FieldOptionsSource } from '../controls/useFieldOptions'
import { cloneJsonValue } from '../graph/json'
import { useId, useMemo, type FocusEvent } from 'react'
import type { FieldPayload, GraphComponentPayload, NodeCardData, NodeErrorEntry } from '../graph/types'

type FieldRowProps = {
    id: string
    nodeType: string
    field: FieldPayload
    value: unknown
    controls: ControlMap
    errors: string[]
    onChange: (value: unknown) => void
    onFieldBlur?: () => void
    optionsSource?: FieldOptionsSource
}

function FieldRowContent({ id, nodeType, field, value, controls, errors, onChange, onFieldBlur }: Omit<FieldRowProps, 'optionsSource'>) {
    const controlId = `nf-${useId().replace(/:/g, '')}`
    // Host controls are allowed to use mutable UI models. Give each mounted
    // field a private, stable JSON copy so an in-place edit cannot mutate the
    // palette, current document, or an undo snapshot before onChange commits.
    const controlField = useMemo(() => ({
        ...field,
        default: copiedControlValue(field.default),
        options: { ...field.options },
    }), [field])
    const controlValue = useMemo(() => copiedControlValue(value), [value])
    const loaded = useFieldOptions(nodeType, controlField)
    const Control = controlFor(controlField.type, controls)
    const fieldErrors = loaded.error === null ? errors : [...errors, loaded.error]

    return (
        <div
            id={id}
            data-nodeflow-field-key={field.key}
            onBlur={(event: FocusEvent<HTMLDivElement>) => {
                const next = event.relatedTarget
                if (next instanceof Node && event.currentTarget.contains(next)) return
                onFieldBlur?.()
            }}
        >
            <FieldControlIdProvider id={controlId}>
                <Control
                    field={controlField}
                    value={controlValue}
                    onChange={onChange}
                    errors={fieldErrors}
                    options={loaded.options}
                    optionsLoading={loaded.loading}
                />
            </FieldControlIdProvider>
        </div>
    )
}

function FieldRow({ optionsSource, ...props }: FieldRowProps) {
    return optionsSource === undefined
        ? <FieldRowContent {...props} />
        : <FieldOptionsContext.Provider value={optionsSource}><FieldRowContent {...props} /></FieldOptionsContext.Provider>
}

function copiedControlValue(value: unknown): unknown {
    try {
        return cloneJsonValue(value)
    } catch {
        return null
    }
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
    def?: GraphComponentPayload
    controls: ControlMap
    errors: NodeErrorEntry[]
    onConfigChange: (key: string, value: unknown) => void
    onFieldBlur?: () => void
    fieldOptionsSources?: Record<string, FieldOptionsSource>
}

/** Field content only: metadata and node-level actions belong to NodeInspector. */
export function ConfigPanel({ node, def, controls, errors, onConfigChange, onFieldBlur, fieldOptionsSources = {} }: ConfigPanelProps) {
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
            onFieldBlur,
            optionsSource: Object.prototype.hasOwnProperty.call(fieldOptionsSources, definitionField.key)
                ? fieldOptionsSources[definitionField.key]
                : undefined,
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
