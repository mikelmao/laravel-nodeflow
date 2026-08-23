import type { ValidationIndicator } from './EditorToolbar'

export type CanvasHudProps = {
    nodeCount: number
    connectionCount: number
    validation: ValidationIndicator
}

function readiness(validation: ValidationIndicator): string {
    const count = validation.count ?? 0
    if (validation.status === 'unchecked') return 'Not validated'
    if (validation.status === 'checking') return 'Checking'
    if (validation.status === 'valid') return 'Ready to publish'
    if (validation.status === 'warning') return `Ready with ${count} warning${count === 1 ? '' : 's'}`
    if (validation.status === 'invalid') return `${count} issue${count === 1 ? '' : 's'}`
    return 'Validation failed'
}

/** Non-interactive canvas overlay, deliberately transparent to all pointer events. */
export function CanvasHud({ nodeCount, connectionCount, validation }: CanvasHudProps) {
    return <div role="status" aria-live="polite" className="pointer-events-none absolute bottom-3 left-3 z-10 flex gap-2 rounded-md border border-border bg-background/95 px-3 py-2 text-xs text-foreground shadow-sm">
        <span>{nodeCount} node{nodeCount === 1 ? '' : 's'}</span>
        <span>{connectionCount} connection{connectionCount === 1 ? '' : 's'}</span>
        <span>{readiness(validation)}</span>
    </div>
}
