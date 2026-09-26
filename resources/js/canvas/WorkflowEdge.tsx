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
                className="react-flow__edge-path"
            />
            {labelText !== '' && (
                <EdgeLabelRenderer>
                    <div
                        aria-label={`Connection output: ${labelText}`}
                        className={`pointer-events-none nodrag nopan rounded border bg-card px-1.5 py-px text-[11px] font-medium leading-4 shadow-xs ${selected ? 'border-primary text-foreground' : 'border-border text-muted-foreground'}`}
                        style={{ position: 'absolute', transform: `translate(-50%, -100%) translate(${labelX}px,${labelY - 6}px)` }}
                    >
                        {labelText}
                    </div>
                </EdgeLabelRenderer>
            )}
        </>
    )
}
