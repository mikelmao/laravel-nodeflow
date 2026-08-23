import {
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    type Connection,
    type EdgeChange,
    type NodeChange,
} from '@xyflow/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Canvas, type NodeflowEdge, type NodeflowNode } from '../canvas/Canvas'
import type { NodeRendererMap } from '../canvas/context'
import { mergeControls } from '../controls'
import type { ControlMap } from '../controls/types'
import { FieldOptionsContext } from '../controls/useFieldOptions'
import { toCanvas } from '../graph/toCanvas'
import { defsByType, toGraph } from '../graph/toGraph'
import type {
    EditorUrls,
    FlowSummary,
    Graph,
    NodeTypePayload,
    TriggerPayload,
} from '../graph/types'
import { send } from '../http'
import { canConnect, nextNodeId } from './ids'
import { NodeInspector } from './NodeInspector'
import { NodeLibrary } from './NodeLibrary'
import { interpretPublish, type PublishOutcome } from './publish'
import { useAutosave } from './useAutosave'

export type FlowEditorProps = {
    flow: FlowSummary
    graph: Graph
    palette: NodeTypePayload[]
    triggers: TriggerPayload[]
    urls: EditorUrls
    controls?: ControlMap
    nodeRenderers?: NodeRendererMap
    autosaveDebounceMs?: number
    className?: string
}

type EditorState = {
    nodes: NodeflowNode[]
    edges: NodeflowEdge[]
    startId: string
    selectedId: string | null
    outcome: PublishOutcome | null
    publishedVersion: number | null
}

function initialEditorState(flow: FlowSummary, graph: Graph): EditorState {
    const canvas = toCanvas(graph)
    return {
        nodes: canvas.nodes,
        edges: canvas.edges,
        startId: graph.start ?? '',
        selectedId: null,
        outcome: null,
        publishedVersion: flow.version,
    }
}

function copiedConfig(definition: NodeTypePayload): Record<string, unknown> {
    return Array.isArray(definition.default_config) ? {} : { ...definition.default_config }
}

function unique(messages: string[]): string[] {
    return [...new Set(messages)]
}

function sessionKey({ flow, urls }: FlowEditorProps): string {
    return JSON.stringify([flow.id, urls.draft, flow.draft_revision, flow.version])
}

/** Authoritative server identity remounts every session-local state/ref together. */
export function FlowEditor(props: FlowEditorProps) {
    return <FlowEditorSession key={sessionKey(props)} {...props} />
}

function FlowEditorSession({
    flow,
    graph,
    palette,
    triggers,
    urls,
    controls,
    nodeRenderers,
    autosaveDebounceMs,
    className,
}: FlowEditorProps) {
    const [editor, setEditor] = useState(() => initialEditorState(flow, graph))
    const [publishing, setPublishing] = useState(false)
    const editorRef = useRef(editor)
    editorRef.current = editor
    const graphGeneration = useRef(0)
    const mounted = useRef(true)
    const publishSequence = useRef(0)
    const activePublish = useRef<number | null>(null)
    const optionsCache = useRef(new Map<string, Record<string, string>>())

    useEffect(() => {
        mounted.current = true
        return () => {
            mounted.current = false
            activePublish.current = null
        }
    }, [])

    const defs = useMemo(() => defsByType(palette), [palette])
    const mergedControls = useMemo(() => mergeControls(controls), [controls])
    const optionsSource = useMemo(() => ({ template: urls.options, cache: optionsCache.current }), [urls.options])
    const trigger = triggers.find((candidate) => candidate.type === flow.trigger_type)

    const canvasNodes = useMemo(
        () => editor.nodes.map((node) => ({
            ...node,
            data: { ...node.data, isStart: node.id === editor.startId },
        })),
        [editor.nodes, editor.startId],
    )
    const built = useMemo(
        () => toGraph({ nodes: canvasNodes, edges: editor.edges }, editor.startId, defs),
        [canvasNodes, editor.edges, editor.startId, defs],
    )
    const autosave = useAutosave({
        url: urls.draft,
        initialRevision: flow.draft_revision,
        graph: built.graph,
        debounceMs: autosaveDebounceMs,
    })

    const onNodesChange = useCallback((changes: NodeChange<NodeflowNode>[]) => {
        const changesGraph = changes.some((change) => change.type !== 'select' && change.type !== 'dimensions')
        if (changesGraph) {
            graphGeneration.current += 1
        }

        setEditor((current) => {
            const removed = new Set(changes.filter((change) => change.type === 'remove').map((change) => change.id))
            return {
                ...current,
                nodes: applyNodeChanges(changes, current.nodes),
                edges: removed.size === 0
                    ? current.edges
                    : current.edges.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)),
                startId: removed.has(current.startId) ? '' : current.startId,
                selectedId: current.selectedId !== null && removed.has(current.selectedId) ? null : current.selectedId,
                outcome: changesGraph ? null : current.outcome,
            }
        })
    }, [])

    const onEdgesChange = useCallback((changes: EdgeChange<NodeflowEdge>[]) => {
        const changesGraph = changes.some((change) => change.type !== 'select')
        if (changesGraph) {
            graphGeneration.current += 1
        }
        setEditor((current) => ({
            ...current,
            edges: applyEdgeChanges(changes, current.edges),
            outcome: changesGraph ? null : current.outcome,
        }))
    }, [])

    const onConnect = useCallback((connection: Connection) => {
        setEditor((current) => {
            const source = current.nodes.find((node) => node.id === connection.source)
            const target = current.nodes.find((node) => node.id === connection.target)
            if (source === undefined || target === undefined
                || !canConnect(source.data.type, connection.sourceHandle, defs)) {
                return current
            }

            graphGeneration.current += 1
            return {
                ...current,
                edges: addEdge<NodeflowEdge>({
                    ...connection,
                    label: connection.sourceHandle ?? undefined,
                }, current.edges),
                outcome: null,
            }
        })
    }, [defs])

    const addNode = useCallback((definition: NodeTypePayload) => {
        graphGeneration.current += 1
        setEditor((current) => {
            const id = nextNodeId(definition.type, new Set(current.nodes.map((node) => node.id)))
            const first = current.nodes.length === 0
            const next: NodeflowNode = {
                id,
                type: 'nodeflowNode',
                position: { x: 120 + current.nodes.length * 30, y: 120 + current.nodes.length * 20 },
                data: { id, type: definition.type, config: copiedConfig(definition), isStart: first },
            }
            return {
                ...current,
                nodes: [...current.nodes, next],
                startId: first ? id : current.startId,
                selectedId: id,
                outcome: null,
            }
        })
    }, [])

    const deleteNode = useCallback((id: string) => {
        graphGeneration.current += 1
        setEditor((current) => ({
            ...current,
            nodes: current.nodes.filter((node) => node.id !== id),
            edges: current.edges.filter((edge) => edge.source !== id && edge.target !== id),
            startId: current.startId === id ? '' : current.startId,
            selectedId: current.selectedId === id ? null : current.selectedId,
            outcome: null,
        }))
    }, [])

    const selected = editor.selectedId === null
        ? undefined
        : canvasNodes.find((node) => node.id === editor.selectedId)
    const semanticEntries = editor.outcome?.kind === 'semantic' && selected !== undefined
        ? editor.outcome.byNode[selected.id] ?? []
        : []
    const nodeErrors = useMemo(() => {
        if (editor.outcome?.kind !== 'semantic') {
            return {}
        }

        return Object.fromEntries(Object.entries(editor.outcome.byNode).map(([id, entries]) => [
            id,
            entries.map((entry) => entry.field === null ? entry.message : `${entry.field}: ${entry.message}`),
        ]))
    }, [editor.outcome])

    const publish = useCallback(async () => {
        if (activePublish.current !== null) {
            return
        }
        if (built.unresolved.length > 0) {
            setEditor((current) => ({
                ...current,
                outcome: {
                    kind: 'failed',
                    message: 'Choose which output each unresolved connection should use before publishing.',
                },
            }))
            return
        }

        const attempt = ++publishSequence.current
        activePublish.current = attempt
        const publishedGeneration = graphGeneration.current
        const ownsAttempt = () => mounted.current && activePublish.current === attempt
        setPublishing(true)
        setEditor((current) => ({ ...current, outcome: null }))

        const ready = await autosave.preparePublish()
        if (!ready) {
            if (ownsAttempt()) {
                if (publishedGeneration === graphGeneration.current) {
                    setEditor((current) => ({
                        ...current,
                        outcome: {
                            kind: 'failed',
                            message: autosave.message ?? 'The draft could not be saved before publishing.',
                        },
                    }))
                }
                activePublish.current = null
                setPublishing(false)
            }
            return
        }

        try {
            const result = await send('POST', urls.publish, { graph: built.graph })
            const currentIds = new Set(editorRef.current.nodes.map((node) => node.id))
            const next = interpretPublish(result, currentIds)
            autosave.finishPublish(next.kind === 'published' ? next.revision : undefined)

            if (!ownsAttempt()) {
                return
            }
            if (next.kind === 'published') {
                setEditor((current) => ({
                    ...current,
                    publishedVersion: next.version,
                    outcome: next,
                }))
            } else if (publishedGeneration === graphGeneration.current) {
                setEditor((current) => ({ ...current, outcome: next }))
            }
        } catch (reason: unknown) {
            autosave.finishPublish()
            if (ownsAttempt() && publishedGeneration === graphGeneration.current) {
                setEditor((current) => ({
                    ...current,
                    outcome: { kind: 'failed', message: `Could not reach server to publish this flow: ${String(reason)}` },
                }))
            }
        } finally {
            if (ownsAttempt()) {
                activePublish.current = null
                setPublishing(false)
            }
        }
    }, [autosave, built, urls.publish])

    const useTheirs = useCallback(() => {
        const conflict = autosave.conflict
        if (conflict === null) {
            return
        }

        const converted = toCanvas(conflict.graph)
        const canonical = toGraph(converted, conflict.graph.start ?? '', defs)
        graphGeneration.current += 1
        setEditor((current) => ({
            ...current,
            nodes: converted.nodes,
            edges: converted.edges,
            startId: conflict.graph.start ?? '',
            selectedId: null,
            outcome: null,
        }))
        autosave.resolveConflict('theirs', canonical.graph)
    }, [autosave, defs])

    const bannerMessages = editor.outcome?.kind === 'semantic'
        ? unique([...editor.outcome.banner, ...editor.outcome.unplaceable])
        : []
    const autosaveDetail = autosave.status === 'error' && autosave.message !== null
        ? ` — ${autosave.message}`
        : ''

    return (
        <FieldOptionsContext.Provider value={optionsSource}>
            <section className={className ?? 'space-y-4'}>
                <header className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">{flow.name}</h1>
                        <p>{trigger?.label ?? flow.trigger_type}</p>
                        {trigger?.description && <p>{trigger.description}</p>}
                        <p>published v{editor.publishedVersion ?? '-'}</p>
                        <p>Start: {editor.startId || 'none'}</p>
                        <p aria-live="polite">Draft: {autosave.status}{autosaveDetail}</p>
                    </div>
                    <button type="button" disabled={publishing} onClick={() => void publish()}>
                        {publishing ? 'Publishing' : 'Publish'}
                    </button>
                </header>

                {autosave.status === 'conflict' && autosave.conflict !== null && (
                    <div role="alert" className="space-y-2 rounded border border-destructive p-3">
                        <p>{autosave.message ?? 'Someone else edited this flow.'} Server revision: {autosave.conflict.revision}.</p>
                        <button type="button" onClick={() => autosave.resolveConflict('mine')}>Keep mine</button>
                        <button type="button" onClick={useTheirs}>Use theirs</button>
                    </div>
                )}

                {editor.outcome?.kind === 'failed' && <p role="alert">{editor.outcome.message}</p>}
                {editor.outcome?.kind === 'structural' && (
                    <div role="alert">
                        <p>The editor sent a graph the server could not read. This is a client bug.</p>
                        <ul>{editor.outcome.developer.map((message) => <li key={message}>{message}</li>)}</ul>
                    </div>
                )}
                {editor.outcome?.kind === 'semantic' && (
                    <div role="alert">
                        <p>This flow could not be published.</p>
                        <ul>{bannerMessages.map((message) => <li key={message}>{message}</li>)}</ul>
                    </div>
                )}
                {editor.outcome?.kind === 'published' && <p role="status">Published v{editor.outcome.version}.</p>}

                <div className="grid grid-cols-[16rem_minmax(0,1fr)_20rem] gap-4">
                    <NodeLibrary palette={palette} onAdd={addNode} />
                    <Canvas
                        nodes={canvasNodes}
                        edges={editor.edges}
                        defs={defs}
                        renderers={nodeRenderers}
                        nodeErrors={nodeErrors}
                        onNodesChange={onNodesChange}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        onNodeClick={(id) => setEditor((current) => ({ ...current, selectedId: id }))}
                    />
                    {selected === undefined ? (
                        <p>Select a node to configure it.</p>
                    ) : (
                        <NodeInspector
                            node={selected.data}
                            def={defs[selected.data.type]}
                            controls={mergedControls}
                            errors={semanticEntries}
                            isStart={selected.id === editor.startId}
                            onConfigChange={(key, value) => {
                                graphGeneration.current += 1
                                setEditor((current) => ({
                                    ...current,
                                    nodes: current.nodes.map((node) => node.id === selected.id
                                        ? { ...node, data: { ...node.data, config: { ...node.data.config, [key]: value } } }
                                        : node),
                                    outcome: null,
                                }))
                            }}
                            onMakeStart={() => {
                                graphGeneration.current += 1
                                setEditor((current) => ({ ...current, startId: selected.id, outcome: null }))
                            }}
                            onDelete={() => deleteNode(selected.id)}
                        />
                    )}
                </div>
            </section>
        </FieldOptionsContext.Provider>
    )
}
