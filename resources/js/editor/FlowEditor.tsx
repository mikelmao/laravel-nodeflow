import {
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    type Connection,
    type EdgeChange,
    type NodeChange,
} from '@xyflow/react'
import { useCallback, useMemo, useRef, useState } from 'react'
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
import { ConfigPanel } from './ConfigPanel'
import { Palette } from './Palette'
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

function copiedConfig(definition: NodeTypePayload): Record<string, unknown> {
    return Array.isArray(definition.default_config) ? {} : { ...definition.default_config }
}

function unique(messages: string[]): string[] {
    return [...new Set(messages)]
}

export function FlowEditor({
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
    const initialCanvas = useRef(toCanvas(graph))
    const [nodes, setNodes] = useState<NodeflowNode[]>(initialCanvas.current.nodes)
    const [edges, setEdges] = useState<NodeflowEdge[]>(initialCanvas.current.edges)
    const [startId, setStartId] = useState(graph.start ?? '')
    const [selectedId, setSelectedId] = useState<string | null>(null)
    const [outcome, setOutcome] = useState<PublishOutcome | null>(null)
    const [publishing, setPublishing] = useState(false)
    const [publishedVersion, setPublishedVersion] = useState(flow.version)

    const defs = useMemo(() => defsByType(palette), [palette])
    const mergedControls = useMemo(() => mergeControls(controls), [controls])
    const optionsCache = useRef(new Map<string, Record<string, string>>())
    const optionsSource = useMemo(() => ({ template: urls.options, cache: optionsCache.current }), [urls.options])
    const trigger = triggers.find((candidate) => candidate.type === flow.trigger_type)

    const canvasNodes = useMemo(
        () => nodes.map((node) => ({ ...node, data: { ...node.data, isStart: node.id === startId } })),
        [nodes, startId],
    )
    const built = useMemo(() => toGraph({ nodes: canvasNodes, edges }, startId, defs), [canvasNodes, edges, startId, defs])
    const autosave = useAutosave({
        url: urls.draft,
        initialRevision: flow.draft_revision,
        graph: built.graph,
        debounceMs: autosaveDebounceMs,
    })

    const cleanRemoved = useCallback((removed: Set<string>) => {
        setEdges((current) => current.filter((edge) => !removed.has(edge.source) && !removed.has(edge.target)))
        setStartId((current) => removed.has(current) ? '' : current)
        setSelectedId((current) => current !== null && removed.has(current) ? null : current)
    }, [])

    const onNodesChange = useCallback((changes: NodeChange<NodeflowNode>[]) => {
        const removed = new Set(changes.filter((change) => change.type === 'remove').map((change) => change.id))
        setNodes((current) => applyNodeChanges(changes, current))
        if (removed.size > 0) {
            cleanRemoved(removed)
        }
    }, [cleanRemoved])

    const onEdgesChange = useCallback((changes: EdgeChange<NodeflowEdge>[]) => {
        setEdges((current) => applyEdgeChanges(changes, current))
    }, [])

    const onConnect = useCallback((connection: Connection) => {
        const sourceType = nodes.find((node) => node.id === connection.source)?.data.type
        if (!canConnect(sourceType, connection.sourceHandle, defs)) {
            return
        }

        setEdges((current) => addEdge<NodeflowEdge>({
            ...connection,
            label: connection.sourceHandle ?? undefined,
        }, current))
    }, [nodes, defs])

    const addNode = useCallback((definition: NodeTypePayload) => {
        setNodes((current) => {
            const id = nextNodeId(definition.type, new Set(current.map((node) => node.id)))
            const next: NodeflowNode = {
                id,
                type: 'nodeflowNode',
                position: { x: 120 + current.length * 30, y: 120 + current.length * 20 },
                data: { id, type: definition.type, config: copiedConfig(definition), isStart: current.length === 0 },
            }
            if (current.length === 0) {
                setStartId(id)
            }
            setSelectedId(id)
            return [...current, next]
        })
        setOutcome(null)
    }, [])

    const deleteNode = useCallback((id: string) => {
        const removed = new Set([id])
        setNodes((current) => current.filter((node) => !removed.has(node.id)))
        cleanRemoved(removed)
        setOutcome(null)
    }, [cleanRemoved])

    const selected = selectedId === null ? undefined : canvasNodes.find((node) => node.id === selectedId)
    const semanticEntries = outcome?.kind === 'semantic' && selected !== undefined
        ? outcome.byNode[selected.id] ?? []
        : []
    const nodeErrors = useMemo(() => {
        if (outcome?.kind !== 'semantic') {
            return {}
        }

        return Object.fromEntries(Object.entries(outcome.byNode).map(([id, entries]) => [
            id,
            entries.map((entry) => entry.field === null ? entry.message : `${entry.field}: ${entry.message}`),
        ]))
    }, [outcome])

    const publish = useCallback(async () => {
        if (built.unresolved.length > 0) {
            setOutcome({
                kind: 'failed',
                message: 'Choose which output each unresolved connection should use before publishing.',
            })
            return
        }

        setPublishing(true)
        setOutcome(null)
        const ready = await autosave.preparePublish()
        if (!ready) {
            setOutcome({ kind: 'failed', message: autosave.message ?? 'The draft could not be saved before publishing.' })
            setPublishing(false)
            return
        }

        try {
            const result = await send('POST', urls.publish, { graph: built.graph })
            const next = interpretPublish(result, new Set(canvasNodes.map((node) => node.id)))
            autosave.finishPublish(next.kind === 'published' ? next.revision : undefined)
            if (next.kind === 'published') {
                setPublishedVersion(next.version)
            }
            setOutcome(next)
        } catch (reason: unknown) {
            autosave.finishPublish()
            setOutcome({ kind: 'failed', message: `Could not reach server to publish this flow: ${String(reason)}` })
        } finally {
            setPublishing(false)
        }
    }, [autosave, built, canvasNodes, urls.publish])

    const useTheirs = useCallback(() => {
        const conflict = autosave.conflict
        if (conflict === null) {
            return
        }

        const converted = toCanvas(conflict.graph)
        const canonical = toGraph(converted, conflict.graph.start ?? '', defs)
        setNodes(converted.nodes)
        setEdges(converted.edges)
        setStartId(conflict.graph.start ?? '')
        setSelectedId(null)
        setOutcome(null)
        autosave.resolveConflict('theirs', canonical.graph)
    }, [autosave, defs])

    const bannerMessages = outcome?.kind === 'semantic'
        ? unique([...outcome.banner, ...outcome.unplaceable])
        : []

    return (
        <FieldOptionsContext.Provider value={optionsSource}>
            <section className={className ?? 'space-y-4'}>
                <header className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">{flow.name}</h1>
                        <p>{trigger?.label ?? flow.trigger_type}</p>
                        {trigger?.description && <p>{trigger.description}</p>}
                        <p>published v{publishedVersion ?? '-'}</p>
                        <p>Start: {startId || 'none'}</p>
                        <p aria-live="polite">Draft: {autosave.status}</p>
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

                {outcome?.kind === 'failed' && <p role="alert">{outcome.message}</p>}
                {outcome?.kind === 'structural' && (
                    <div role="alert">
                        <p>The editor sent a graph the server could not read. This is a client bug.</p>
                        <ul>{outcome.developer.map((message) => <li key={message}>{message}</li>)}</ul>
                    </div>
                )}
                {outcome?.kind === 'semantic' && (
                    <div role="alert">
                        <p>This flow could not be published.</p>
                        <ul>{bannerMessages.map((message) => <li key={message}>{message}</li>)}</ul>
                    </div>
                )}
                {outcome?.kind === 'published' && <p role="status">Published v{outcome.version}.</p>}

                <div className="grid grid-cols-[16rem_minmax(0,1fr)_20rem] gap-4">
                    <Palette palette={palette} onAdd={addNode} />
                    <Canvas
                        nodes={canvasNodes}
                        edges={edges}
                        defs={defs}
                        renderers={nodeRenderers}
                        nodeErrors={nodeErrors}
                        onNodesChange={onNodesChange}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        onNodeClick={setSelectedId}
                    />
                    {selected === undefined ? (
                        <p>Select a node to configure it.</p>
                    ) : (
                        <ConfigPanel
                            node={selected.data}
                            def={defs[selected.data.type]}
                            controls={mergedControls}
                            errors={semanticEntries}
                            isStart={selected.id === startId}
                            onConfigChange={(key, value) => {
                                setNodes((current) => current.map((node) => node.id === selected.id
                                    ? { ...node, data: { ...node.data, config: { ...node.data.config, [key]: value } } }
                                    : node))
                                setOutcome(null)
                            }}
                            onMakeStart={() => {
                                setStartId(selected.id)
                                setOutcome(null)
                            }}
                            onDelete={() => deleteNode(selected.id)}
                        />
                    )}
                </div>
            </section>
        </FieldOptionsContext.Provider>
    )
}
