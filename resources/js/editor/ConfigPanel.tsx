import { controlFor } from '../controls'
import type { ControlMap } from '../controls'
import { useFieldOptions } from '../controls/useFieldOptions'
import type { FieldPayload, NodeCardData, NodeErrorEntry, NodeTypePayload } from '../graph/types'

type FieldRowProps = {
    nodeType: string
    field: FieldPayload
    value: unknown
    controls: ControlMap
    errors: string[]
    onChange: (value: unknown) => void
}

function FieldRow({ nodeType, field, value, controls, errors, onChange }: FieldRowProps) {
    const loaded = useFieldOptions(nodeType, field)
    const Control = controlFor(field.type, controls)
    const fieldErrors = loaded.error === null ? errors : [...errors, loaded.error]

    return (
        <Control
            field={field}
            value={value}
            onChange={onChange}
            errors={fieldErrors}
            options={loaded.options}
            optionsLoading={loaded.loading}
        />
    )
}

export type ConfigPanelProps = {
    node: NodeCardData
    def?: NodeTypePayload
    controls: ControlMap
    errors: NodeErrorEntry[]
    isStart: boolean
    onConfigChange: (key: string, value: unknown) => void
    onMakeStart: () => void
    onDelete: () => void
}

export function ConfigPanel({
    node,
    def,
    controls,
    errors,
    isStart,
    onConfigChange,
    onMakeStart,
    onDelete,
}: ConfigPanelProps) {
    const nodeErrors = errors.filter((entry) => entry.field === null)

    return (
        <aside className="space-y-4 rounded-md border bg-card p-4">
            <header className="space-y-1">
                <h2 className="font-semibold">{def?.label ?? node.type}</h2>
                <p className="font-mono text-xs text-muted-foreground">{node.id}</p>
                {def?.description && <p className="text-sm text-muted-foreground">{def.description}</p>}
                {def && (
                    <dl className="text-xs text-muted-foreground">
                        <div>
                            <dt className="inline font-medium">Cardinality: </dt>
                            <dd className="inline">{def.cardinality.length > 0 ? def.cardinality.join(', ') : 'none'}</dd>
                        </div>
                        <div>
                            <dt className="inline font-medium">Outputs: </dt>
                            <dd className="inline">{def.outputs.length > 0 ? def.outputs.join(', ') : 'none'}</dd>
                        </div>
                    </dl>
                )}
            </header>

            {def === undefined && (
                <p role="alert" className="rounded border border-destructive/50 p-2 text-sm text-destructive">
                    Node type "{node.type}" is not registered. Its configuration cannot be edited, but the node may
                    still be deleted.
                </p>
            )}

            {nodeErrors.length > 0 && (
                <ul role="alert" className="space-y-1 text-sm text-destructive">
                    {nodeErrors.map((entry, index) => <li key={`${entry.message}-${index}`}>{entry.message}</li>)}
                </ul>
            )}

            {def?.fields.map((definitionField) => {
                const hasValue = Object.prototype.hasOwnProperty.call(node.config, definitionField.key)
                const value = hasValue ? node.config[definitionField.key] : definitionField.default
                const fieldErrors = errors
                    .filter((entry) => entry.field === definitionField.key)
                    .map((entry) => entry.message)

                return (
                    <FieldRow
                        key={`${node.id}:${definitionField.key}`}
                        nodeType={node.type}
                        field={definitionField}
                        value={value}
                        controls={controls}
                        errors={fieldErrors}
                        onChange={(next) => onConfigChange(definitionField.key, next)}
                    />
                )
            })}

            <div className="flex gap-2">
                <button type="button" disabled={isStart} onClick={onMakeStart}>
                    {isStart ? 'Start node' : 'Make start node'}
                </button>
                <button type="button" onClick={onDelete}>Delete node</button>
            </div>
        </aside>
    )
}
