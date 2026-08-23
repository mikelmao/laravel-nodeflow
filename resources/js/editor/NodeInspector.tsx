import { useEffect, useId, useRef, useState } from 'react'
import type { KeyboardEvent } from 'react'
import type { ControlMap } from '../controls'
import type { NodeCardData, NodeErrorEntry, NodeTypePayload } from '../graph/types'
import { ConfigPanel } from './ConfigPanel'

type InspectorTab = 'configure' | 'advanced'

export type NodeInspectorProps = {
    node: NodeCardData
    def?: NodeTypePayload
    controls: ControlMap
    errors: NodeErrorEntry[]
    /** An issue chosen in FlowOverview; field issues switch back to Configure and focus their control. */
    issueToFocus?: NodeErrorEntry | null
    isStart: boolean
    onConfigChange: (key: string, value: unknown) => void
    onMakeStart: () => void
    onDelete: () => void
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
    isStart,
    onConfigChange,
    onMakeStart,
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
                <p className="text-sm text-muted-foreground">{def?.group || 'Unregistered node type'}</p>
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
                <ConfigPanel node={node} def={def} controls={controls} errors={errors} onConfigChange={onConfigChange} />
            </div>
            <div id={tabPanelId(generatedId, 'advanced')} role="tabpanel" aria-labelledby={advancedTabId} hidden={activeTab !== 'advanced'} className="space-y-5">
                <dl className="space-y-3 text-sm">
                    <div><dt className="font-medium text-muted-foreground">Node ID</dt><dd className="break-all font-mono">{node.id}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Registered type</dt><dd className="break-all font-mono">{node.type}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Group</dt><dd>{def?.group || 'None'}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Cardinality</dt><dd>{empty(def?.cardinality ?? [])}</dd></div>
                    <div><dt className="font-medium text-muted-foreground">Declared outputs</dt><dd>{empty(def?.outputs ?? [])}</dd></div>
                </dl>
                <div className="space-y-2 border-t border-border pt-4">
                    <button type="button" disabled={isStart} onClick={onMakeStart} className="w-full rounded-md border border-input px-3 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60">
                        {isStart ? 'Start node' : 'Make start node'}
                    </button>
                    <button type="button" onClick={onDelete} className="w-full rounded-md border border-destructive bg-destructive px-3 py-2 text-sm text-destructive-foreground hover:opacity-90">
                        Delete node
                    </button>
                </div>
            </div>
        </aside>
    )
}
