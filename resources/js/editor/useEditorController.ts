import {
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    type Connection,
    type EdgeChange,
    type NodeChange,
} from '@xyflow/react'
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import type { CanvasActions, CanvasProps, NodeflowEdge, NodeflowNode } from '../canvas/Canvas'
import { CANVAS_ORIGIN, NODE_MIN_HEIGHT, NODE_WIDTH, ROW_GAP } from '../canvas/layout'
import type { NodeRendererMap } from '../canvas/context'
import { mergeControls, type ControlMap } from '../controls'
import { toCanvas } from '../graph/toCanvas'
import { cloneGraphConfig, cloneJsonValue } from '../graph/json'
import { hierarchicalLayout } from '../graph/layout'
import { defsByType, toGraph } from '../graph/toGraph'
import type {
    EditorUrls,
    FlowSummary,
    Graph,
    GraphComponentPayload,
    NodeErrorEntry,
    NodeTypePayload,
    TriggerNodeTypePayload,
    TriggerSourcesPayload,
    WebhookMetadata,
} from '../graph/types'
import { send, webhookRotationResponse } from '../http'
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
    addTrigger: (definition: TriggerNodeTypePayload, point?: { x: number; y: number }) => void
    replaceTrigger: (definition: TriggerNodeTypePayload) => void
    nodesChange: (changes: NodeChange<NodeflowNode>[]) => void
    edgesChange: (changes: EdgeChange<NodeflowEdge>[]) => void
    connect: (connection: Connection) => void
    selectNode: (id: string | null) => void
    selectEdge: (id: string | null) => void
    configure: (id: string, key: string, value: unknown) => void
    configureTriggerSource: (id: string, source: string | null) => void
    closeConfigTransaction: () => void
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
    trigger_nodes: TriggerNodeTypePayload[]
    trigger_sources: TriggerSourcesPayload
    webhook: WebhookMetadata | null
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

function copiedConfig(definition: GraphComponentPayload): Record<string, unknown> {
    return cloneGraphConfig(definition.default_config)
}

function hasRegisteredTriggerSource(definition: TriggerNodeTypePayload, sources: TriggerSourcesPayload): boolean {
    const allowed = new Set(definition.compatible_source_keys)
    const registered = Object.prototype.hasOwnProperty.call(sources, definition.driver) ? sources[definition.driver] ?? [] : []
    return registered.some((source) => source.driver === definition.driver && allowed.has(source.key))
}

function copiedTriggerConfig(definition: TriggerNodeTypePayload, sources: TriggerSourcesPayload): Record<string, unknown> {
    const nodeConfig = copiedConfig(definition)
    const selectedKey = typeof nodeConfig.source === 'string' ? nodeConfig.source : null
    if (selectedKey === null) return nodeConfig
    const selected = (Object.prototype.hasOwnProperty.call(sources, definition.driver) ? sources[definition.driver] ?? [] : [])
        .find((source) => source.driver === definition.driver
            && definition.compatible_source_keys.includes(source.key)
            && source.key === selectedKey)
    if (selected === undefined) return nodeConfig

    const reserved = new Set(definition.fields.map((field) => field.key))
    const contributed = new Set(selected.fields.filter((field) => !reserved.has(field.key)).map((field) => field.key))
    const sourceDefaults = cloneGraphConfig(selected.default_config)
    for (const [key, value] of Object.entries(sourceDefaults)) {
        if (contributed.has(key) && !Object.prototype.hasOwnProperty.call(nodeConfig, key)) nodeConfig[key] = value
    }
    return nodeConfig
}

function snapshotDocument(document: EditorDocument): EditorDocument {
    return {
        nodes: document.nodes.map((node) => ({
            ...node,
            position: { ...node.position },
            data: { ...node.data, config: cloneGraphConfig(node.data.config) },
        })),
        edges: document.edges.map((edge) => ({ ...edge })),
        startId: document.startId,
    }
}

function sameDocument(left: EditorDocument, right: EditorDocument): boolean {
    return JSON.stringify(left) === JSON.stringify(right)
}

function defaultDocument(graph: Graph, defs: Record<string, GraphComponentPayload>): EditorDocument {
    const canvas = toCanvas(graph, defs)
    return { nodes: stripNodeSelection(canvas.nodes as NodeflowNode[]), edges: stripEdgeSelection(canvas.edges as NodeflowEdge[]), startId: graph.start ?? '' }
}

function stripNodeSelection(nodes: NodeflowNode[]): NodeflowNode[] {
    return nodes.map(({ selected: _selected, ...node }) => node)
}

function stripEdgeSelection(edges: NodeflowEdge[]): NodeflowEdge[] {
    return edges.map(({ selected: _selected, ...edge }) => edge)
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

function wouldCreateCycle(edges: NodeflowEdge[], source: string, target: string): boolean {
    if (source === target) return true
    const outgoing = new Map<string, string[]>()
    for (const edge of edges) {
        const targets = outgoing.get(edge.source) ?? []
        targets.push(edge.target)
        outgoing.set(edge.source, targets)
    }
    const pending = [target]
    const visited = new Set<string>()
    while (pending.length > 0) {
        const current = pending.pop()!
        if (current === source) return true
        if (visited.has(current)) continue
        visited.add(current)
        pending.push(...(outgoing.get(current) ?? []))
    }
    return false
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

type WebhookCredentialIdentity = {
    endpointUrl: string | null
    rotatedAt: string | null
    version: number | null
}

type WebhookSecretDisclosure = {
    secret: string
    credential: WebhookCredentialIdentity
}

type WebhookClientState = {
    metadata: WebhookMetadata | null
    disclosure: WebhookSecretDisclosure | null
}

type PendingWebhookMetadata = {
    credential: WebhookMetadata | null
    invalidated: boolean
    latestActive: boolean | null
}

type CredentialRelation = 'same' | 'older' | 'newer' | 'different' | 'unknown'

function parsedTimestamp(value: string | null): number | null {
    if (value === null) return null
    const parsed = Date.parse(value)
    return Number.isFinite(parsed) ? parsed : null
}

function credentialRelation(
    current: Pick<WebhookCredentialIdentity, 'endpointUrl' | 'rotatedAt'>,
    incoming: Pick<WebhookCredentialIdentity, 'endpointUrl' | 'rotatedAt'>,
): CredentialRelation {
    if (current.endpointUrl !== incoming.endpointUrl) return 'different'
    if (current.rotatedAt === null && incoming.rotatedAt === null) return 'same'
    const currentTime = parsedTimestamp(current.rotatedAt)
    const incomingTime = parsedTimestamp(incoming.rotatedAt)
    if (currentTime === null || incomingTime === null) return 'unknown'
    if (incomingTime < currentTime) return 'older'
    if (incomingTime > currentTime) return 'newer'
    return 'same'
}

function metadataIdentity(metadata: WebhookMetadata | null): Pick<WebhookCredentialIdentity, 'endpointUrl' | 'rotatedAt'> | null {
    return metadata === null ? null : { endpointUrl: metadata.endpoint_url, rotatedAt: metadata.secret_rotated_at }
}

function reconcileWebhookMetadata(current: WebhookClientState, incoming: WebhookMetadata | null): WebhookClientState {
    if (current.metadata === null || incoming === null) {
        if (current.metadata === null && incoming === null) return { metadata: null, disclosure: current.disclosure }
        return { metadata: incoming, disclosure: null }
    }
    const currentIdentity = metadataIdentity(current.metadata)
    const incomingIdentity = metadataIdentity(incoming)
    if (currentIdentity === null || incomingIdentity === null) return { metadata: incoming, disclosure: null }
    const relation = credentialRelation(currentIdentity, incomingIdentity)
    const disclosureMatchesCurrent = current.disclosure === null
        || credentialRelation(current.disclosure.credential, currentIdentity) === 'same'
    const disclosure = disclosureMatchesCurrent ? current.disclosure : null
    if (relation === 'older') {
        return {
            metadata: { ...incoming, secret_rotated_at: current.metadata.secret_rotated_at },
            disclosure,
        }
    }
    if (relation === 'same') return { metadata: incoming, disclosure }
    return { metadata: incoming, disclosure: null }
}

function coalescePendingWebhookMetadata(current: PendingWebhookMetadata | null, incoming: WebhookMetadata | null): PendingWebhookMetadata {
    const copied = incoming === null ? null : { ...incoming }
    if (current === null) {
        return {
            credential: copied,
            invalidated: copied === null || (copied.secret_rotated_at !== null && parsedTimestamp(copied.secret_rotated_at) === null),
            latestActive: copied?.active ?? null,
        }
    }
    if (current.credential === null || copied === null) {
        return {
            credential: current.credential ?? copied,
            invalidated: true,
            latestActive: current.credential === null ? copied?.active ?? current.latestActive : current.latestActive,
        }
    }
    const currentIdentity = metadataIdentity(current.credential)
    const incomingIdentity = metadataIdentity(copied)
    if (currentIdentity === null || incomingIdentity === null) return { ...current, invalidated: true }
    const relation = credentialRelation(currentIdentity, incomingIdentity)
    if (relation === 'newer') return { credential: copied, invalidated: current.invalidated, latestActive: copied.active }
    if (relation === 'same' || relation === 'older') return { ...current, latestActive: copied.active }
    if (relation === 'different') return { ...current, invalidated: true }

    const currentTime = parsedTimestamp(current.credential.secret_rotated_at)
    const incomingTime = parsedTimestamp(copied.secret_rotated_at)
    const preferIncoming = current.credential.endpoint_url === copied.endpoint_url && currentTime === null && incomingTime !== null
    return {
        credential: preferIncoming ? copied : current.credential,
        invalidated: true,
        latestActive: current.credential.endpoint_url === copied.endpoint_url ? copied.active : current.latestActive,
    }
}

function reconcilePendingWebhookMetadata(current: WebhookClientState, pending: PendingWebhookMetadata | null): WebhookClientState {
    if (pending === null) return current
    const reconciled = reconcileWebhookMetadata(current, pending.credential)
    let metadata = reconciled.metadata
    if (metadata !== null
        && pending.credential !== null
        && metadata.endpoint_url === pending.credential.endpoint_url
        && pending.latestActive !== null
    ) metadata = { ...metadata, active: pending.latestActive }
    return {
        metadata,
        disclosure: pending.invalidated ? null : reconciled.disclosure,
    }
}

/** Controller boundary: graph history is canonical; panels, selection and requests are deliberately not undoable. */
export function useEditorController(options: UseEditorControllerOptions): UseEditorControllerResult {
    const defs = useMemo(
        () => defsByType([...options.palette, ...options.trigger_nodes]),
        [options.palette, options.trigger_nodes],
    )
    const initial = useMemo(() => defaultDocument(options.graph, defs), [options.graph, defs])
    const [history, setHistory] = useState<History<EditorDocument>>(() => createHistory(initial))
    const historyRef = useRef(history)
    historyRef.current = history
    const [selected, setSelected] = useState<EditorSelection>({ nodeId: null, edgeId: null })
    const [view, setView] = useState<EditorView>({ libraryOpen: true, inspectorOpen: true, selectedEdgeId: null })
    const [validation, setValidation] = useState<ValidationOutcome | null>(null)
    const [publishOutcome, setPublishOutcome] = useState<PublishOutcome | null>(null)
    const [validationState, setValidationState] = useState<ValidationIndicator>({ status: 'unchecked' })
    const [publishing, setPublishing] = useState(false)
    const [publishedVersion, setPublishedVersion] = useState<number | null>(options.flow.version)
    const [webhookState, setWebhookState] = useState<WebhookClientState>({ metadata: options.webhook, disclosure: null })
    const webhookMetadata = webhookState.metadata
    const webhookSecret = webhookState.disclosure?.secret ?? null
    const [webhookRotating, setWebhookRotating] = useState(false)
    const [webhookRotationError, setWebhookRotationError] = useState<string | null>(null)
    const [issueToFocus, setIssueToFocus] = useState<NodeErrorEntry | null>(null)
    const document = history.present
    const documentRef = useRef(document)
    documentRef.current = document
    const generation = useRef(0)
    const validationSequence = useRef(0)
    const publishSequence = useRef(0)
    const credentialSequence = useRef(0)
    const committedCredentialSequence = useRef(0)
    const activePublish = useRef<number | null>(null)
    const activeRotation = useRef<number | null>(null)
    const activeCredentialOperation = useRef<{ kind: 'publish' | 'rotation'; attempt: number } | null>(null)
    const pendingWebhookMetadata = useRef<PendingWebhookMetadata | null>(null)
    const mounted = useRef(true)
    const validateUrl = useRef(options.urls.validate)
    const publishUrl = useRef(options.urls.publish)
    const rotationUrl = useRef(options.urls.rotate_webhook_secret)
    const canvas = useRef<CanvasActions | null>(null)
    const optionsCache = useRef(new Map<string, Record<string, string>>())
    const controls = useMemo(() => mergeControls(options.controls), [options.controls])
    const optionsSource = useMemo(() => ({ template: options.urls.options, cache: optionsCache.current }), [options.urls.options])
    const built = useMemo(() => toGraph(document, document.startId, defs), [document, defs])
    const builtRef = useRef(built)
    builtRef.current = built
    const autosave = useAutosave({ url: options.urls.draft, sessionIdentity: options.urls.publish, initialRevision: options.flow.draft_revision, graph: built.graph, debounceMs: options.autosaveDebounceMs })
    const applyPendingWebhookMetadata = useCallback(() => {
        const pending = pendingWebhookMetadata.current
        pendingWebhookMetadata.current = null
        if (pending !== null) setWebhookState((current) => reconcilePendingWebhookMetadata(current, pending))
    }, [])

    useEffect(() => {
        mounted.current = true
        return () => {
            mounted.current = false
            validationSequence.current += 1
            publishSequence.current += 1
            activePublish.current = null
            activeRotation.current = null
            activeCredentialOperation.current = null
            pendingWebhookMetadata.current = null
        }
    }, [])

    useEffect(() => {
        if (validateUrl.current === options.urls.validate) return
        validateUrl.current = options.urls.validate
        validationSequence.current += 1
        setValidation(null)
        setValidationState({ status: 'unchecked' })
        setIssueToFocus(null)
    }, [options.urls.validate])

    useEffect(() => {
        if (publishUrl.current === options.urls.publish) return
        publishUrl.current = options.urls.publish
        publishSequence.current += 1
        activePublish.current = null
        committedCredentialSequence.current = ++credentialSequence.current
        activeRotation.current = null
        activeCredentialOperation.current = null
        pendingWebhookMetadata.current = null
        setPublishing(false)
        setPublishOutcome(null)
        setWebhookRotating(false)
        setWebhookRotationError(null)
    }, [options.urls.publish])

    useEffect(() => {
        if (rotationUrl.current === options.urls.rotate_webhook_secret) return
        rotationUrl.current = options.urls.rotate_webhook_secret
        // A rotation endpoint can be refreshed independently. Invalidate only
        // the rotation that owned the previous URL; never release a publish's
        // shared credential barrier while its request is still in flight.
        if (activeCredentialOperation.current?.kind === 'rotation') {
            committedCredentialSequence.current = ++credentialSequence.current
            activeRotation.current = null
            activeCredentialOperation.current = null
            setWebhookRotating(false)
            setWebhookRotationError(null)
            applyPendingWebhookMetadata()
        }
    }, [applyPendingWebhookMetadata, options.urls.rotate_webhook_secret])

    useLayoutEffect(() => {
        const value = options.webhook === null ? null : { ...options.webhook }
        if (activeCredentialOperation.current !== null) {
            pendingWebhookMetadata.current = coalescePendingWebhookMetadata(pendingWebhookMetadata.current, value)
            return
        }
        pendingWebhookMetadata.current = null
        setWebhookState((current) => reconcileWebhookMetadata(current, value))
    }, [options.webhook])

    const clearValidation = useCallback(() => {
        validationSequence.current += 1
        setValidation(null)
        setValidationState({ status: 'unchecked' })
        setIssueToFocus(null)
    }, [])

    const commit = useCallback((next: EditorDocument, transaction: string | null = null) => {
        const current = historyRef.current
        if (sameDocument(current.present, next)) return false
        const snapshot = snapshotDocument(next)
        const nextHistory = commitHistory(current, snapshot, transaction)
        historyRef.current = nextHistory
        documentRef.current = snapshot
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
        if (definition.kind !== 'executable') return
        const current = documentRef.current
        const id = nextNodeId(definition.type, new Set(current.nodes.map((node) => node.id)))
        const topology = hierarchicalLayout([...current.nodes.map((node) => node.id), id], current.edges.map((edge) => ({ from: edge.source, to: edge.target })), current.startId || id)
        const position = availablePoint(point ?? topology[id] ?? CANVAS_ORIGIN, current.nodes)
        const next: NodeflowNode = { id, type: 'nodeflowNode', position, data: { id, type: definition.type, kind: 'executable', config: copiedConfig(definition), isStart: false } }
        if (commit({ nodes: [...current.nodes, next], edges: current.edges, startId: current.startId })) {
            setSelected({ nodeId: id, edgeId: null })
            setView((currentView) => ({ ...currentView, inspectorOpen: true }))
        }
    }, [commit])

    const addTrigger = useCallback((definition: TriggerNodeTypePayload, point?: { x: number; y: number }) => {
        if (definition.kind !== 'trigger' || !hasRegisteredTriggerSource(definition, options.trigger_sources)) return
        const current = documentRef.current
        if (current.nodes.some((node) => defs[node.data.type]?.kind === 'trigger')) return
        const id = nextNodeId(definition.type, new Set(current.nodes.map((node) => node.id)))
        const topology = hierarchicalLayout(
            [...current.nodes.map((node) => node.id), id],
            current.edges.map((edge) => ({ from: edge.source, to: edge.target })),
            id,
        )
        const position = availablePoint(point ?? topology[id] ?? CANVAS_ORIGIN, current.nodes)
        const next: NodeflowNode = {
            id,
            type: 'nodeflowNode',
            position,
            data: { id, type: definition.type, kind: 'trigger', config: copiedTriggerConfig(definition, options.trigger_sources), isStart: true },
        }
        if (commit({ nodes: [...current.nodes, next], edges: current.edges, startId: id })) {
            setSelected({ nodeId: id, edgeId: null })
            setView((currentView) => ({ ...currentView, inspectorOpen: true }))
        }
    }, [commit, defs, options.trigger_sources])

    const replaceTrigger = useCallback((definition: TriggerNodeTypePayload) => {
        if (definition.kind !== 'trigger' || !hasRegisteredTriggerSource(definition, options.trigger_sources)) return
        const current = documentRef.current
        const triggers = current.nodes.filter((node) => defs[node.data.type]?.kind === 'trigger')
        if (triggers.length === 0) {
            addTrigger(definition)
            return
        }

        const retained = triggers.find((node) => node.id === current.startId) ?? triggers[0]!
        const removedIds = new Set(triggers.filter((node) => node.id !== retained.id).map((node) => node.id))
        const remainingNodes = current.nodes
            .filter((node) => !removedIds.has(node.id))
            .map((node): NodeflowNode => node.id === retained.id
                ? {
                    ...node,
                    data: {
                        id: retained.id,
                        type: definition.type,
                        kind: 'trigger',
                        config: copiedTriggerConfig(definition, options.trigger_sources),
                        isStart: true,
                    },
                }
                : node)
        const outgoing = current.edges.filter((edge) => edge.source === retained.id)
        const preserved = outgoing.length === 1
            && remainingNodes.some((node) => node.id === outgoing[0]!.target && defs[node.data.type]?.kind === 'executable')
            ? { ...outgoing[0]!, sourceHandle: 'started', label: 'started' }
            : null
        const edges = current.edges.flatMap((edge) => {
            if (removedIds.has(edge.source) || removedIds.has(edge.target) || edge.target === retained.id) return []
            if (edge.source !== retained.id) return [edge]
            return preserved?.id === edge.id ? [preserved] : []
        })

        if (commit({ nodes: remainingNodes, edges, startId: retained.id })) {
            setSelected((selection) => {
                if (removedIds.has(selection.nodeId ?? '')) return { nodeId: retained.id, edgeId: null }
                return { nodeId: selection.nodeId, edgeId: null }
            })
            setView((currentView) => ({ ...currentView, selectedEdgeId: null }))
        }
    }, [addTrigger, commit, defs, options.trigger_sources])

    const addAtViewportCenter = useCallback((definition: NodeTypePayload) => {
        const actions = canvas.current
        if (actions?.viewportCenter !== undefined) {
            addNode(definition, actions.viewportCenter())
            return
        }
        if (actions !== null) {
            const center = typeof window === 'undefined'
                ? CANVAS_ORIGIN
                : { x: Math.max(0, window.innerWidth || globalThis.document.documentElement.clientWidth || 0) / 2, y: Math.max(0, window.innerHeight || globalThis.document.documentElement.clientHeight || 0) / 2 }
            addNode(definition, actions.screenToFlowPosition(center))
            return
        }
        addNode(definition)
    }, [addNode])

    const selectNode = useCallback((id: string | null) => {
        setSelected({ nodeId: id, edgeId: null })
        setIssueToFocus(null)
        setView((current) => ({ ...current, inspectorOpen: id === null ? current.inspectorOpen : true, selectedEdgeId: null }))
    }, [])
    const selectEdge = useCallback((id: string | null) => {
        setSelected({ nodeId: null, edgeId: id })
        setIssueToFocus(null)
        setView((current) => ({ ...current, selectedEdgeId: id }))
    }, [])

    const deleteNode = useCallback((id: string) => {
        const current = documentRef.current
        const removedNode = current.nodes.find((node) => node.id === id)
        const nextNodes = current.nodes.filter((node) => node.id !== id)
        if (nextNodes.length === current.nodes.length) return
        const removesTrigger = removedNode !== undefined && defs[removedNode.data.type]?.kind === 'trigger'
        commit({ nodes: nextNodes, edges: current.edges.filter((edge) => edge.source !== id && edge.target !== id), startId: removesTrigger || current.startId === id ? '' : current.startId })
        setSelected((selection) => selection.nodeId === id ? { nodeId: null, edgeId: null } : selection)
        setView((current) => ({ ...current, selectedEdgeId: null }))
    }, [commit, defs])

    const nodesChange = useCallback((changes: NodeChange<NodeflowNode>[]) => {
        const selectionChanges = changes.filter((change) => change.type === 'select')
        const graphChanges = changes.filter((change) => change.type !== 'select')
        const selectedNode = selectionChanges.filter((change) => change.selected).at(-1)
        if (selectedNode !== undefined) selectNode(selectedNode.id)
        else if (selectionChanges.some((change) => !change.selected)) {
            setSelected((current) => current.nodeId !== null && selectionChanges.some((change) => change.id === current.nodeId && !change.selected) ? { nodeId: null, edgeId: null } : current)
            setView((current) => ({ ...current, selectedEdgeId: null }))
        }
        // Topology creation/replacement belongs to addNode/addTrigger/
        // replaceTrigger/connect, where component and graph invariants are
        // enforced. React Flow change batches are only authoritative for
        // controlled-state updates such as movement, dimensions and removal.
        if (graphChanges.some((change) => change.type === 'add' || change.type === 'replace')) return
        const removed = new Set(graphChanges.filter((change) => change.type === 'remove').map((change) => change.id))
        if (graphChanges.every((change) => change.type === 'dimensions')) return
        const current = documentRef.current
        const nodes = stripNodeSelection(applyNodeChanges(graphChanges, current.nodes))
        const removesTrigger = current.nodes.some((node) => removed.has(node.id) && defs[node.data.type]?.kind === 'trigger')
        const position = graphChanges.find((change) => change.type === 'position')
        const transaction = position?.type === 'position' ? `move:${position.id}` : null
        commit({ nodes, edges: removed.size === 0 ? current.edges : current.edges.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)), startId: removesTrigger || removed.has(current.startId) ? '' : current.startId }, transaction)
        if (position?.type === 'position' && position.dragging === false) closeConfigTransaction()
        if (removed.size > 0) setSelected((selection) => removed.has(selection.nodeId ?? '') || current.edges.some((edge) => edge.id === selection.edgeId && (removed.has(edge.source) || removed.has(edge.target))) ? { nodeId: null, edgeId: null } : selection)
    }, [closeConfigTransaction, commit, defs, selectNode])

    const edgesChange = useCallback((changes: EdgeChange<NodeflowEdge>[]) => {
        const selectionChanges = changes.filter((change) => change.type === 'select')
        const graphChanges = changes.filter((change) => change.type !== 'select')
        const selectedEdge = selectionChanges.filter((change) => change.selected).at(-1)
        if (selectedEdge !== undefined) selectEdge(selectedEdge.id)
        else if (selectionChanges.some((change) => !change.selected)) {
            setSelected((current) => current.edgeId !== null && selectionChanges.some((change) => change.id === current.edgeId && !change.selected) ? { nodeId: null, edgeId: null } : current)
            setView((current) => ({ ...current, selectedEdgeId: null }))
        }
        // New and replacement connections must pass through connect(), which
        // owns handle, component-kind, cardinality and cycle validation.
        if (graphChanges.some((change) => change.type === 'add' || change.type === 'replace')) return
        if (graphChanges.length === 0) return
        const current = documentRef.current
        const edges = stripEdgeSelection(applyEdgeChanges(graphChanges, current.edges))
        commit({ ...current, edges })
        if (graphChanges.some((change) => change.type === 'remove')) {
            setSelected((selection) => ({ ...selection, edgeId: null }))
            setView((current) => ({ ...current, selectedEdgeId: null }))
        }
    }, [commit, selectEdge])

    const connect = useCallback((connection: Connection) => {
        if (connection.source === null || connection.target === null || connection.sourceHandle === null) return
        const current = documentRef.current
        const source = current.nodes.find((node) => node.id === connection.source)
        const target = current.nodes.find((node) => node.id === connection.target)
        const sourceKind = source === undefined ? undefined : defs[source.data.type]?.kind
        const targetKind = target === undefined ? undefined : defs[target.data.type]?.kind
        if (source === undefined || target === undefined || targetKind === 'trigger') return
        if (sourceKind === 'trigger' && (connection.sourceHandle !== 'started' || targetKind !== 'executable')) return
        if (!canConnect(source.data.type, connection.sourceHandle, defs)) return
        if (current.edges.some((edge) => edge.source === connection.source && edge.sourceHandle === connection.sourceHandle)) return
        if (wouldCreateCycle(current.edges, connection.source, connection.target)) return
        commit({ ...current, edges: addEdge<NodeflowEdge>({ ...connection, label: connection.sourceHandle }, current.edges) })
    }, [commit, defs])

    const configure = useCallback((id: string, key: string, value: unknown) => {
        const current = documentRef.current
        if (!current.nodes.some((node) => node.id === id)) return
        let copiedValue: ReturnType<typeof cloneJsonValue>
        try {
            copiedValue = cloneJsonValue(value)
        } catch {
            return
        }
        commit({ ...current, nodes: current.nodes.map((node) => node.id === id ? { ...node, data: { ...node.data, config: { ...node.data.config, [key]: copiedValue } } } : node) }, `config:${id}:${key}`)
    }, [commit])

    const configureTriggerSource = useCallback((id: string, source: string | null) => {
        const current = documentRef.current
        const target = current.nodes.find((node) => node.id === id)
        const definition = target === undefined ? undefined : defs[target.data.type]
        if (target === undefined || definition?.kind !== 'trigger') return
        const registered = Object.prototype.hasOwnProperty.call(options.trigger_sources, definition.driver)
            ? options.trigger_sources[definition.driver] ?? []
            : []
        const selectedSource = source === null ? undefined : registered.find((candidate) =>
            candidate.driver === definition.driver
            && candidate.key === source
            && definition.compatible_source_keys.includes(candidate.key),
        )
        let currentConfig: Record<string, unknown>
        let sourceDefaults: Record<string, unknown>
        try {
            currentConfig = cloneGraphConfig(target.data.config)
            sourceDefaults = cloneGraphConfig(selectedSource?.default_config ?? {})
        } catch {
            return
        }
        const nodeFieldKeys = new Set(definition.fields.map((field) => field.key))
        const sourceFieldKeys = new Set(selectedSource?.fields
            .filter((field) => !nodeFieldKeys.has(field.key))
            .map((field) => field.key) ?? [])
        const config: Record<string, unknown> = {}
        for (const key of nodeFieldKeys) {
            if (key === 'source' || !Object.prototype.hasOwnProperty.call(currentConfig, key)) continue
            config[key] = currentConfig[key]
        }
        config.source = source
        for (const key of sourceFieldKeys) {
            if (Object.prototype.hasOwnProperty.call(sourceDefaults, key)) config[key] = sourceDefaults[key]
        }
        commit({
            ...current,
            nodes: current.nodes.map((node) => node.id === id ? { ...node, data: { ...node.data, config } } : node),
        }, `config:${id}:source`)
    }, [commit, defs, options.trigger_sources])

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
        const next = defaultDocument(conflict.graph, defs)
        const reset = resetHistory(next)
        historyRef.current = reset
        setHistory(reset)
        generation.current += 1
        clearValidation()
        setPublishOutcome(null)
        setSelected({ nodeId: null, edgeId: null })
        setView((current) => ({ ...current, selectedEdgeId: null }))
        autosave.resolveConflict('theirs', toGraph(next, next.startId, defs).graph)
    }, [autosave, clearValidation, defs])

    const validate = useCallback(async () => {
        const request = ++validationSequence.current
        const requestGeneration = generation.current
        const url = options.urls.validate
        if (url === undefined || url === '') {
            if (!mounted.current || request !== validationSequence.current) return
            setValidation({ kind: 'failed', message: 'Validation is unavailable because this editor did not receive a validate URL.' })
            setValidationState({ status: 'failed' })
            return
        }
        if (!mounted.current) return
        setValidationState({ status: 'checking' })
        setValidation(null)
        try {
            const graph = builtRef.current.graph
            const result = await send('POST', url, { graph })
            if (!mounted.current || request !== validationSequence.current || requestGeneration !== generation.current) return
            const next = interpretValidation(result, new Set(documentRef.current.nodes.map((node) => node.id)))
            setValidation(next)
            if (next.kind === 'valid') setValidationState({ status: next.warnings.length === 0 ? 'valid' : 'warning', count: next.warnings.length })
            else if (next.kind === 'invalid') setValidationState({ status: 'invalid', count: next.errors.length + next.unplaceable.length + Object.values(next.byNode).flat().length })
            else setValidationState({ status: 'failed' })
        } catch (reason: unknown) {
            if (mounted.current && request === validationSequence.current && requestGeneration === generation.current) {
                setValidation({ kind: 'failed', message: `Could not reach server to validate this flow: ${String(reason)}` })
                setValidationState({ status: 'failed' })
            }
        }
    }, [options.urls.validate])

    const publish = useCallback(async () => {
        if (!mounted.current || activePublish.current !== null || activeCredentialOperation.current !== null) return
        const currentBuilt = builtRef.current
        if (currentBuilt.unresolved.length > 0) {
            setPublishOutcome({ kind: 'failed', message: 'Choose which output each unresolved connection should use before publishing.' })
            return
        }
        const attempt = ++publishSequence.current
        const credentialAttempt = ++credentialSequence.current
        const publishedGeneration = generation.current
        activePublish.current = attempt
        activeCredentialOperation.current = { kind: 'publish', attempt: credentialAttempt }
        pendingWebhookMetadata.current = null
        let credentialMetadataCommitted = false
        setPublishing(true)
        setPublishOutcome(null)
        const owns = () => mounted.current
            && activePublish.current === attempt
            && publishSequence.current === attempt
            && activeCredentialOperation.current?.kind === 'publish'
            && activeCredentialOperation.current.attempt === credentialAttempt
        const readyRevision = await autosave.preparePublish()
        if (readyRevision === false) {
            if (owns() && publishedGeneration === generation.current) setPublishOutcome({ kind: 'failed', message: autosave.message ?? 'The draft could not be saved before publishing.' })
            if (owns()) {
                applyPendingWebhookMetadata()
                activePublish.current = null
                activeCredentialOperation.current = null
                setPublishing(false)
            }
            return
        }
        try {
            const result = await send('POST', options.urls.publish, {
                graph: currentBuilt.graph,
                draft_revision: readyRevision,
            })
            if (result.status === 409) {
                autosave.finishPublish(undefined, result.data)
                if (owns() && publishedGeneration === generation.current) setPublishOutcome(null)
                return
            }
            const next = interpretPublish(result, new Set(documentRef.current.nodes.map((node) => node.id)))
            autosave.finishPublish(next.kind === 'published' ? next.revision : undefined)
            if (!owns()) return
            if (next.kind === 'published') {
                const pending = pendingWebhookMetadata.current
                pendingWebhookMetadata.current = null
                credentialMetadataCommitted = true
                if (credentialAttempt > committedCredentialSequence.current) {
                    committedCredentialSequence.current = credentialAttempt
                    // Every successful publication supersedes a previous plaintext
                    // disclosure, including a success that returns no new secret.
                    // Credential metadata belongs to the still-owned editor session,
                    // not to the graph generation used for outcome presentation.
                    const publishedTrigger = currentBuilt.graph.nodes?.find((node) => defs[node.type]?.kind === 'trigger')
                    const publishedDef = publishedTrigger === undefined ? undefined : defs[publishedTrigger.type]
                    setWebhookState((current) => {
                        const endpointUrl = next.webhookUrl ?? current.metadata?.endpoint_url ?? null
                        const webhookPublished = publishedDef?.kind === 'trigger' && publishedDef.driver === 'webhook'
                        const metadata = endpointUrl === null && current.metadata === null && !webhookPublished
                            ? null
                            : {
                                endpoint_url: endpointUrl,
                                active: webhookPublished,
                                secret_rotated_at: current.metadata?.secret_rotated_at ?? null,
                            }
                        const operation: WebhookClientState = {
                            metadata,
                            disclosure: next.webhookSecret === undefined ? null : {
                                secret: next.webhookSecret,
                                credential: {
                                    endpointUrl,
                                    rotatedAt: metadata?.secret_rotated_at ?? null,
                                    version: next.version,
                                },
                            },
                        }
                        return reconcilePendingWebhookMetadata(operation, pending)
                    })
                }
            }
            if (publishedGeneration !== generation.current) return
            if (next.kind === 'published') setPublishedVersion(next.version)
            setPublishOutcome(next.kind === 'published'
                ? { kind: 'published', version: next.version, revision: next.revision }
                : next)
        } catch (reason: unknown) {
            autosave.finishPublish()
            if (owns() && publishedGeneration === generation.current) setPublishOutcome({ kind: 'failed', message: `Could not reach server to publish this flow: ${String(reason)}` })
        } finally {
            if (owns()) {
                if (!credentialMetadataCommitted) applyPendingWebhookMetadata()
                activePublish.current = null
                activeCredentialOperation.current = null
                setPublishing(false)
            }
        }
    }, [applyPendingWebhookMetadata, autosave, defs, options.urls.publish])

    const rotateWebhookSecret = useCallback(async () => {
        if (!mounted.current || activeRotation.current !== null || activeCredentialOperation.current !== null) return
        const credentialAttempt = ++credentialSequence.current
        activeRotation.current = credentialAttempt
        activeCredentialOperation.current = { kind: 'rotation', attempt: credentialAttempt }
        pendingWebhookMetadata.current = null
        let credentialMetadataCommitted = false
        const owns = () => mounted.current
            && activeRotation.current === credentialAttempt
            && activeCredentialOperation.current?.kind === 'rotation'
            && activeCredentialOperation.current.attempt === credentialAttempt
        setWebhookRotating(true)
        setWebhookRotationError(null)
        try {
            const result = await send('POST', options.urls.rotate_webhook_secret)
            if (!owns()) return
            if (!result.ok) {
                setWebhookRotationError(result.status === 403
                    ? 'You are not authorized to rotate this webhook secret.'
                    : 'Could not rotate the webhook secret. Try again.')
                return
            }
            const rotated = webhookRotationResponse(result.data)
            if (rotated === null) {
                setWebhookRotationError('Could not rotate the webhook secret because the server response was invalid.')
                return
            }
            if (credentialAttempt <= committedCredentialSequence.current) return
            committedCredentialSequence.current = credentialAttempt
            const pending = pendingWebhookMetadata.current
            pendingWebhookMetadata.current = null
            credentialMetadataCommitted = true
            setWebhookState((current) => {
                const metadata = current.metadata === null ? null : { ...current.metadata, secret_rotated_at: rotated.rotatedAt }
                const operation: WebhookClientState = {
                    metadata,
                    disclosure: metadata === null ? null : {
                        secret: rotated.secret,
                        credential: {
                            endpointUrl: metadata?.endpoint_url ?? null,
                            rotatedAt: rotated.rotatedAt,
                            version: publishedVersion,
                        },
                    },
                }
                return reconcilePendingWebhookMetadata(operation, pending)
            })
        } catch {
            if (owns()) setWebhookRotationError('Could not rotate the webhook secret. Check your connection and try again.')
        } finally {
            if (owns()) {
                if (!credentialMetadataCommitted) applyPendingWebhookMetadata()
                activeRotation.current = null
                activeCredentialOperation.current = null
                setWebhookRotating(false)
            }
        }
    }, [applyPendingWebhookMetadata, options.urls.rotate_webhook_secret, publishedVersion])

    const focusIssue = useCallback((nodeId: string | null, field: string | null) => {
        if (nodeId === null || !documentRef.current.nodes.some((node) => node.id === nodeId)) return
        selectNode(nodeId)
        canvas.current?.centerNode(nodeId)
        const source = publishOutcome !== null && publishOutcome.kind !== 'published' ? publishOutcome : validation
        const matching = graphIssues(source).find((issue) => issue.node === nodeId && issue.field === field)
        setIssueToFocus({ node: nodeId, field, message: matching?.message ?? 'This field needs attention.' })
    }, [publishOutcome, selectNode, validation])

    const selectedNode = selected.nodeId === null ? undefined : document.nodes.find((node) => node.id === selected.nodeId)
    // Publish failures are the most recent server verdict. A successful publish deliberately leaves useful validate warnings visible.
    const activeOutcome = publishOutcome !== null && publishOutcome.kind !== 'published' ? publishOutcome : validation
    const fieldErrors = activeOutcome?.kind === 'invalid' || activeOutcome?.kind === 'semantic'
        ? selectedNode === undefined ? [] : activeOutcome.byNode[selectedNode.id] ?? []
        : []
    const nodeErrors = useMemo(() => activeOutcome?.kind === 'invalid' || activeOutcome?.kind === 'semantic'
        ? Object.fromEntries(Object.entries(activeOutcome.byNode).map(([id, entries]) => [id, entries.map((entry) => entry.field === null ? entry.message : `${entry.field}: ${entry.message}`)]))
        : {}, [activeOutcome])
    const canvasNodes = useMemo(() => document.nodes.map((node) => ({ ...node, selected: node.id === selected.nodeId, data: { ...node.data, isStart: node.id === document.startId } })), [document.nodes, document.startId, selected.nodeId])
    const canvasEdges = useMemo(() => document.edges.map((edge) => ({ ...edge, selected: edge.id === selected.edgeId })), [document.edges, selected.edgeId])
    const unknownTypes = document.nodes.filter((node) => !Object.prototype.hasOwnProperty.call(defs, node.data.type)).map((node) => ({ nodeId: node.id, type: node.data.type }))
    const triggerNode = document.nodes.find((node) => defs[node.data.type]?.kind === 'trigger')
    const trigger = triggerNode === undefined ? null : defs[triggerNode.data.type] ?? null
    const availableTriggerDefinitions = options.trigger_nodes.filter((definition) => {
        const sources = Object.prototype.hasOwnProperty.call(options.trigger_sources, definition.driver)
            ? options.trigger_sources[definition.driver] ?? []
            : []
        return sources.some((source) => source.driver === definition.driver && definition.compatible_source_keys.includes(source.key))
    })
    const compatibleSourceKeys = trigger?.kind === 'trigger' ? new Set(trigger.compatible_source_keys) : new Set<string>()
    const registeredCompatibleSources = trigger?.kind === 'trigger'
        ? (Object.prototype.hasOwnProperty.call(options.trigger_sources, trigger.driver) ? options.trigger_sources[trigger.driver] ?? [] : [])
            .filter((source) => source.driver === trigger.driver && compatibleSourceKeys.has(source.key))
        : []
    const selectedSourceKey = triggerNode !== undefined && typeof triggerNode.data.config.source === 'string'
        ? triggerNode.data.config.source
        : null
    const triggerPublishDisabledReason = options.trigger_nodes.length > 0 && availableTriggerDefinitions.length === 0 && triggerNode === undefined
        ? 'No compatible trigger source is registered by this application.'
        : availableTriggerDefinitions.length > 0 && triggerNode === undefined
            ? 'Add a trigger before publishing this flow.'
        : trigger?.kind === 'trigger' && registeredCompatibleSources.length === 0
            ? 'No compatible trigger source is registered by this application.'
            : trigger?.kind === 'trigger' && (selectedSourceKey === null || !registeredCompatibleSources.some((source) => source.key === selectedSourceKey))
                ? 'The selected trigger source is not compatible with this trigger.'
                : null
    const publishDisabledReason = webhookRotating
        ? 'Webhook secret rotation is in progress.'
        : triggerPublishDisabledReason
    const message = outcomeMessages(activeOutcome)
    const save = { status: autosave.status, message: autosave.message ?? undefined } as EditorToolbarProps['save']
    const publishIndicator: PublishIndicator = publishOutcome?.kind === 'published'
        ? { status: 'published', version: publishOutcome.version }
        : publishing ? { status: 'publishing' }
        : publishOutcome?.kind === 'failed' ? { status: 'error', message: publishOutcome.message }
        : { status: 'idle' }

    const actions: EditorActions = useMemo(() => ({
        addNode, addAtViewportCenter, addTrigger, replaceTrigger, nodesChange, edgesChange, connect, selectNode, selectEdge, configure, configureTriggerSource, closeConfigTransaction,
        deleteNode, deleteSelection, undo: () => moveHistory('undo'), redo: () => moveHistory('redo'), autoLayout,
        validate, publish, resolveConflict, registerCanvas: (next) => { canvas.current = next }, focusIssue,
        setLibraryOpen: (open) => setView((current) => ({ ...current, libraryOpen: open })),
        setInspectorOpen: (open) => setView((current) => ({ ...current, inspectorOpen: open })),
    }), [addAtViewportCenter, addNode, addTrigger, autoLayout, closeConfigTransaction, configure, configureTriggerSource, connect, deleteNode, deleteSelection, edgesChange, focusIssue, moveHistory, nodesChange, publish, replaceTrigger, resolveConflict, selectEdge, selectNode, validate])

    return {
        document,
        selected: selectedNode,
        view,
        actions,
        optionsSource,
        canvasProps: { nodes: canvasNodes, edges: canvasEdges, defs, renderers: options.nodeRenderers, nodeErrors, onNodesChange: nodesChange, onEdgesChange: edgesChange, onConnect: connect, onNodeClick: selectNode, onEdgeClick: selectEdge, onPaneClick: () => { selectNode(null) }, onDropNodeType: (type, point) => { const definition = Object.prototype.hasOwnProperty.call(defs, type) ? defs[type] : undefined; if (definition?.kind === 'executable') addNode(definition, point); else if (definition?.kind === 'trigger') addTrigger(definition, point) }, onReady: (next) => { canvas.current = next }, onDispose: (old) => { if (canvas.current === old) canvas.current = null }, interactive: true, deleteKeyCode: null },
        canvasHudProps: { nodeCount: document.nodes.length, connectionCount: document.edges.length, validation: validationState },
        toolbarProps: { flowName: options.flow.name, triggerLabel: trigger?.label ?? 'No trigger', publishedVersion, save, validation: validationState, publish: publishIndicator, publishDisabledReason, credentialBusy: publishing || webhookRotating, canUndo: history.past.length > 0, canRedo: history.future.length > 0, hasSelection: selected.nodeId !== null || selected.edgeId !== null, onUndo: () => moveHistory('undo'), onRedo: () => moveHistory('redo'), onAutoLayout: autoLayout, onFit: () => canvas.current?.fit(), onDeleteSelected: deleteSelection, onValidate: () => { void validate() }, onPublish: () => { if (publishDisabledReason === null) void publish() } },
        noticeProps: { save, publish: publishIndicator, validation: validationState, structuralError: message.structural, graphMessages: [...message.graph, ...(message.failed === undefined ? [] : [message.failed])], validationMessage: validation?.kind === 'failed' ? validation.message : undefined, onKeepMine: () => resolveConflict('mine'), onUseTheirs: () => resolveConflict('theirs') },
        flowOverviewProps: { flow: { name: options.flow.name }, trigger: trigger === null ? null : { label: trigger.label, type: trigger.type }, triggerReadiness: publishDisabledReason, publishedVersion, nodeCount: document.nodes.length, connectionCount: document.edges.length, startNodeId: document.startId || null, validation: validationState, issues: graphIssues(activeOutcome), warnings: validation?.kind === 'valid' ? validation.warnings : validation?.kind === 'invalid' ? validation.warnings : [], errors: message.graph, unknownTypes, unresolvedOutputs: built.unresolved.map((edge) => ({ from: edge.source, to: edge.target })), onIssueSelect: (issue) => focusIssue(issue.node, issue.field) },
        nodeInspectorProps: selectedNode === undefined ? null : { node: selectedNode.data, def: defs[selectedNode.data.type], controls, errors: fieldErrors, issueToFocus, triggerSources: options.trigger_sources, triggerOptionsTemplate: options.urls.trigger_options, triggerSourceOptionsTemplate: options.urls.trigger_source_options, onConfigChange: (key, value) => configure(selectedNode.id, key, value), onTriggerSourceChange: (source) => configureTriggerSource(selectedNode.id, source), onConfigBlur: closeConfigTransaction, webhook: webhookMetadata, webhookSecret, webhookPublishing: publishing, webhookRotating, webhookRotationError, onAcknowledgeWebhookSecret: () => setWebhookState((current) => ({ ...current, disclosure: null })), onRotateWebhookSecret: () => { void rotateWebhookSecret() }, onDelete: () => deleteNode(selectedNode.id) },
    }
}
