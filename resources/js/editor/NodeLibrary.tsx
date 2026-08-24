import { useCallback, useId, useRef, useState, type DragEvent } from 'react'
import type { GraphComponentPayload, NodeTypePayload, TriggerNodeTypePayload, TriggerSourcesPayload } from '../graph/types'
import { NodeflowIcon } from '../presentation/icons'
import { categoryClasses, categoryPresentation } from '../presentation/node'
import { ConfirmationDialog } from './ConfirmationDialog'

export type NodeLibraryProps = {
    palette: NodeTypePayload[]
    triggers?: TriggerNodeTypePayload[]
    triggerSources?: TriggerSourcesPayload
    hasTrigger?: boolean
    onAdd: (definition: NodeTypePayload) => void
    onAddTrigger?: (definition: TriggerNodeTypePayload) => void
    onReplaceTrigger?: (definition: TriggerNodeTypePayload) => void
    onRequestClose?: () => void
    searchInputRef?: CompatibleRef<HTMLInputElement>
}

/** A structurally compatible ref, safe when a host and package resolve React types separately. */
export type CompatibleRef<T> =
    | { current: T | null }
    | ((instance: T | null) => unknown)
    | null

function attachRef<T>(ref: CompatibleRef<T> | undefined, instance: T | null): void | (() => void) {
    if (typeof ref === 'function') {
        const cleanup = ref(instance)

        if (typeof cleanup === 'function') {
            return () => { void cleanup() }
        }

        return
    }

    if (ref !== null && ref !== undefined) {
        ref.current = instance
    }
}

type IndexedDefinition = { definition: GraphComponentPayload; index: number }

function normalizeSearch(value: string): string {
    return value.trim().toLocaleLowerCase()
}

function normalizeSort(value: string): string {
    return value.trim().toLowerCase()
}

function sortDefinitions(left: IndexedDefinition, right: IndexedDefinition): number {
    const leftGroup = left.definition.kind === 'trigger' ? 'Triggers' : left.definition.group
    const rightGroup = right.definition.kind === 'trigger' ? 'Triggers' : right.definition.group
    const byGroup = normalizeSort(leftGroup).localeCompare(normalizeSort(rightGroup), 'en', {
        sensitivity: 'base',
        numeric: true,
    })
    if (byGroup !== 0) return byGroup

    const byLabel = normalizeSort(left.definition.label).localeCompare(normalizeSort(right.definition.label), 'en', {
        sensitivity: 'base',
        numeric: true,
    })
    if (byLabel !== 0) return byLabel

    return left.index - right.index
}

function searchableText(definition: GraphComponentPayload): string {
    return [definition.label, definition.kind === 'trigger' ? 'Triggers' : definition.group, definition.description ?? '', definition.type]
        .map(normalizeSearch)
        .join(' ')
}

/** Filters host definitions without changing registry order, then deterministically groups library entries. */
export function filterNodeDefinitions(definitions: NodeTypePayload[], query: string): NodeTypePayload[] {
    const terms = normalizeSearch(query).split(/\s+/).filter(Boolean)

    return definitions
        .map((definition, index) => ({ definition, index }))
        .filter(({ definition }) => {
            const haystack = searchableText(definition)
            return terms.every((term) => haystack.includes(term))
        })
        .sort(sortDefinitions)
        .map(({ definition }) => definition)
}

const conciseDescriptionLimit = 120

function visibleCharacters(text: string): string[] | null {
    if (typeof Intl.Segmenter !== 'function') return null

    return Array.from(new Intl.Segmenter('en', { granularity: 'grapheme' }).segment(text), ({ segment }) => segment)
}

function conciseDescription(description: string | null): string {
    const text = description?.trim()
    if (!text) return 'No description provided.'
    const characters = visibleCharacters(text)
    if (characters === null) return text
    return characters.length > conciseDescriptionLimit
        ? `${characters.slice(0, conciseDescriptionLimit - 1).join('')}…`
        : text
}

function groupLabel(definition: GraphComponentPayload): string {
    return definition.kind === 'trigger' ? 'Triggers' : definition.group.trim() || 'Other'
}

function hasCompatibleSource(definition: TriggerNodeTypePayload, sources: TriggerSourcesPayload | undefined): boolean {
    if (sources === undefined) return definition.compatible_source_keys.length > 0
    const allowed = new Set(definition.compatible_source_keys)
    const registered = Object.prototype.hasOwnProperty.call(sources, definition.driver) ? sources[definition.driver] ?? [] : []
    return registered.some((source) => source.driver === definition.driver && allowed.has(source.key))
}

export function NodeLibrary({
    palette,
    triggers = [],
    triggerSources,
    hasTrigger = false,
    onAdd,
    onAddTrigger,
    onReplaceTrigger,
    onRequestClose,
    searchInputRef,
}: NodeLibraryProps) {
    const [query, setQuery] = useState('')
    const [replacement, setReplacement] = useState<TriggerNodeTypePayload | null>(null)
    const searchId = `node-library-search-${useId().replace(/:/g, '')}`
    const replacementOpener = useRef<HTMLButtonElement | null>(null)
    const attachSearchInputRef = useCallback(
        (instance: HTMLInputElement | null) => attachRef(searchInputRef, instance),
        [searchInputRef],
    )
    const terms = normalizeSearch(query).split(/\s+/).filter(Boolean)
    const matches = (definition: GraphComponentPayload) => {
        const haystack = searchableText(definition)
        return terms.every((term) => haystack.includes(term))
    }
    const triggerDefinitions = triggers.filter(matches)
    const definitions = filterNodeDefinitions(palette, query)
    const groups = new Map<string, { label: string; definitions: GraphComponentPayload[] }>()

    if (triggerDefinitions.length > 0) {
        groups.set('triggers', { label: 'Triggers', definitions: triggerDefinitions })
    }

    for (const definition of definitions) {
        const label = groupLabel(definition)
        const key = `executable:${normalizeSort(label)}`
        const group = groups.get(key)
        if (group === undefined) {
            groups.set(key, { label, definitions: [definition] })
        } else {
            group.definitions.push(definition)
        }
    }

    const resultCount = definitions.length + triggerDefinitions.length
    const resultLabel = `${resultCount} node type${resultCount === 1 ? '' : 's'} found`

    function startDrag(event: DragEvent<HTMLButtonElement>, definition: GraphComponentPayload): void {
        event.dataTransfer.effectAllowed = 'copy'
        event.dataTransfer.setData('application/x-nodeflow-node-type', definition.type)
    }

    function closeReplacement(): void {
        setReplacement(null)
    }

    function choose(definition: GraphComponentPayload, opener: HTMLButtonElement): void {
        if (definition.kind === 'executable') {
            onAdd(definition)
            return
        }
        if (!hasCompatibleSource(definition, triggerSources)) return
        if (hasTrigger) {
            replacementOpener.current = opener
            setReplacement(definition)
            return
        }
        onAddTrigger?.(definition)
    }

    return (
        <aside aria-label="Node Library" className="flex min-h-0 w-full flex-col gap-4 rounded-md border border-border bg-card p-4 text-card-foreground sm:max-w-sm">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-semibold">Node Library</h2>
                {onRequestClose !== undefined && (
                    <button
                        type="button"
                        aria-label="Close node library"
                        title="Close node library"
                        onClick={onRequestClose}
                        className="rounded p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <NodeflowIcon name="close" className="size-4" />
                    </button>
                )}
            </div>

            <div className="space-y-1.5">
                <label htmlFor={searchId} className="text-sm font-medium">Search nodes</label>
                <div className="relative">
                    <NodeflowIcon name="search" className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <input
                        ref={attachSearchInputRef}
                        id={searchId}
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search node types"
                        className="w-full rounded-md border border-input bg-background py-2 pl-8 pr-3 text-sm outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </div>
                <p aria-live="polite" className="text-xs text-muted-foreground">{resultLabel}</p>
            </div>

            {palette.length + triggers.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No node types are registered. Register definitions with <code>Nodeflow::register([...])</code>.
                </p>
            ) : resultCount === 0 ? (
                <p className="text-sm text-muted-foreground">No nodes match “{query.trim()}”.</p>
            ) : (
                <div className="min-h-0 space-y-4 overflow-y-auto">
                    {[...groups.entries()].map(([groupKey, group]) => {
                        const presentation = categoryPresentation(group.label)
                        return (
                            <section key={groupKey} className="space-y-2" aria-label={group.label}>
                                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">{group.label}</h3>
                                <div className="space-y-1.5">
                                    {group.definitions.map((definition) => {
                                        const unavailable = definition.kind === 'trigger' && !hasCompatibleSource(definition, triggerSources)
                                        const disabled = unavailable || (definition.kind === 'trigger' && onAddTrigger === undefined)
                                        return (
                                            <button
                                                key={definition.type}
                                                type="button"
                                                draggable={!disabled && !(definition.kind === 'trigger' && hasTrigger)}
                                                disabled={disabled}
                                                aria-label={`Add ${definition.label}`}
                                                title={unavailable ? 'No compatible trigger source is registered.' : definition.description ?? undefined}
                                                onClick={(event) => choose(definition, event.currentTarget)}
                                                onDragStart={(event) => startDrag(event, definition)}
                                                className="flex w-full items-start gap-3 rounded-md border border-border bg-background p-3 text-left transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <span className={`mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded ${categoryClasses[presentation.accent]}`} aria-hidden="true">
                                                    {definition.icon ? <span className="text-sm leading-none">{definition.icon}</span> : <NodeflowIcon name={presentation.icon} className="size-4" />}
                                                </span>
                                                <span className="min-w-0 space-y-0.5">
                                                    <span className="block truncate text-sm font-medium">{definition.label}</span>
                                                    <span className="block text-xs leading-5 text-muted-foreground">{conciseDescription(definition.description)}</span>
                                                    <span className="block truncate font-mono text-[11px] text-muted-foreground">{definition.type}</span>
                                                    {unavailable && <span className="block text-[11px] font-medium text-destructive">No compatible trigger source is registered.</span>}
                                                </span>
                                            </button>
                                        )
                                    })}
                                </div>
                            </section>
                        )
                    })}
                </div>
            )}
            <ConfirmationDialog
                open={replacement !== null}
                title="Replace trigger"
                description="Replacing the existing trigger resets its configuration. Its single connected target is preserved when possible."
                confirmLabel="Replace trigger"
                openerRef={replacementOpener}
                onCancel={closeReplacement}
                onConfirm={() => {
                    const selected = replacement
                    closeReplacement()
                    if (selected !== null) onReplaceTrigger?.(selected)
                }}
            />
        </aside>
    )
}
