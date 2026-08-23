import type { ReactNode } from 'react'
import type { PublishIndicator, SaveIndicator, ValidationIndicator } from './EditorToolbar'

export type EditorNoticesProps = {
    save: SaveIndicator
    publish?: PublishIndicator
    validation?: ValidationIndicator
    structuralError?: string
    graphMessages?: string[]
    validationMessage?: string
    onKeepMine?: () => void
    onUseTheirs?: () => void
}

function Alert({ children }: { children: ReactNode }) {
    return <div role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{children}</div>
}

/** Pure, persistent presentation of controller-owned notices. It makes no requests or effects. */
export function EditorNotices({ save, publish, validation, structuralError, graphMessages, validationMessage, onKeepMine, onUseTheirs }: EditorNoticesProps) {
    const graphFailure = graphMessages?.filter(Boolean) ?? []
    const validationFailed = validation?.status === 'failed'
    return <section aria-label="Workflow notices" className="space-y-2">
        {save.status === 'conflict' && <Alert><p>{save.message ?? 'This workflow changed elsewhere.'}</p><div className="mt-2 flex gap-2"><button type="button" onClick={onKeepMine} className="rounded border border-current px-2 py-1">Keep mine</button><button type="button" onClick={onUseTheirs} className="rounded border border-current px-2 py-1">Use theirs</button></div></Alert>}
        {save.status === 'error' && <Alert>{save.message ?? 'Could not save changes.'}</Alert>}
        {structuralError && <Alert>{structuralError}</Alert>}
        {graphFailure.length > 0 && <Alert><ul>{graphFailure.map((message, index) => <li key={`${message}-${index}`}>{message}</li>)}</ul></Alert>}
        {publish?.status === 'error' && <Alert>{publish.message ?? 'Could not publish this workflow.'}</Alert>}
        {validationFailed && <Alert>{validationMessage ?? 'Validation could not complete.'}</Alert>}
        {publish?.status === 'published' && <div role="status" className="rounded-md border border-border bg-muted px-3 py-2 text-sm">Published v{publish.version ?? ''}</div>}
    </section>
}
