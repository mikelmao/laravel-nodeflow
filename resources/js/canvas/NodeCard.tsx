import { Handle, Position, type NodeProps } from '@xyflow/react'
import { useContext } from 'react'
import { NodeflowIcon } from '../presentation/icons'
import { categoryClasses, categoryPresentation, nodeSummary } from '../presentation/node'
import { CanvasContext, type NodeRenderer, type NodeRendererMap } from './context'
import type { NodeflowNode } from './Canvas'
import { NODE_WIDTH, outputHandleTop } from './layout'

export function rendererFor(type: string, renderers: NodeRendererMap): NodeRenderer {
    return Object.prototype.hasOwnProperty.call(renderers, type) ? renderers[type]! : defaultNodeRenderer
}

/** The package body is deliberately short; wrapper chrome belongs to NodeCard. */
export const defaultNodeRenderer: NodeRenderer = ({ data, def }) => {
    if (def === undefined) {
        return (
            <p role="alert" className="px-3 pb-2 text-[11px] text-destructive">
                Unknown node type “{data.type}”. It can remain in a draft but cannot be published until this application registers it.
            </p>
        )
    }

    const summary = nodeSummary(data, def)
    return summary === '' ? null : <p className="px-3 pb-2 text-[11px] text-muted-foreground">{summary}</p>
}

/**
 * Handles, wrapper chrome, and the complete per-node error list belong to the
 * package so host body renderers cannot make a graph unwireable or hide issues.
 */
export function NodeCard({ id, data, selected, isConnectable }: NodeProps<NodeflowNode>) {
    const { defs, renderers, nodeErrors, decorations } = useContext(CanvasContext)
    const def = Object.prototype.hasOwnProperty.call(defs, data.type) ? defs[data.type] : undefined
    const outputs = def?.outputs ?? []
    const Body = rendererFor(data.type, renderers)
    const errors = Object.prototype.hasOwnProperty.call(nodeErrors, id) ? nodeErrors[id]! : []
    const selectionClassName = selected ? 'border-primary ring-1 ring-primary' : 'border-border'
    const decoration = Object.prototype.hasOwnProperty.call(decorations, id) ? decorations[id]! : undefined
    const dimClassName = decoration?.dimmed === true ? ' opacity-40' : ''
    const presentation = categoryPresentation(def?.group ?? '')
    const cardClassName = `relative rounded-md border bg-card shadow-sm ${selectionClassName} ${categoryClasses[presentation.accent]}${dimClassName}`
    const label = def?.label ?? data.type

    return (
        <article style={{ width: NODE_WIDTH }} aria-label={label} className={cardClassName}>
            <Handle
                type="target"
                position={Position.Left}
                isConnectable={isConnectable}
                className="!size-2 !bg-muted-foreground"
            />
            <header className="flex items-center gap-2 px-3 py-2">
                <span className="flex size-5 shrink-0 items-center justify-center rounded bg-background/70">
                    {def?.icon ? <span aria-hidden="true" className="text-xs leading-none">{def.icon}</span> : <NodeflowIcon name={presentation.icon} className="size-3.5" />}
                </span>
                <span className="min-w-0 flex-1 truncate text-xs font-semibold text-foreground">{label}</span>
                {data.isStart && <span className="rounded bg-primary px-1 text-[10px] font-semibold uppercase text-primary-foreground">START</span>}
                {errors.length > 0 && <span className="rounded bg-destructive/15 px-1 text-[10px] font-semibold uppercase text-destructive">ISSUE</span>}
            </header>
            <Body data={data} def={def} selected={selected} errors={errors} />
            {decoration !== undefined && decoration.badges.length > 0 && (
                <ul data-testid={`nodeflow-badges-${id}`} className="flex flex-wrap gap-1 px-3 pb-2 text-[10px] text-muted-foreground">
                    {decoration.badges.map((badge) => (
                        <li key={badge.key} className="rounded bg-muted px-1">
                            {badge.label} <span className="font-semibold text-foreground">{badge.value}</span>
                        </li>
                    ))}
                </ul>
            )}
            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 px-3 pb-2 text-[10px] text-destructive">
                    {errors.map((error) => <li key={error}>{error}</li>)}
                </ul>
            )}
            {outputs.length > 0 && (
                <div aria-label="Outputs" className="border-t border-border/70 py-1">
                    {outputs.map((output, index) => (
                        <div key={output} data-output-row className="flex h-6 items-center px-3 pr-5 text-[10px] text-muted-foreground">
                            <span className="truncate">{output}</span>
                            <Handle
                                id={output}
                                type="source"
                                position={Position.Right}
                                isConnectable={isConnectable}
                                style={{ top: outputHandleTop(index, outputs.length) }}
                                className="!size-2 !bg-primary"
                            />
                        </div>
                    ))}
                </div>
            )}
        </article>
    )
}
