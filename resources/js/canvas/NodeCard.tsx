import { Handle, Position, type NodeProps } from '@xyflow/react'
import { useContext } from 'react'
import { NodeflowIcon } from '../presentation/icons'
import { categoryClasses, categoryPresentation, nodeSummary } from '../presentation/node'
import { CanvasContext, type NodeRenderer, type NodeRendererMap } from './context'
import type { NodeflowNode } from './Canvas'
import { NODE_WIDTH } from './layout'

export function rendererFor(type: string, renderers: NodeRendererMap): NodeRenderer {
    return Object.prototype.hasOwnProperty.call(renderers, type) ? renderers[type]! : defaultNodeRenderer
}

/** The package body is deliberately short; wrapper chrome belongs to NodeCard. */
export const defaultNodeRenderer: NodeRenderer = ({ data, def }) => {
    if (def === undefined) {
        return (
            <p role="alert" className="px-3 pb-2.5 text-xs leading-4 text-destructive">
                Unknown node type “{data.type}”. It can remain in a draft but cannot be published until this application registers it.
            </p>
        )
    }

    const summary = nodeSummary(data, def)
    return summary === '' ? null : <p title={summary} className="line-clamp-2 break-words px-3 pb-2.5 text-xs leading-4 text-muted-foreground">{summary}</p>
}

const badgeClass = 'shrink-0 rounded px-1.5 py-px text-[10px] font-semibold uppercase leading-4 tracking-wide'

/**
 * Handles, wrapper chrome, and the complete per-node error list belong to the
 * package so host body renderers cannot make a graph unwireable or hide issues.
 * Colors come only from host tokens; React Flow's handle defaults are
 * overridden with important utilities because its stylesheet is unlayered.
 */
export function NodeCard({ id, data, selected, isConnectable }: NodeProps<NodeflowNode>) {
    const { defs, renderers, nodeErrors, decorations } = useContext(CanvasContext)
    const def = Object.prototype.hasOwnProperty.call(defs, data.type) ? defs[data.type] : undefined
    const outputs = def?.outputs ?? []
    const Body = rendererFor(data.type, renderers)
    const errors = Object.prototype.hasOwnProperty.call(nodeErrors, id) ? nodeErrors[id]! : []
    const decoration = Object.prototype.hasOwnProperty.call(decorations, id) ? decorations[id]! : undefined
    const dimClassName = decoration?.dimmed === true ? ' opacity-40' : ''
    const presentation = categoryPresentation(def?.kind === 'executable' ? def.group : 'Trigger')
    const isTrigger = def?.kind === 'trigger'
    const stateClassName = selected
        ? 'border-primary ring-2 ring-primary/25'
        : errors.length > 0 || def === undefined ? 'border-destructive/60' : 'border-border'
    const cardClassName = `relative rounded-lg border bg-card text-card-foreground shadow-sm ${stateClassName}${dimClassName}`
    const label = def?.label ?? data.type

    return (
        <article style={{ width: NODE_WIDTH }} aria-label={label} className={cardClassName}>
            {!isTrigger && (
                <Handle
                    type="target"
                    position={Position.Left}
                    isConnectable={isConnectable}
                    aria-label="Input"
                    className="!size-3 !border-2 !border-card !bg-muted-foreground"
                />
            )}
            <header className="flex items-center gap-2 px-3 pb-1.5 pt-2.5">
                <span aria-hidden="true" className={`flex size-6 shrink-0 items-center justify-center rounded-md ${categoryClasses[presentation.accent]}`}>
                    {def?.icon ? <span className="text-xs leading-none">{def.icon}</span> : <NodeflowIcon name={presentation.icon} className="size-3.5" />}
                </span>
                <span title={label} className="min-w-0 flex-1 truncate text-[13px] font-semibold leading-5 text-foreground">{label}</span>
                {isTrigger && <span className={`${badgeClass} bg-muted text-foreground`}>TRIGGER</span>}
                {(isTrigger || data.isStart) && <span className={`${badgeClass} border border-primary/40 bg-primary/10 text-foreground`}>START</span>}
                {errors.length > 0 && <span className={`${badgeClass} inline-flex items-center gap-0.5 bg-destructive/10 text-foreground`}><NodeflowIcon name="alert" className="size-3 text-destructive" />ISSUE</span>}
            </header>
            <Body data={data} def={def} selected={selected} errors={errors} />
            {decoration !== undefined && decoration.badges.length > 0 && (
                <ul data-testid={`nodeflow-badges-${id}`} className="flex flex-wrap gap-1 px-3 pb-2.5 text-[11px] text-foreground">
                    {decoration.badges.map((badge) => (
                        <li key={badge.key} className="rounded bg-muted px-1.5 py-px">
                            {badge.label} <span className="font-semibold tabular-nums">{badge.value}</span>
                        </li>
                    ))}
                </ul>
            )}
            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 px-3 pb-2.5 text-[11px] leading-4 text-destructive">
                    {errors.map((error) => <li key={error}>{error}</li>)}
                </ul>
            )}
            {outputs.length > 0 && (
                <div aria-label="Outputs" className="border-t border-border py-0.5">
                    {outputs.map((output) => (
                        <div key={output} data-output-row className="relative flex h-7 items-center justify-end px-3 pr-4 text-[11px] font-medium text-muted-foreground">
                            <span className="truncate">{output}</span>
                            <Handle
                                id={output}
                                type="source"
                                position={Position.Right}
                                isConnectable={isConnectable}
                                aria-label={`Output ${output}`}
                                style={{ top: '50%', transform: 'translate(50%, -50%)' }}
                                className="!size-3 !border-2 !border-card !bg-primary"
                            />
                        </div>
                    ))}
                </div>
            )}
        </article>
    )
}
