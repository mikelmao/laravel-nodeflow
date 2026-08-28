/**
 * The field wire shape is transcribed from PHP's Field::toWireArray(). Custom
 * field types remain arbitrary strings so an unmatched editor control can fail
 * explicitly rather than silently rendering the wrong input.
 */
export type FieldPayload = { key: string; type: string; label: string; help: string | null; default: unknown; required: boolean; options: Record<string,string>; dynamic_options: boolean }

// Empty PHP configuration can arrive as []; editors normalize it before use.
export type GraphConfig = Record<string, unknown> | unknown[]

export type GraphComponentKind = 'trigger' | 'executable'

// Source: FlowEditorController::edit() palette over NodeDefinition::toArray().
export type ExecutableNodeTypePayload = {
  kind: 'executable'
  type: string
  label: string
  group: string
  icon: string | null
  description: string | null
  outputs: string[]
  fields: FieldPayload[]
  default_config: GraphConfig
  cardinality: ('subject'|'audience')[]
}

// Retained as the concise public name for executable-node definitions.
export type NodeTypePayload = ExecutableNodeTypePayload

// Source: FlowEditorController::edit() trigger_nodes over TriggerNodeRegistry::palette().
export type TriggerNodeTypePayload = {
  kind: 'trigger'
  type: string
  driver: string
  label: string
  icon: string | null
  description: string | null
  outputs: ['started']
  fields: FieldPayload[]
  default_config: GraphConfig
  compatible_source_keys: string[]
}

export type GraphComponentPayload = ExecutableNodeTypePayload | TriggerNodeTypePayload

// Source: FlowEditorController::triggerSources(), grouped by stable driver key.
export type TriggerSourcePayload = {
  key: string
  driver: string
  label: string
  icon: string | null
  description: string | null
  fields: FieldPayload[]
  default_config: GraphConfig
}
export type TriggerSourcesPayload = Record<string, TriggerSourcePayload[]>

/** @deprecated Use TriggerNodeTypePayload. */
export type TriggerPayload = TriggerNodeTypePayload

export type WebhookMetadata = {
  endpoint_url: string | null
  active: boolean
  secret_rotated_at: string | null
}

/**
 * Draft graph containers and config/output values may be absent or null. Stored
 * positions round-trip untouched. A null output is unresolved and must never be
 * replaced with a guessed default.
 */
export type GraphNode = { id: string; type: string; config?: GraphConfig | null; position?: {x:number;y:number} }
export type GraphEdge = { from: string; to: string; output?: string | null }
export type Graph = { start?: string | null; nodes?: GraphNode[] | null; edges?: GraphEdge[] | null }

// Source: FlowEditorController::edit(). draft_revision is the concurrency token;
// draft_updated_at is display metadata only.
export type FlowSummary = { id:number; name:string; status:string; version:number|null; draft_revision:number; draft_updated_at:string|null }
export type EditorUrls = {
  draft:string
  publish:string
  options:string
  validate?:string
  rotate_webhook_secret:string
  trigger_options:string
  trigger_source_options:string
}

// A graph-level error may omit its node id, so node is nullable on the wire.
export type NodeErrorEntry = { node:string|null; field:string|null; message:string }

/**
 * Validation and semantic publish errors are distinguished by the presence of
 * node_errors. This type is exported intentionally for host wrappers.
 */
export type PublishErrorBody = { message?:string; errors?:string[]|Record<string,string[]>; node_errors?:NodeErrorEntry[] }
export type NodeCardData = { id:string; type:string; kind:GraphComponentKind|null; config:Record<string,unknown>; isStart:boolean }
export type CanvasNode = { id:string; type:'nodeflowNode'; position:{x:number;y:number}; data:NodeCardData }
export type CanvasEdge = { id:string; type?:'nodeflowEdge'; source:string; sourceHandle:string|null; target:string; label?:string }

// These graph contracts deliberately do not import xyflow; adapters own that boundary.

// Source: Nodeflow\Runs\RunOverlay::snapshot(). One entry per node in the run's
// pinned graph. `reached` is row existence or a subject sitting here, never a
// count of subjects released — see the package spec's E13.
export type NodeOverlay = { reached: boolean; byOutput: Record<string, number>; waiting: number; failed: number; error: string | null }

// `terminal` is server-computed, so the client never hardcodes which run
// statuses end a run. The server recognizes completed, failed, and cancelled.
export type OverlaySnapshot = { status: string; terminal: boolean; nodes: Record<string, NodeOverlay> }

// Source: RunViewController::show(). `version` is the pinned version, which may
// be older than the flow's current one.
export type RunSummary = { id: number; status: string; terminal: boolean; strategy: string; is_test: boolean; started_via: string; trigger_node_id: string; started_at: string | null; ended_at: string | null; error: string | null; version: number; flow: { id: number; name: string } }

// `subjects` carries the __NODEFLOW_NODE__ sentinel; the client substitutes it.
export type RunUrls = { overlay: string; subjects: string }

// Source: Nodeflow\Runs\RunSubjects::atNode(). Only active subjects at a node
// are listable — every terminal status nulls current_node_id.
export type RunSubjectRow = { id: number; subject_type: string; subject_id: string; status: string; current_node_id: string | null; last_error: string | null; exited_at: string | null }
