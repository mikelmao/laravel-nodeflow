import {
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    type Connection,
    type EdgeChange,
    type NodeChange,
} from '@xyflow/react'
import { useCallback, useMemo, useRef, useState } from 'react'
import type { CanvasActions, CanvasProps, NodeflowEdge, NodeflowNode } from '../canvas/Canvas'
import { CANVAS_ORIGIN, NODE_MIN_HEIGHT, NODE_WIDTH, ROW_GAP } from '../canvas/layout'
import type { NodeRendererMap } from '../canvas/context'
import { mergeControls, type ControlMap } from '../controls'
import { toCanvas } from '../graph/toCanvas'
import { hierarchicalLayout } from '../graph/layout'
import { defsByType, toGraph } from '../graph/toGraph'
import type { EditorUrls, FlowSummary, Graph, NodeErrorEntry, NodeTypePayload, TriggerPayload } from '../graph/types'
import { send } from '../http'
import { canConnect, nextNodeId } from './ids'
import { closeTransaction, commitHistory, createHistory, redoHistory, resetHistory, undoHistory, type History } from './history'
import { interpretPublish, type PublishOutcome } from './publish'
import { useAutosave } from './useAutosave'
import { interpretValidation, type ValidationOutcome } from './validation'
import type { CanvasHudProps } from './CanvasHud'
import type { EditorNoticesProps } from './EditorNotices'
import type { EditorToolbarProps, PublishIndicator, ValidationIndicator } from './EditorToolbar'
import type { FlowOverviewIssue, FlowOverviewProps } from './FlowOverview'
import type { NodeInspectorProps } from './NodeInspector'

export type EditorDocument = { nodes: NodeflowNode[]; edges: NodeflowEdge[]; startId: string }
export type EditorSelection = { nodeId: string | null; edgeId: string | null }
export type EditorView = { libraryOpen: boolean; inspectorOpen: boolean; selectedEdgeId: string | null }
export type ToolbarSlots = NonNullable<EditorToolbarProps['slots']>

export type EditorActions = {
    addNode: (definition: NodeTypePayload, point?: { x: number; y: number }) => void
    addAtViewportCenter: (definition: NodeTypePayload) => void
    nodesChange: (changes: NodeChange<NodeflowNode>[]) => void
    edgesChange: (changes: EdgeChange<NodeflowEdge>[]) => void
    connect: (connection: Connection) => void
    selectNode: (id: string | null) => void
    selectEdge: (id: string | null) => void
    configure: (id: string, key: string, value: unknown) => void
    closeConfigTransaction: () => void
    makeStart: (id: string) => void
    deleteNode: (id: string) => void
    deleteSelection: () => void
    undo: () => void
    redo: () => void
    autoLayout: () => void
    validate: () => Promise<void>
    publish: () => Promise<void>
    resolveConflict: (choice: 'mine' | 'theirs') => void
    registerCanvas: (actions: CanvasActions) => void
    focusIssue: (node: string | null, field: string | null) => void
    setLibraryOpen: (open: boolean) => void
    setInspectorOpen: (open: boolean) => void
}

export type UseEditorControllerOptions = {
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    triggers: TriggerPayload[]
    urls: EditorUrls
    controls?: ControlMap
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
}

export type UseEditorControllerResult = {
    document: EditorDocument
    selected: NodeflowNode | undefined
    view: EditorView
    actions: EditorActions
    optionsSource: { template: string; cache: Map<string, Record<string, string>> }
    canvasProps: CanvasProps & { deleteKeyCode: null }
    canvasHudProps: CanvasHudProps
    toolbarProps: Omit<EditorToolbarProps, 'slots'>
    noticeProps: EditorNoticesProps
    flowOverviewProps: FlowOverviewProps
    nodeInspectorProps: NodeInspectorProps | null
}

function copiedConfig(definition: NodeTypePayload): Record<string, unknown> {
    return Array.isArray(definition.default_config) ? {} : { ...definition.default_config }
}

function sameDocument(left: EditorDocument, right: EditorDocument): boolean {
    return JSON.stringify(left) === JSON.stringify(right)
}

function defaultDocument(graph: Graph): EditorDocument {
    const canvas = toCanvas(graph)
    return { nodes: canvas.nodes as NodeflowNode[], edges: canvas.edges as NodeflowEdge[], startId: graph.start ?? '' }
}

function overlap(left: { x: number; y: number }, right: { x: number; y: number }): boolean {
    return Math.abs(left.x - right.x) < NODE_WIDTH && Math.abs(left.y - right.y) < NODE_MIN_HEIGHT
}

function availablePoint(point: { x: number; y: number }, nodes: NodeflowNode[]): { x: number; y: number } {
    let next = point
    while (nodes.some((node) => overlap(node.position, next))) {
        next = { x: next.x, y: next.y + NODE_MIN_HEIGHT + ROW_GAP }
    }
    return next
}

function graphIssues(outcome: ValidationOutcome | PublishOutcome | null): FlowOverviewIssue[] {
    if (outcome?.kind !== 'invalid' && outcome?.kind !== 'semantic') return []
    const entries = Object.values(outcome.byNode).flat()
    return [
        ...entries.map((entry) => ({ message: entry.message, node: entry.node, field: entry.field, placeable: entry.node !== null })),
        ...outcome.unplaceable.map((message) => ({ message, node: null, field: null, placeable: false })),
    ]
}

function outcomeMessages(outcome: ValidationOutcome | PublishOutcome | null): { structural?: string; graph: string[]; failed?: string } {
    if (outcome?.kind === 'structural') return { structural: 'The editor sent a graph the server could not read. This is a client bug.', graph: outcome.developer }
    if (outcome?.kind === 'invalid') return { graph: [...outcome.errors, ...outcome.unplaceable] }
    if (outcome?.kind === 'semantic') return { graph: [...outcome.banner, ...outcome.unplaceable] }
    if (outcome?.kind === 'failed') return { graph: [], failed: outcome.message }
    return { graph: [] }
}

/** Controller boundary: graph history is canonical; panels, selection and requests are deliberately not undoable. */
export function useEditorController(options: UseEditorControllerOptions): UseEditorControllerResult {
    const initial = useMemo(() => defaultDocument(options.graph), [options.graph])
    const [history, setHistory] = useState<History<EditorDocument>>(() => createHistory(initial))
    const historyRef = useRef(history)
    historyRef.current = history
    const [selected, setSelected] = useState<EditorSelection>({ nodeId: null, edgeId: null })
    const [view, setView] = useState<EditorView>({ libraryOpen: true, inspectorOpen: false, selectedEdgeId: null })
    const [validation, setValidation] = useState<ValidationOutcome | null>(null)
    const [publishOutcome, setPublishOutcome] = useState<PublishOutcome | null>(null)
    const [validationState, setValidationState] = useState<ValidationIndicator>({ status: 'unchecked' })
    const [publishing, setPublishing] = useState(false)
    const [publishedVersion, setPublishedVersion] = useState<number | null>(options.flow.version)
    const [issueToFocus, setIssueToFocus] = useState<NodeErrorEntry | null>(null)
    const document = history.present
    const documentRef = useRef(document)
    documentRef.current = document
    const generation = useRef(0)
    const validationSequence = useRef(0)
    const publishSequence = useRef(0)
    const activePublish = useRef<number | null>(null)
    const canvas = useRef<CanvasActions | null>(null)
    const optionsCache = useRef(new Map<string, Record<string, string>>())
    const defs = useMemo(() => defsByType(options.palette), [options.palette])
    const controls = useMemo(() => mergeControls(options.controls), [options.controls])
    const optionsSource = useMemo(() => ({ template: options.urls.options, cache: optionsCache.current }), [options.urls.options])
    const built = useMemo(() => toGraph(document, document.startId, defs), [document, defs])
    const builtRef = useRef(built)
    builtRef.current = built
    const autosave = useAutosave({ url: options.urls.draft, initialRevision: options.flow.draft_revision, graph: built.graph, debounceMs: options.autosaveDebounceMs })

    const clearValidation = useCallback(() => {
        validationSequence.current += 1
        setValidation(null)
        setValidationState({ status: 'unchecked' })
        setIssueToFocus(null)
    }, [])

    const commit = useCallback((next: EditorDocument, transaction: string | null = null) => {
        const current = historyRef.current
        if (sameDocument(current.present, next)) return false
        const nextHistory = commitHistory(current, next, transaction)
        historyRef.current = nextHistory
        documentRef.current = next
        setHistory(nextHistory)
        generation.current += 1
        clearValidation()
        setPublishOutcome(null)
        return true
    }, [clearValidation])

    const closeConfigTransaction = useCallback(() => {
        const next = closeTransaction(historyRef.current)
        historyRef.current = next
        setHistory(next)
    }, [])

    const addNode = useCallback((definition: NodeTypePayload, point?: { x: number; y: number }) => {
        const current = documentRef.current
        const id = nextNodeId(definition.type, new Set(current.nodes.map((node) => node.id)))
        const topology = hierarchicalLayout([...current.nodes.map((node) => node.id), id], current.edges.map((edge) => ({ from: edge.source, to: edge.target })), current.startId || id)
        const position = availablePoint(point ?? topology[id] ?? CANVAS_ORIGIN, current.nodes)
        const next: NodeflowNode = { id, type: 'nodeflowNode', position, data: { id, type: definition.type, config: copiedConfig(definition), isStart: current.nodes.length === 0 } }
        if (commit({ nodes: [...current.nodes, next], edges: current.edges, startId: current.nodes.length === 0 ? id : current.startId })) {
            setSelected({ nodeId: id, edgeId: null })
            setView((currentView) => ({ ...currentView, inspectorOpen: true }))
        }
    }, [commit])

    const addAtViewportCenter = useCallback((definition: NodeTypePayload) => {
        const center = typeof window === 'undefined'
            ? undefined
            : { x: Math.max(0, window.innerWidth || globalThis.document.documentElement?.clientWidth || 0) / 2, y: Math.max(0, window.innerHeight || globalThis.document.documentElement?.clientHeight || 0) / 2 }
        addNode(definition, center === undefined || canvas.current === null ? undefined : canvas.current.screenToFlowPosition(center))
    }, [addNode])

    const selectNode = useCallback((id: string | null) => {
        setSelected({ nodeId: id, edgeId: null })
        setIssueToFocus(null)
        setView((current) => ({ ...current, inspectorOpen: id !== null, selectedEdgeId: null }))
    }, [])
    const selectEdge = useCallback((id: string | null) => {
        setSelected({ nodeId: null, edgeId: id })
        setIssueToFocus(null)
        setView((current) => ({ ...current, inspectorOpen: false, selectedEdgeId: id }))
    }, [])

    const deleteNode = useCallback((id: string) => {
        const current = documentRef.current
        const nextNodes = current.nodes.filter((node) => node.id !== id)
        if (nextNodes.length === current.nodes.length) return
        commit({ nodes: nextNodes, edges: current.edges.filter((edge) => edge.source !== id && edge.target !== id), startId: current.startId === id ? '' : current.startId })
        setSelected((selection) => selection.nodeId === id ? { nodeId: null, edgeId: null } : selection)
        setView((current) => ({ ...current, inspectorOpen: false, selectedEdgeId: null }))
    }, [commit])

    const nodesChange = useCallback((changes: NodeChange<NodeflowNode>[]) => {
        const removed = new Set(changes.filter((change) => change.type === 'remove').map((change) => change.id))
        const selectedChange = changes.find((change) => change.type === 'select' && change.selected)
        const selectedNode = selectedChange !== undefined && 'id' in selectedChange ? selectedChange.id : undefined
        if (selectedNode !== undefined) selectNode(selectedNode)
        if (changes.every((change) => change.type === 'select' || change.type === 'dimensions')) return
        const current = documentRef.current
        const nodes = applyNodeChanges(changes, current.nodes)
        const position = changes.find((change) => change.type === 'position')
        const transaction = position?.type === 'position' ? `move:${position.id}` : null
        commit({ nodes, edges: removed.size === 0 ? current.edges : current.edges.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)), startId: removed.has(current.startId) ? '' : current.startId }, transaction)
        if (position?.type === 'position' && position.dragging === false) closeConfigTransaction()
        if (removed.size > 0) setSelected((selection) => removed.has(selection.nodeId ?? '') ? { nodeId: null, edgeId: null } : selection)
    }, [closeConfigTransaction, commit, selectNode])

    const edgesChange = useCallback((changes: EdgeChange<NodeflowEdge>[]) => {
        const selectedChange = changes.find((change) => change.type === 'select' && change.selected)
        const selectedEdge = selectedChange !== undefined && 'id' in selectedChange ? selectedChange.id : undefined
        if (selectedEdge !== undefined) selectEdge(selectedEdge)
        if (changes.every((change) => change.type === 'select')) return
        const current = documentRef.current
        commit({ ...current, edges: applyEdgeChanges(changes, current.edges) })
        if (changes.some((change) => change.type === 'remove')) setSelected((selection) => ({ ...selection, edgeId: null }))
    }, [commit, selectEdge])

    const connect = useCallback((connection: Connection) => {
        if (connection.source === null || connection.target === null || connection.sourceHandle === null) return
        const current = documentRef.current
        const source = current.nodes.find((node) => node.id === connection.source)
        if (source === undefined || !current.nodes.some((node) => node.id === connection.target) || !canConnect(source.data.type, connection.sourceHandle, defs)) return
        if (current.edges.some((edge) => edge.source === connection.source && edge.target === connection.target && edge.sourceHandle === connection.sourceHandle)) return
        commit({ ...current, edges: addEdge<NodeflowEdge>({ ...connection, label: connection.sourceHandle }, current.edges) })
    }, [commit, defs])

    const configure = useCallback((id: string, key: string, value: unknown) => {
        const current = documentRef.current
        if (!current.nodes.some((node) => node.id === id)) return
        commit({ ...current, nodes: current.nodes.map((node) => node.id === id ? { ...node, data: { ...node.data, config: { ...node.data.config, [key]: value } } } : node) }, `config:${id}:${key}`)
    }, [commit])

    const makeStart = useCallback((id: string) => {
        const current = documentRef.current
        if (current.nodes.some((node) => node.id === id)) commit({ ...current, startId: id })
    }, [commit])

    const deleteSelection = useCallback(() => {
        const current = documentRef.current
        if (selected.nodeId !== null) {
            deleteNode(selected.nodeId)
        } else if (selected.edgeId !== null) {
            commit({ ...current, edges: current.edges.filter((edge) => edge.id !== selected.edgeId) })
            selectEdge(null)
        }
    }, [commit, deleteNode, selectEdge, selected])

    const moveHistory = useCallback((direction: 'undo' | 'redo') => {
        const next = direction === 'undo' ? undoHistory(historyRef.current) : redoHistory(historyRef.current)
        if (next === historyRef.current) return
        historyRef.current = next
        setHistory(next)
        generation.current += 1
        clearValidation()
        setPublishOutcome(null)
        setSelected((selection) => ({ nodeId: next.present.nodes.some((node) => node.id === selection.nodeId) ? selection.nodeId : null, edgeId: next.present.edges.some((edge) => edge.id === selection.edgeId) ? selection.edgeId : null }))
    }, [clearValidation])

    const autoLayout = useCallback(() => {
        const current = documentRef.current
        const positions = hierarchicalLayout(current.nodes.map((node) => node.id), current.edges.map((edge) => ({ from: edge.source, to: edge.target })), current.startId)
        commit({ ...current, nodes: current.nodes.map((node) => ({ ...node, position: positions[node.id] ?? node.position })) })
    }, [commit])

    const resolveConflict = useCallback((choice: 'mine' | 'theirs') => {
        if (choice === 'mine') {
            autosave.resolveConflict('mine')
            return
        }
        const conflict = autosave.conflict
        if (conflict === null) return
        const next = defaultDocument(conflict.graph)
        const reset = resetHistory(next)
        historyRef.current = reset
        setHistory(reset)
        generation.current += 1
        clearValidation()
        setPublishOutcome(null)
        setSelected({ nodeId: null, edgeId: null })
        setView((current) => ({ ...current, inspectorOpen: false, selectedEdgeId: null }))
        autosave.resolveConflict('theirs', toGraph(next, next.startId, defs).graph)
    }, [autosave, clearValidation, defs])

    const validate = useCallback(async () => {
        const url = options.urls.validate
        if (url === undefined || url === '') {
            setValidation({ kind: 'failed', message: 'Validation is unavailable because this editor did not receive a validate URL.' })
            setValidationState({ status: 'failed' })
            return
        }
        const request = ++validationSequence.current
        const requestGeneration = generation.current
        setValidationState({ status: 'checking' })
        setValidation(null)
        try {
            const graph = builtRef.current.graph
            const result = await send('POST', url, { graph })
            if (request !== validationSequence.current || requestGeneration !== generation.current) return
            const next = interpretValidation(result, new Set(documentRef.current.nodes.map((node) => node.id)))
            setValidation(next)
            if (next.kind === 'valid') setValidationState({ status: next.warnings.length === 0 ? 'valid' : 'warning', count: next.warnings.length })
            else if (next.kind === 'invalid') setValidationState({ status: 'invalid', count: next.errors.length + next.unplaceable.length + Object.values(next.byNode).flat().length })
            else setValidationState({ status: 'failed' })
        } catch (reason: unknown) {
            if (request === validationSequence.current && requestGeneration === generation.current) {
                setValidation({ kind: 'failed', message: `Could not reach server to validate this flow: ${String(reason)}` })
                setValidationState({ status: 'failed' })
            }
        }
    }, [options.urls.validate])

    const publish = useCallback(async () => {
        if (activePublish.current !== null) return
        const currentBuilt = builtRef.current
        if (currentBuilt.unresolved.length > 0) {
            setPublishOutcome({ kind: 'failed', message: 'Choose which output each unresolved connection should use before publishing.' })
            return
        }
        const attempt = ++publishSequence.current
        const publishedGeneration = generation.current
        activePublish.current = attempt
        setPublishing(true)
        setPublishOutcome(null)
        const owns = () => activePublish.current === attempt
        const ready = await autosave.preparePublish()
        if (!ready) {
            if (owns() && publishedGeneration === generation.current) setPublishOutcome({ kind: 'failed', message: autosave.message ?? 'The draft could not be saved before publishing.' })
            if (owns()) { activePublish.current = null; setPublishing(false) }
            return
        }
        try {
            const result = await send('POST', options.urls.publish, { graph: currentBuilt.graph })
            const next = interpretPublish(result, new Set(documentRef.current.nodes.map((node) => node.id)))
            autosave.finishPublish(next.kind === 'published' ? next.revision : undefined)
            if (!owns()) return
            if (next.kind === 'published') setPublishedVersion(next.version)
            if (next.kind === 'published' || publishedGeneration === generation.current) setPublishOutcome(next)
        } catch (reason: unknown) {
            autosave.finishPublish()
            if (owns() && publishedGeneration === generation.current) setPublishOutcome({ kind: 'failed', message: `Could not reach server to publish this flow: ${String(reason)}` })
        } finally {
            if (owns()) { activePublish.current = null; setPublishing(false) }
        }
    }, [autosave, options.urls.publish])

    const focusIssue = useCallback((nodeId: string | null, field: string | null) => {
        if (nodeId === null || !documentRef.current.nodes.some((node) => node.id === nodeId)) return
        selectNode(nodeId)
        canvas.current?.centerNode(nodeId)
        const matching = graphIssues(validation ?? publishOutcome).find((issue) => issue.node === nodeId && issue.field === field)
        setIssueToFocus({ node: nodeId, field, message: matching?.message ?? 'This field needs attention.' })
    }, [publishOutcome, selectNode, validation])

    const selectedNode = selected.nodeId === null ? undefined : document.nodes.find((node) => node.id === selected.nodeId)
    const activeOutcome = validation ?? publishOutcome
    const fieldErrors = activeOutcome?.kind === 'invalid' || activeOutcome?.kind === 'semantic'
        ? selectedNode === undefined ? [] : activeOutcome.byNode[selectedNode.id] ?? []
        : []
    const nodeErrors = useMemo(() => activeOutcome?.kind === 'invalid' || activeOutcome?.kind === 'semantic'
        ? Object.fromEntries(Object.entries(activeOutcome.byNode).map(([id, entries]) => [id, entries.map((entry) => entry.field === null ? entry.message : `${entry.field}: ${entry.message}`)]))
        : {}, [activeOutcome])
    const canvasNodes = useMemo(() => document.nodes.map((node) => ({ ...node, selected: node.id === selected.nodeId, data: { ...node.data, isStart: node.id === document.startId } })), [document.nodes, document.startId, selected.nodeId])
    const unknownTypes = document.nodes.filter((node) => !Object.prototype.hasOwnProperty.call(defs, node.data.type)).map((node) => ({ nodeId: node.id, type: node.data.type }))
    const trigger = options.triggers.find((candidate) => candidate.type === options.flow.trigger_type) ?? null
    const message = outcomeMessages(activeOutcome)
    const save = { status: autosave.status, message: autosave.message ?? undefined } as EditorToolbarProps['save']
    const publishIndicator: PublishIndicator = publishOutcome?.kind === 'published'
        ? { status: 'published', version: publishOutcome.version }
        : publishing ? { status: 'publishing' }
        : publishOutcome?.kind === 'failed' ? { status: 'error', message: publishOutcome.message }
        : { status: 'idle' }

    const actions: EditorActions = useMemo(() => ({
        addNode, addAtViewportCenter, nodesChange, edgesChange, connect, selectNode, selectEdge, configure, closeConfigTransaction,
        makeStart, deleteNode, deleteSelection, undo: () => moveHistory('undo'), redo: () => moveHistory('redo'), autoLayout,
        validate, publish, resolveConflict, registerCanvas: (next) => { canvas.current = next }, focusIssue,
        setLibraryOpen: (open) => setView((current) => ({ ...current, libraryOpen: open })),
        setInspectorOpen: (open) => setView((current) => ({ ...current, inspectorOpen: open })),
    }), [addAtViewportCenter, addNode, autoLayout, closeConfigTransaction, configure, connect, deleteNode, deleteSelection, edgesChange, focusIssue, makeStart, moveHistory, nodesChange, publish, resolveConflict, selectEdge, selectNode, validate])

    return {
        document,
        selected: selectedNode,
        view,
        actions,
        optionsSource,
        canvasProps: { nodes: canvasNodes, edges: document.edges, defs, renderers: options.nodeRenderers, nodeErrors, onNodesChange: nodesChange, onEdgesChange: edgesChange, onConnect: connect, onNodeClick: selectNode, onEdgeClick: selectEdge, onPaneClick: () => { selectNode(null); setView((current) => ({ ...current, inspectorOpen: false })) }, onDropNodeType: (type, point) => { if (Object.prototype.hasOwnProperty.call(defs, type)) addNode(defs[type]!, point) }, onReady: (next) => { canvas.current = next }, interactive: true, deleteKeyCode: null },
        canvasHudProps: { nodeCount: document.nodes.length, connectionCount: document.edges.length, validation: validationState },
        toolbarProps: { flowName: options.flow.name, triggerLabel: trigger?.label ?? options.flow.trigger_type, publishedVersion, save, validation: validationState, publish: publishIndicator, canUndo: history.past.length > 0, canRedo: history.future.length > 0, hasSelection: selected.nodeId !== null || selected.edgeId !== null, onUndo: () => moveHistory('undo'), onRedo: () => moveHistory('redo'), onAutoLayout: autoLayout, onFit: () => canvas.current?.fit(), onDeleteSelected: deleteSelection, onValidate: () => { void validate() }, onPublish: () => { void publish() } },
        noticeProps: { save, publish: publishIndicator, validation: validationState, structuralError: message.structural, graphMessages: [...message.graph, ...(message.failed === undefined ? [] : [message.failed])], validationMessage: validation?.kind === 'failed' ? validation.message : undefined, onKeepMine: () => resolveConflict('mine'), onUseTheirs: () => resolveConflict('theirs') },
        flowOverviewProps: { flow: { name: options.flow.name }, trigger: trigger === null ? null : { label: trigger.label, type: trigger.type }, publishedVersion, nodeCount: document.nodes.length, connectionCount: document.edges.length, startNodeId: document.startId || null, validation: validationState, issues: graphIssues(activeOutcome), warnings: validation?.kind === 'valid' ? validation.warnings : validation?.kind === 'invalid' ? validation.warnings : [], errors: message.graph, unknownTypes, unresolvedOutputs: built.unresolved.map((edge) => ({ from: edge.source, to: edge.target })), onIssueSelect: (issue) => focusIssue(issue.node, issue.field) },
        nodeInspectorProps: selectedNode === undefined ? null : { node: selectedNode.data, def: defs[selectedNode.data.type], controls, errors: fieldErrors, isStart: selectedNode.id === document.startId, issueToFocus, onConfigChange: (key, value) => configure(selectedNode.id, key, value), onConfigBlur: closeConfigTransaction, onMakeStart: () => makeStart(selectedNode.id), onDelete: () => deleteNode(selectedNode.id) },
    }
}
