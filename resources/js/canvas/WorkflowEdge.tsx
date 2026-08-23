import { BaseEdge, EdgeLabelRenderer, getSmoothStepPath, type EdgeProps } from '@xyflow/react'

/** A readable route with the declared output shown just above its midpoint. */
export function WorkflowEdge({
    id,
    sourceX,
    sourceY,
    sourcePosition,
    targetX,
    targetY,
    targetPosition,
    label,
    style,
    markerStart,
    markerEnd,
    selected,
}: EdgeProps) {
    const [path, labelX, labelY] = getSmoothStepPath({
        sourceX,
        sourceY,
        sourcePosition,
        targetX,
        targetY,
        targetPosition,
        borderRadius: 10,
    })
    const labelText = typeof label === 'string' || typeof label === 'number' ? String(label) : ''

    return (
        <>
            <BaseEdge
                id={id}
                path={path}
                style={style}
                markerStart={markerStart}
                markerEnd={markerEnd}
                className={selected ? 'react-flow__edge-path stroke-primary' : 'react-flow__edge-path'}
            />
            {labelText !== '' && (
                <EdgeLabelRenderer>
                    <div
                        aria-label={`Connection output: ${labelText}`}
                        className="pointer-events-none nodrag nopan rounded border border-border bg-background px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground shadow-sm"
                        style={{ position: 'absolute', transform: `translate(-50%, -100%) translate(${labelX}px,${labelY - 10}px)` }}
                    >
                        {labelText}
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    )
}
