/**
 * The field wire shape is transcribed from PHP's Field::toWireArray(). Custom
 * field types remain arbitrary strings so an unmatched editor control can fail
 * explicitly rather than silently rendering the wrong input.
 */
export type FieldPayload = { key: string; type: string; label: string; help: string | null; default: unknown; required: boolean; options: Record<string,string>; dynamic_options: boolean }

// Empty PHP configuration can arrive as []; editors normalize it before use.
export type GraphConfig = Record<string, unknown> | unknown[]

// Source: NodeRegistry::palette() over NodeDefinition::toArray().
export type NodeTypePayload = { type: string; label: string; group: string; icon: string | null; description: string | null; outputs: string[]; fields: FieldPayload[]; default_config: GraphConfig; cardinality: ('subject'|'audience')[] }

// Source: TriggerRegistry::palette() over TriggerDefinition::toArray().
export type TriggerPayload = { type: string; label: string; description: string | null; fields: FieldPayload[] }

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
export type FlowSummary = { id:number; name:string; trigger_type:string; status:string; version:number|null; draft_revision:number; draft_updated_at:string|null }
export type EditorUrls = { draft:string; publish:string; options:string }

// A graph-level error may omit its node id, so node is nullable on the wire.
export type NodeErrorEntry = { node:string|null; field:string|null; message:string }

/**
 * Validation and semantic publish errors are distinguished by the presence of
 * node_errors. This type is exported intentionally for host wrappers.
 */
export type PublishErrorBody = { message?:string; errors?:string[]|Record<string,string[]>; node_errors?:NodeErrorEntry[] }
export type NodeCardData = { id:string; type:string; config:Record<string,unknown>; isStart:boolean }
export type CanvasNode = { id:string; type:'nodeflowNode'; position:{x:number;y:number}; data:NodeCardData }
export type CanvasEdge = { id:string; source:string; sourceHandle:string|null; target:string; label?:string }

// These graph contracts deliberately do not import xyflow; adapters own that boundary.
