import { Handle, Position, type NodeProps } from '@xyflow/react'
import { useContext } from 'react'
import { CanvasContext, type NodeRenderer, type NodeRendererMap } from './context'
import type { NodeflowNode } from './Canvas'
import { NODE_WIDTH, outputHandleTop } from './layout'

export function rendererFor(type: string, renderers: NodeRendererMap): NodeRenderer {
    return renderers[type] ?? defaultNodeRenderer
}

/**
 * The package default exposes the definition label, id, start marker, group,
 * description, and a compact config preview. Unknown types remain loud and
 * diagnosable instead of becoming blank cards.
 */
export const defaultNodeRenderer: NodeRenderer = ({ data, def }) => (
    <div className="space-y-1 px-3 py-2">
        <div className="flex items-center gap-1.5">
            {data.isStart && (
                <span className="rounded bg-primary px-1 text-[10px] font-semibold uppercase text-primary-foreground">
                    START
                </span>
            )}
            {def?.icon && <span aria-hidden="true">{def.icon}</span>}
            <span className="text-xs font-semibold text-foreground">{def?.label ?? data.type}</span>
        </div>
        <p className="font-mono text-[10px] text-muted-foreground">{data.id}</p>
        {def?.group && <p className="text-[10px] text-muted-foreground">{def.group}</p>}
        {def === undefined ? (
            <p role="alert" className="text-[11px] text-destructive">
                Node type "{data.type}" is not registered in this application. It can be saved as a draft but not
                published.
            </p>
        ) : (
            <>
                {def.description && <p className="text-[10px] text-muted-foreground">{def.description}</p>}
                {Object.entries(data.config)
                    .filter(([, value]) => value !== null && value !== '' && value !== undefined)
                    .slice(0, 3)
                    .map(([key, value]) => (
                        <p key={key} className="truncate text-[10px] text-muted-foreground">
                            {key}: <span className="text-foreground">{String(value)}</span>
                        </p>
                    ))}
            </>
        )}
    </div>
)

/**
 * Handles and the mandatory per-node error list always belong to this wrapper.
 * A source handle's id is exactly the declared output name, preserving the
 * graph edge contract even when a host supplies a custom body renderer.
 */
export function NodeCard({ id, data, selected, isConnectable }: NodeProps<NodeflowNode>) {
    const { defs, renderers, nodeErrors } = useContext(CanvasContext)
    const def = defs[data.type]
    const outputs = def?.outputs ?? []
    const Body = rendererFor(data.type, renderers)
    const errors = nodeErrors[id] ?? []
    const selectionClassName = selected ? 'border-primary ring-1 ring-primary' : 'border-border'

    return (
        <div style={{ width: NODE_WIDTH }} className={`rounded-md border bg-card shadow-sm ${selectionClassName}`}>
            <Handle
                type="target"
                position={Position.Left}
                isConnectable={isConnectable}
                className="!size-2 !bg-muted-foreground"
            />
            <Body data={data} def={def} selected={selected} errors={errors} />
            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 px-3 pb-2 text-[10px] text-destructive">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
            {outputs.map((output, index) => (
                <Handle
                    key={output}
                    id={output}
                    type="source"
                    position={Position.Right}
                    isConnectable={isConnectable}
                    style={{ top: outputHandleTop(index) }}
                    className="!size-2 !bg-primary"
                >
                    <span className="pointer-events-none absolute right-3 -top-1.5 text-[9px] text-muted-foreground">
                        {output}
                    </span>
                </Handle>
            ))}
        </div>
    )
}
