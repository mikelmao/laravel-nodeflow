import type { ReactNode } from 'react'
import type { PublishIndicator, SaveIndicator, ValidationIndicator } from './EditorToolbar'

export type EditorNoticesProps = {
    save: SaveIndicator
    publish?: PublishIndicator
    validation?: ValidationIndicator
    structuralError?: string
    graphMessages?: string[]
    validationMessage?: string
    onKeepMine: () => void
    onUseTheirs: () => void
}

function Alert({ children }: { children: ReactNode }) {
    return <div role="alert" className="border-b border-destructive/30 bg-destructive/10 px-4 py-2 text-sm text-foreground [&_li]:list-inside [&_li]:list-disc">{children}</div>
}

/** Pure, persistent presentation of controller-owned notices. It makes no requests or effects. */
export function EditorNotices({ save, publish, validation, structuralError, graphMessages, validationMessage, onKeepMine, onUseTheirs }: EditorNoticesProps) {
    const graphFailure = graphMessages?.filter(Boolean) ?? []
    const validationFailed = validation?.status === 'failed'
    return <section aria-label="Workflow notices">
        {save.status === 'conflict' && <Alert><p>{save.message ?? 'This workflow changed elsewhere.'}</p><div className="mt-2 flex gap-2"><button type="button" onClick={onKeepMine} className="inline-flex h-8 items-center rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">Keep mine</button><button type="button" onClick={onUseTheirs} className="inline-flex h-8 items-center rounded-md border border-border bg-card px-3 text-sm font-medium text-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">Use theirs</button></div></Alert>}
        {save.status === 'error' && <Alert>{save.message ?? 'Could not save changes.'}</Alert>}
        {structuralError && <Alert>{structuralError}</Alert>}
        {graphFailure.length > 0 && <Alert><ul>{graphFailure.map((message, index) => <li key={`${message}-${index}`}>{message}</li>)}</ul></Alert>}
        {publish?.status === 'error' && <Alert>{publish.message ?? 'Could not publish this workflow.'}</Alert>}
        {validationFailed && <Alert>{validationMessage ?? 'Validation could not complete.'}</Alert>}
        {publish?.status === 'published' && <div role="status" className="border-b border-border bg-muted px-4 py-2 text-sm text-foreground">Published v{publish.version ?? ''}</div>}
    </section>
}
