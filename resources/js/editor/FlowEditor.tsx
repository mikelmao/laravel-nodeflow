import { useEffect, useLayoutEffect, useRef } from 'react'
import { Canvas } from '../canvas/Canvas'
import type { NodeRendererMap } from '../canvas/context'
import type { ControlMap } from '../controls/types'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import { FactCataloguesProvider, type FactsConfig } from '../facts/FactCataloguesContext'
import type {
    EditorUrls,
    FlowSummary,
    Graph,
    NodeTypePayload,
    TriggerNodeTypePayload,
    TriggerSourcesPayload,
    WebhookMetadata,
} from '../graph/types'
import { CanvasHud } from './CanvasHud'
import { EditorNotices } from './EditorNotices'
import { EditorShell, type EditorMode } from './EditorShell'
import { EditorToolbar } from './EditorToolbar'
import { FlowOverview } from './FlowOverview'
import { NodeInspector } from './NodeInspector'
import { NodeLibrary } from './NodeLibrary'
import { useEditorController, type ToolbarSlots } from './useEditorController'

type ShortcutEntry = { token: symbol; root: HTMLElement }
type ShortcutRegistry = { active: symbol | null; entries: ShortcutEntry[] }
const shortcutRegistries = new WeakMap<Document, ShortcutRegistry>()
const useShortcutLayoutEffect = typeof window === 'undefined' ? useEffect : useLayoutEffect

function shortcutRegistry(document: Document): ShortcutRegistry {
    let registry = shortcutRegistries.get(document)
    if (registry === undefined) {
        registry = { active: null, entries: [] }
        shortcutRegistries.set(document, registry)
    }
    return registry
}

function claimShortcut(document: Document, token: symbol): void {
    const registry = shortcutRegistry(document)
    registry.active = token
    const entry = registry.entries.find((candidate) => candidate.token === token)
    if (entry !== undefined) registry.entries = [...registry.entries.filter((candidate) => candidate !== entry), entry]
}

function removeShortcut(document: Document, token: symbol, root: HTMLElement): void {
    const registry = shortcutRegistry(document)
    const wasActive = registry.active === token
    const hadFocus = root.contains(document.activeElement)
    registry.entries = registry.entries.filter((entry) => entry.token !== token)
    if (wasActive) {
        const fallback = registry.entries.at(-1)
        registry.active = fallback?.token ?? null
        if (hadFocus) fallback?.root.focus({ preventScroll: true })
    }
    if (registry.entries.length === 0) shortcutRegistries.delete(document)
}

export type { EditorMode, ToolbarSlots }

export type FlowEditorProps = {
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    trigger_nodes: TriggerNodeTypePayload[]
    trigger_sources: TriggerSourcesPayload
    webhook: WebhookMetadata | null
    urls: EditorUrls
    controls?: ControlMap
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
    className?: string
    mode?: EditorMode
    toolbarSlots?: ToolbarSlots
    facts?: FactsConfig
}

function sessionKey({ flow, urls }: FlowEditorProps): string {
    return JSON.stringify([flow.id, urls.draft, urls.publish, flow.draft_revision, flow.version])
}

/** The public session boundary remounts all request and history refs when server identity changes. */
export function FlowEditor(props: FlowEditorProps) {
    return <FlowEditorSession key={sessionKey(props)} {...props} />
}

function editableTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false
    return target.matches('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [data-nodeflow-shortcuts="off"]')
        || target.closest('[contenteditable]:not([contenteditable="false"]), [data-nodeflow-shortcuts="off"]') !== null
}

function interactiveTarget(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) return false
    if (target.closest('.react-flow__node, .react-flow__pane') !== null) return false
    return target.closest('button, a, input, textarea, select, [contenteditable]:not([contenteditable="false"]), [role="button"], [tabindex]:not([tabindex="-1"])') !== null
}

function FlowEditorSession({ mode = 'workspace', toolbarSlots, className, facts, ...options }: FlowEditorProps) {
    const controller = useEditorController(options)
    const librarySearchRef = useRef<HTMLInputElement>(null)
    const shortcutToken = useRef(Symbol('nodeflow-shortcuts'))
    const rootRef = useRef<HTMLDivElement>(null)
    const claimShortcuts = (event?: { type: string; target: EventTarget | null }) => {
        const root = rootRef.current
        if (root === null) return
        claimShortcut(root.ownerDocument, shortcutToken.current)
        if ((event?.type === 'pointerdown' || event?.type === 'click') && !interactiveTarget(event.target) && !editableTarget(event.target)) {
            root.focus({ preventScroll: true })
        }
    }

    useShortcutLayoutEffect(() => {
        const root = rootRef.current
        if (root === null) return
        const registry = shortcutRegistry(root.ownerDocument)
        registry.entries.push({ token: shortcutToken.current, root })
        if (registry.active === null) registry.active = shortcutToken.current
        return () => {
            removeShortcut(root.ownerDocument, shortcutToken.current, root)
        }
    }, [])

    const openLibraryAndFocus = () => {
        controller.actions.setInspectorOpen(false)
        controller.actions.setLibraryOpen(true)
        requestAnimationFrame(() => librarySearchRef.current?.focus())
    }

    useEffect(() => {
        if (!controller.view.libraryOpen) return
        const frame = requestAnimationFrame(() => librarySearchRef.current?.focus())
        return () => cancelAnimationFrame(frame)
    }, [controller.view.libraryOpen])

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            const root = rootRef.current
            if (root === null) return
            const registry = shortcutRegistry(root.ownerDocument)
            const targetInside = event.target instanceof Node && root.contains(event.target)
            if (registry.active !== shortcutToken.current || (!targetInside && !root.contains(root.ownerDocument.activeElement))) return
            if (editableTarget(event.target)) return
            const command = !event.altKey && (event.metaKey || event.ctrlKey)
            const plain = !event.metaKey && !event.ctrlKey && !event.altKey
            if (command && event.key.toLowerCase() === 'z') {
                event.preventDefault()
                if (event.shiftKey) controller.actions.redo()
                else controller.actions.undo()
            } else if (command && event.key.toLowerCase() === 'k') {
                event.preventDefault()
                openLibraryAndFocus()
            } else if (plain && event.shiftKey && event.key.toLowerCase() === 'l') {
                event.preventDefault()
                controller.actions.autoLayout()
            } else if (plain && !event.shiftKey && event.key.toLowerCase() === 'f') {
                event.preventDefault()
                controller.toolbarProps.onFit()
            } else if ((event.key === 'Delete' || event.key === 'Backspace') && (controller.selected !== undefined || controller.view.selectedEdgeId !== null)) {
                event.preventDefault()
                controller.actions.deleteSelection()
            }
        }
        const document = rootRef.current?.ownerDocument ?? globalThis.document
        document.addEventListener('keydown', onKeyDown)
        return () => document.removeEventListener('keydown', onKeyDown)
    }, [controller.actions, controller.selected, controller.toolbarProps, controller.view.selectedEdgeId])

    const triggerTypes = new Set(options.trigger_nodes.map((definition) => definition.type))
    const hasTrigger = controller.document.nodes.some((node) => triggerTypes.has(node.data.type))
    const library = <NodeLibrary
        palette={options.palette}
        triggers={options.trigger_nodes}
        triggerSources={options.trigger_sources}
        hasTrigger={hasTrigger}
        onAdd={controller.actions.addAtViewportCenter}
        onAddTrigger={controller.actions.addTrigger}
        onReplaceTrigger={controller.actions.replaceTrigger}
        onRequestClose={() => controller.actions.setLibraryOpen(false)}
        searchInputRef={librarySearchRef}
    />
    const canvas = <>
        <Canvas {...controller.canvasProps} showMinimap />
        <CanvasHud {...controller.canvasHudProps} />
        {controller.document.nodes.length === 0 && <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center"><button type="button" onClick={openLibraryAndFocus} className="pointer-events-auto rounded-md border border-border bg-background px-4 py-2 shadow-sm">Add a node</button></div>}
    </>
    const inspector = controller.nodeInspectorProps === null
        ? <FlowOverview {...controller.flowOverviewProps} />
        : <NodeInspector {...controller.nodeInspectorProps} />

    return <FactCataloguesProvider config={facts}><FieldOptionsContext.Provider value={controller.optionsSource}>
        <div ref={rootRef} tabIndex={-1} className="contents" onPointerDownCapture={claimShortcuts} onClickCapture={claimShortcuts} onFocusCapture={claimShortcuts}>
            <p className="sr-only">Start: {controller.document.startId || 'none'}</p>
            <EditorShell
            mode={mode}
            className={className}
            toolbar={<EditorToolbar {...controller.toolbarProps} slots={toolbarSlots} />}
            notices={<EditorNotices {...controller.noticeProps} />}
            library={library}
            canvas={canvas}
            inspector={inspector}
            libraryOpen={controller.view.libraryOpen}
            inspectorOpen={controller.view.inspectorOpen}
            onLibraryOpenChange={controller.actions.setLibraryOpen}
            onInspectorOpenChange={controller.actions.setInspectorOpen}
            />
        </div>
    </FieldOptionsContext.Provider></FactCataloguesProvider>
}
