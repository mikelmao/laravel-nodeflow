import { useEffect, useId, useMemo, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { ControlMap } from '../controls'
import type { FieldOptionsSource } from '../controls/useFieldOptions'
import { cloneGraphConfig } from '../graph/json'
import type { GraphComponentPayload, NodeCardData, NodeErrorEntry, TriggerSourcePayload, TriggerSourcesPayload, WebhookMetadata } from '../graph/types'
import { triggerSourceOptionsTemplate } from '../http'
import { ConfigPanel } from './ConfigPanel'
import { WebhookDetails } from './WebhookDetails'

type InspectorTab = 'configure' | 'advanced'

export type NodeInspectorProps = {
    node: NodeCardData
    def?: GraphComponentPayload
    controls: ControlMap
    errors: NodeErrorEntry[]
    /** An issue chosen in FlowOverview; field issues switch back to Configure and focus their control. */
    issueToFocus?: NodeErrorEntry | null
    onConfigChange: (key: string, value: unknown) => void
    onConfigBlur?: () => void
    triggerSources?: TriggerSourcesPayload
    triggerOptionsTemplate?: string
    triggerSourceOptionsTemplate?: string
    onTriggerSourceChange?: (source: string | null, defaults: Record<string, unknown>, priorFieldKeys: string[]) => void
    webhook?: WebhookMetadata | null
    webhookSecret?: string | null
    webhookRotating?: boolean
    webhookRotationError?: string | null
    onAcknowledgeWebhookSecret?: () => void
    onRotateWebhookSecret?: () => void
    onDelete: () => void
}

function ownSources(sources: TriggerSourcesPayload, driver: string): TriggerSourcePayload[] {
    return Object.prototype.hasOwnProperty.call(sources, driver) ? sources[driver] ?? [] : []
}

function empty(values: string[]): string {
    return values.length === 0 ? 'None' : values.join(', ')
}

function tabPanelId(id: string, tab: InspectorTab): string {
    return `node-inspector-${id}-${tab}-panel`
}

export function NodeInspector({
    node,
    def,
    controls,
    errors,
    issueToFocus = null,
    onConfigChange,
    onConfigBlur,
    triggerSources = {},
    triggerOptionsTemplate,
    triggerSourceOptionsTemplate: sourceOptionsTemplate,
    onTriggerSourceChange,
    webhook = null,
    webhookSecret = null,
    webhookRotating = false,
    webhookRotationError = null,
    onAcknowledgeWebhookSecret = () => {},
    onRotateWebhookSecret = () => {},
    onDelete,
}: NodeInspectorProps) {
    const generatedId = useId().replace(/:/g, '')
    const [activeTab, setActiveTab] = useState<InspectorTab>('configure')
    const rootRef = useRef<HTMLElement>(null)
    const configureRef = useRef<HTMLButtonElement>(null)
    const advancedRef = useRef<HTMLButtonElement>(null)
    const configureTabId = `node-inspector-${generatedId}-configure-tab`
    const advancedTabId = `node-inspector-${generatedId}-advanced-tab`
    const selectedIssue = issueToFocus?.node === node.id && issueToFocus.field !== null ? issueToFocus : null
    const optionCaches = useRef(new Map<string, Map<string, Record<string, string>>>())
    const compatibleSources = useMemo(() => {
        if (def?.kind !== 'trigger') return []
        const keys = new Set(def.compatible_source_keys)
        return ownSources(triggerSources, def.driver).filter((source) => source.driver === def.driver && keys.has(source.key))
    }, [def, triggerSources])
    const selectedSourceKey = typeof node.config.source === 'string' ? node.config.source : null
    const selectedSource = selectedSourceKey === null
        ? undefined
        : compatibleSources.find((source) => source.key === selectedSourceKey)
    const baseFieldKeys = useMemo(
        () => new Set(def?.kind === 'trigger' ? def.fields.map((field) => field.key) : []),
        [def],
    )
    const sourceFieldCollisions = useMemo(
        () => selectedSource?.fields.filter((field) => baseFieldKeys.has(field.key)) ?? [],
        [baseFieldKeys, selectedSource],
    )
    const selectedSourceFields = useMemo(
        () => selectedSource?.fields.filter((field) => !baseFieldKeys.has(field.key)) ?? [],
        [baseFieldKeys, selectedSource],
    )
    const contributedFieldKeys = useMemo(() => [...new Set(compatibleSources.flatMap((source) =>
        source.fields.filter((field) => !baseFieldKeys.has(field.key)).map((field) => field.key),
    ))], [baseFieldKeys, compatibleSources])
    const composedDef = useMemo((): GraphComponentPayload | undefined => {
        if (def?.kind !== 'trigger') return def
        const sourceOptions = Object.fromEntries(compatibleSources.map((source) => [source.key, source.label]))
        const baseFields = def.fields.map((field) => field.key === 'source'
            ? { ...field, options: sourceOptions, dynamic_options: false }
            : field)
        return { ...def, fields: [...baseFields, ...selectedSourceFields] }
    }, [compatibleSources, def, selectedSourceFields])
    const localErrors = useMemo(() => {
        if (def?.kind !== 'trigger') return errors
        if (selectedSourceKey !== null && selectedSource === undefined) {
            return [...errors, { node: node.id, field: 'source', message: 'The selected trigger source is not compatible with this trigger.' }]
        }
        if (compatibleSources.length === 0) {
            return [...errors, { node: node.id, field: 'source', message: 'No compatible trigger source is registered by this application.' }]
        }
        return [
            ...errors,
            ...sourceFieldCollisions.map((field) => ({
                node: node.id,
                field: field.key,
                message: `The source field [${field.key}] collides with a reserved trigger field.`,
            })),
        ]
    }, [compatibleSources.length, def, errors, node.id, selectedSource, selectedSourceKey, sourceFieldCollisions])
    const fieldOptionsSources = useMemo(() => {
        const result: Record<string, FieldOptionsSource> = {}
        if (def?.kind !== 'trigger') return result
        const sourceFor = (template: string) => {
            let cache = optionCaches.current.get(template)
            if (cache === undefined) {
                cache = new Map()
                optionCaches.current.set(template, cache)
            }
            return { template, cache }
        }
        if (triggerOptionsTemplate !== undefined) {
            for (const field of def.fields) result[field.key] = sourceFor(triggerOptionsTemplate)
        }
        if (selectedSource !== undefined && sourceOptionsTemplate !== undefined) {
            const template = triggerSourceOptionsTemplate(sourceOptionsTemplate, selectedSource.key)
            for (const field of selectedSourceFields) result[field.key] = sourceFor(template)
        }
        return result
    }, [def, selectedSource, selectedSourceFields, sourceOptionsTemplate, triggerOptionsTemplate])

    function changeConfig(key: string, value: unknown): void {
        if (def?.kind !== 'trigger' || key !== 'source') {
            onConfigChange(key, value)
            return
        }
        const nextKey = typeof value === 'string' && value !== '' ? value : null
        const next = compatibleSources.find((source) => source.key === nextKey)
        const rawDefaults = next === undefined ? {} : cloneGraphConfig(next.default_config)
        const nextBaseKeys = new Set(def.fields.map((field) => field.key))
        const nextSourceFieldKeys = new Set(next?.fields.filter((field) => !nextBaseKeys.has(field.key)).map((field) => field.key) ?? [])
        const defaults = Object.fromEntries(Object.entries(rawDefaults).filter(([defaultKey]) => nextSourceFieldKeys.has(defaultKey)))
        // Clean every registered source-owned key so recovery from an invalid
        // persisted selection cannot leave another source's stale config.
        const priorFieldKeys = contributedFieldKeys
        if (onTriggerSourceChange !== undefined) {
            onTriggerSourceChange(nextKey, defaults, priorFieldKeys)
            return
        }
        onConfigChange('source', nextKey)
        for (const [defaultKey, defaultValue] of Object.entries(defaults)) onConfigChange(defaultKey, defaultValue)
    }

    useEffect(() => {
        setActiveTab('configure')
    }, [node.id])

    useEffect(() => {
        if (selectedIssue !== null) {
            setActiveTab('configure')
        }
    }, [selectedIssue])

    useEffect(() => {
        if (activeTab !== 'configure' || selectedIssue === null) return

        const fieldKey = selectedIssue.field
        if (fieldKey === null) return
        const field = [...(rootRef.current?.querySelectorAll<HTMLElement>('[data-nodeflow-field-key]') ?? [])]
            .find((row) => row.dataset.nodeflowFieldKey === fieldKey)
        const control = field?.querySelector<HTMLElement>('input, select, textarea, button, [tabindex]')
        control?.focus()
    }, [activeTab, node.id, selectedIssue])

    function activate(tab: InspectorTab): void {
        setActiveTab(tab)
        ;(tab === 'configure' ? configureRef : advancedRef).current?.focus()
    }

    function onTabKeyDown(event: KeyboardEvent<HTMLButtonElement>): void {
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
            event.preventDefault()
            activate(activeTab === 'configure' ? 'advanced' : 'configure')
        } else if (event.key === 'Home') {
            event.preventDefault()
            activate('configure')
        } else if (event.key === 'End') {
            event.preventDefault()
            activate('advanced')
        }
    }

    return (
        <aside ref={rootRef} aria-label="Node inspector" className="flex min-h-0 flex-col gap-4 rounded-lg border border-border bg-card p-4 text-card-foreground">
            <header className="space-y-1">
                <h2 className="text-base font-semibold">{def?.label ?? 'Unregistered node'}</h2>
                <p className="text-sm text-muted-foreground">{def === undefined ? 'Unregistered node type' : def.kind === 'trigger' ? 'Trigger' : def.group || 'Unregistered node type'}</p>
                {def?.description && <p className="text-sm text-muted-foreground">{def.description}</p>}
            </header>

            <div role="tablist" aria-label="Node inspector sections" className="flex border-b border-border">
                <button
                    ref={configureRef}
                    id={configureTabId}
                    type="button"
                    role="tab"
                    aria-selected={activeTab === 'configure'}
                    aria-controls={tabPanelId(generatedId, 'configure')}
                    tabIndex={activeTab === 'configure' ? 0 : -1}
                    onClick={() => setActiveTab('configure')}
                    onKeyDown={onTabKeyDown}
                    className="border-b-2 border-transparent px-3 py-2 text-sm font-medium aria-selected:border-primary aria-selected:text-foreground"
                >
                    Configure
                </button>
                <button
                    ref={advancedRef}
                    id={advancedTabId}
                    type="button"
                    role="tab"
                    aria-selected={activeTab === 'advanced'}
                    aria-controls={tabPanelId(generatedId, 'advanced')}
                    tabIndex={activeTab === 'advanced' ? 0 : -1}
                    onClick={() => setActiveTab('advanced')}
                    onKeyDown={onTabKeyDown}
                    className="border-b-2 border-transparent px-3 py-2 text-sm font-medium aria-selected:border-primary aria-selected:text-foreground"
                >
                    Advanced
                </button>
            </div>

            <div id={tabPanelId(generatedId, 'configure')} role="tabpanel" aria-labelledby={configureTabId} hidden={activeTab !== 'configure'} className="min-h-0 overflow-y-auto">
                <div className="space-y-5">
                    {def?.kind === 'trigger' && compatibleSources.length === 0 && (
                        <p className="rounded-md border border-border bg-muted p-3 text-sm text-muted-foreground">Register a compatible trigger source in the host application before this trigger can be published.</p>
                    )}
                    <ConfigPanel node={node} def={composedDef} controls={controls} errors={localErrors} onConfigChange={changeConfig} onFieldBlur={onConfigBlur} fieldOptionsSources={fieldOptionsSources} />
                    {def?.kind === 'trigger' && def.driver === 'webhook' && (
                        <WebhookDetails
                            metadata={webhook}
                            oneTimeSecret={webhookSecret}
                            rotating={webhookRotating}
                            rotationError={webhookRotationError}
                            onAcknowledgeSecret={onAcknowledgeWebhookSecret}
                            onRotate={onRotateWebhookSecret}
                        />
                    )}
                </div>
            </div>
            <div id={tabPanelId(generatedId, 'advanced')} role="tabpanel" aria-labelledby={advancedTabId} hidden={activeTab !== 'advanced'} className="space-y-5">
                <dl className="space-y-3 text-sm">
                    <div><dt className="font-medium text-muted-foreground">Node ID</dt><dd className="break-all font-mono">{node.id}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Registered type</dt><dd className="break-all font-mono">{node.type}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Group</dt><dd>{def?.kind === 'executable' ? def.group || 'None' : def?.kind === 'trigger' ? 'Trigger' : 'None'}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Cardinality</dt><dd>{empty(def?.kind === 'executable' ? def.cardinality : [])}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Declared outputs</dt><dd>{empty(def?.outputs ?? [])}</dd></div>
                </dl>
                <div className="space-y-2 border-t border-border pt-4">
                    <button type="button" onClick={onDelete} className="w-full rounded-md border border-destructive bg-destructive px-3 py-2 text-sm text-destructive-foreground hover:opacity-90">
                        Delete node
                    </button>
                </div>
            </div>
        </aside>
    )
}
