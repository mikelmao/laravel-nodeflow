import { useId, type ReactNode } from 'react'
import { NodeflowIcon } from '../presentation/icons'

export type SaveIndicator = {
    status: 'idle' | 'saving' | 'saved' | 'error' | 'conflict'
    message?: string
}

export type ValidationIndicator = {
    status: 'unchecked' | 'checking' | 'valid' | 'warning' | 'invalid' | 'failed'
    count?: number
}

export type PublishIndicator = {
    status: 'idle' | 'publishing' | 'published' | 'error'
    message?: string
    version?: number
}

export type EditorToolbarProps = {
    flowName: string
    triggerLabel: string
    publishedVersion: number | null
    save: SaveIndicator
    validation: ValidationIndicator
    publish: PublishIndicator
    publishDisabledReason?: string | null
    credentialBusy?: boolean
    canUndo: boolean
    canRedo: boolean
    hasSelection: boolean
    onUndo: () => void
    onRedo: () => void
    onAutoLayout: () => void
    onFit: () => void
    onDeleteSelected: () => void
    onValidate: () => void
    onPublish: () => void
    slots?: { leading?: ReactNode; trailing?: ReactNode }
}

function saveCopy(save: SaveIndicator): string {
    return ({ idle: 'Changes saved', saving: 'Saving changes', saved: 'Saved', error: 'Save failed', conflict: 'Save conflict' })[save.status]
}

function validationCopy(validation: ValidationIndicator): string {
    const count = validation.count ?? 0
    if (validation.status === 'unchecked') return 'Not validated'
    if (validation.status === 'checking') return 'Checking'
    if (validation.status === 'valid') return 'Ready to publish'
    if (validation.status === 'warning') return `Ready with ${count} warning${count === 1 ? '' : 's'}`
    if (validation.status === 'invalid') return `${count} issue${count === 1 ? '' : 's'}`
    return 'Validation failed'
}

function ActionButton({ label, title, icon, disabled, onClick }: {
    label: string
    title?: string
    icon: React.ComponentProps<typeof NodeflowIcon>['name']
    disabled?: boolean
    onClick: () => void
}) {
    return <button type="button" aria-label={label} title={title ?? label} disabled={disabled} onClick={onClick} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-sm font-medium hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
        <NodeflowIcon name={icon} className="size-4" />
        <span>{label}</span>
    </button>
}

function SecondaryActions({ props, compact = false }: { props: EditorToolbarProps; compact?: boolean }) {
    const suffix = compact ? ' (more actions)' : ''
    return <>
        <ActionButton label={`Undo${suffix}`} icon="undo" disabled={!props.canUndo} onClick={props.onUndo} />
        <ActionButton label={`Redo${suffix}`} icon="redo" disabled={!props.canRedo} onClick={props.onRedo} />
        <ActionButton label={`Auto layout${suffix}`} icon="layout" onClick={props.onAutoLayout} />
        <ActionButton label={`Fit canvas${suffix}`} icon="info" onClick={props.onFit} />
        {props.hasSelection && <ActionButton label={`Delete selected${suffix}`} icon="trash" onClick={props.onDeleteSelected} />}
    </>
}

/** Package-owned workflow context and command controls; server/controller state stays outside. */
export function EditorToolbar(props: EditorToolbarProps) {
    const publishDescriptionId = `nodeflow-publish-description-${useId().replace(/:/g, '')}`
    const saveText = saveCopy(props.save)
    const validationText = validationCopy(props.validation)
    const publishedContext = props.publishedVersion === null ? 'Not published' : `Published v${props.publishedVersion}`
    const publishText = props.publish.status === 'published'
        ? `Published v${props.publish.version ?? props.publishedVersion ?? ''}`.trim()
        : props.publish.status === 'publishing' ? 'Publishing' : 'Publish'
    const publishDescription = props.publishDisabledReason
        ?? (props.publish.status === 'publishing' ? 'Publishing is in progress.' : 'Flow is ready to publish.')

    return <header className="flex flex-wrap items-center gap-3 border-b border-border bg-background px-4 py-3 text-foreground">
        {props.slots?.leading}
        <div className="min-w-0 grow">
            <h1 className="truncate text-base font-semibold">{props.flowName}</h1>
            <p className="flex gap-1 text-xs text-muted-foreground"><span>Trigger: {props.triggerLabel}</span><span>·</span><span>{publishedContext}</span></p>
        </div>
        <div className="flex items-center gap-2" aria-label="Workflow editing actions" role="group">
            <div className="hidden items-center gap-2 lg:flex">
                <SecondaryActions props={props} />
            </div>
            <div className="lg:hidden" aria-label="More workflow actions" role="group">
                <details className="relative">
                    <summary className="cursor-pointer rounded-md border border-border px-2.5 py-1.5 text-sm font-medium">More workflow actions</summary>
                    <div className="absolute right-0 top-full z-20 mt-1 flex flex-col gap-1 rounded-md border border-border bg-popover p-2 shadow-md">
                        <SecondaryActions props={props} compact />
                    </div>
                </details>
            </div>
        </div>
        <div className="flex items-center gap-2" aria-label="Workflow persistence actions" role="group">
            <span role="status" aria-live="polite" aria-label={`Save status: ${saveText}`} title={props.save.message ?? saveText} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-sm font-medium">
                <NodeflowIcon name={props.save.status === 'error' || props.save.status === 'conflict' ? 'alert' : 'check'} className="size-4" />
                <span>{saveText}</span>
            </span>
            <button type="button" aria-label="Validate flow" title={validationText} disabled={props.validation.status === 'checking'} onClick={props.onValidate} className="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1.5 text-sm font-medium hover:bg-muted disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <NodeflowIcon name={props.validation.status === 'invalid' || props.validation.status === 'failed' ? 'alert' : 'check'} className="size-4" />
                <span>Validate</span>
                <span className="text-xs">{validationText}</span>
            </button>
            <span id={publishDescriptionId} role="status" aria-live="polite" aria-label="Publish readiness" className="sr-only">{publishDescription}</span>
            <button type="button" aria-label="Publish" aria-describedby={publishDescriptionId} aria-busy={props.credentialBusy ?? props.publish.status === 'publishing'} title={props.publishDisabledReason ?? props.publish.message ?? publishText} disabled={props.publish.status === 'publishing' || props.publishDisabledReason != null} onClick={props.onPublish} className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <NodeflowIcon name={props.publish.status === 'error' ? 'alert' : 'play'} className="size-4" />
                <span>{publishText}</span>
            </button>
        </div>
        {props.slots?.trailing}
    </header>
}
