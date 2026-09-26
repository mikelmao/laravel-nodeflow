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
    const tone = validation.status === 'invalid' || validation.status === 'failed' ? 'bg-destructive' : validation.status === 'valid' || validation.status === 'warning' ? 'bg-primary' : 'bg-muted-foreground'
    return <div role="status" aria-live="polite" className="pointer-events-none absolute left-14 top-3 z-10 flex h-8 items-center gap-2 rounded-md border border-border bg-card/95 px-2.5 text-xs text-muted-foreground shadow-sm">
        <span>{nodeCount} node{nodeCount === 1 ? '' : 's'}</span>
        <span aria-hidden="true">·</span>
        <span>{connectionCount} connection{connectionCount === 1 ? '' : 's'}</span>
        <span aria-hidden="true">·</span>
        <span className="inline-flex items-center gap-1.5 text-foreground"><span aria-hidden="true" className={`size-1.5 rounded-full ${tone}`} />{readiness(validation)}</span>
    </div>
}
