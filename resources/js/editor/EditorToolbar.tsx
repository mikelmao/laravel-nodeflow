import { useEffect, useId, useRef, type KeyboardEvent, type ReactNode } from 'react'
import { NodeflowIcon } from '../presentation/icons'

export type SaveIndicator = {
    status: 'idle' | 'saving' | 'saved' | 'error' | 'conflict'
    message?: string
    /** An edit the server does not hold yet, for example one waiting for the autosave debounce. */
    unsaved?: boolean
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

type IconName = React.ComponentProps<typeof NodeflowIcon>['name']

function saveCopy(save: SaveIndicator): string {
    if (save.status === 'idle' && save.unsaved === true) return 'Unsaved changes'
    return ({ idle: 'Changes saved', saving: 'Saving changes', saved: 'Saved', error: 'Save failed', conflict: 'Save conflict' })[save.status]
}

function saveIcon(save: SaveIndicator): IconName {
    if (save.status === 'error' || save.status === 'conflict') return 'alert'
    if (save.status === 'saving' || save.unsaved === true) return 'pause'
    return 'check'
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

const focusRing = 'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-card'
const outlineButton = `inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-border bg-card px-2.5 text-sm font-medium text-foreground shadow-xs transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 ${focusRing}`
const iconButton = `inline-flex size-8 shrink-0 items-center justify-center rounded-md text-foreground transition-colors hover:bg-muted disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:bg-transparent ${focusRing}`

function IconAction({ label, icon, disabled, onClick }: { label: string; icon: IconName; disabled?: boolean; onClick: () => void }) {
    return <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick} className={iconButton}>
        <NodeflowIcon name={icon} className="size-4" />
    </button>
}

function MenuAction({ label, icon, disabled, onClick }: { label: string; icon: IconName; disabled?: boolean; onClick: () => void }) {
    return <button type="button" aria-label={`${label} (more actions)`} title={label} disabled={disabled} onClick={onClick} className={`flex h-8 w-full items-center gap-2 whitespace-nowrap rounded px-2 text-sm text-popover-foreground hover:bg-muted disabled:cursor-not-allowed disabled:opacity-50 ${focusRing}`}>
        <NodeflowIcon name={icon} className="size-4 text-muted-foreground" />
        <span>{label}</span>
    </button>
}

type SecondaryAction = { label: string; icon: IconName; disabled?: boolean; onClick: () => void }

function secondaryActions(props: EditorToolbarProps): SecondaryAction[][] {
    return [
        [
            { label: 'Undo', icon: 'undo', disabled: !props.canUndo, onClick: props.onUndo },
            { label: 'Redo', icon: 'redo', disabled: !props.canRedo, onClick: props.onRedo },
        ],
        [
            { label: 'Auto layout', icon: 'layout', onClick: props.onAutoLayout },
            { label: 'Fit canvas', icon: 'fit', onClick: props.onFit },
        ],
        props.hasSelection ? [{ label: 'Delete selected', icon: 'trash', onClick: props.onDeleteSelected }] : [],
    ]
}

/**
 * The narrow overflow menu. A bare details element stays open after an action
 * and on outside clicks, so it closes on both and on Escape.
 */
function OverflowMenu({ actions }: { actions: SecondaryAction[] }) {
    const details = useRef<HTMLDetailsElement>(null)

    useEffect(() => {
        const element = details.current
        if (element === null) return
        const onPointerDown = (event: PointerEvent) => {
            if (element.open && event.target instanceof Node && !element.contains(event.target)) element.open = false
        }
        element.ownerDocument.addEventListener('pointerdown', onPointerDown, true)
        return () => element.ownerDocument.removeEventListener('pointerdown', onPointerDown, true)
    }, [])

    function close(returnFocus: boolean) {
        const element = details.current
        if (element === null) return
        element.open = false
        if (returnFocus) element.querySelector('summary')?.focus()
    }

    function onKeyDown(event: KeyboardEvent<HTMLDetailsElement>) {
        if (event.key !== 'Escape' || details.current?.open !== true) return
        event.preventDefault()
        event.stopPropagation()
        close(true)
    }

    return <details ref={details} className="relative" onKeyDown={onKeyDown}>
        <summary aria-label="More workflow actions" title="More workflow actions" className={`flex size-8 cursor-pointer list-none items-center justify-center rounded-md border border-border bg-card text-foreground hover:bg-muted [&::-webkit-details-marker]:hidden ${focusRing}`}>
            <NodeflowIcon name="more" className="size-4" />
        </summary>
        <div className="absolute right-0 top-full z-20 mt-1 flex min-w-44 flex-col gap-0.5 rounded-md border border-border bg-popover p-1 shadow-md">
            {actions.map((action) => <MenuAction key={action.label} {...action} onClick={() => { close(false); action.onClick() }} />)}
        </div>
    </details>
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
    const saveTone = props.save.status === 'error' || props.save.status === 'conflict' ? 'text-destructive' : 'text-muted-foreground'
    const validationTone = props.validation.status === 'invalid' || props.validation.status === 'failed'
        ? 'bg-destructive/10 text-foreground'
        : 'bg-muted text-foreground'
    const groups = secondaryActions(props).filter((group) => group.length > 0)

    return <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-border bg-card px-3 py-2 text-card-foreground sm:px-4">
        {props.slots?.leading}
        <div className="min-w-0 grow basis-40">
            <h1 title={props.flowName} className="truncate text-[15px] font-semibold leading-5">{props.flowName}</h1>
            <p className="flex min-w-0 gap-1 truncate text-xs leading-4 text-muted-foreground"><span className="truncate">Trigger: {props.triggerLabel}</span><span aria-hidden="true">·</span><span className="shrink-0">{publishedContext}</span></p>
        </div>
        <div className="flex items-center gap-1" aria-label="Workflow editing actions" role="group">
            <div className="hidden items-center gap-1 lg:flex">
                {groups.map((group, index) => <div key={group[0]!.label} className={`flex items-center gap-0.5${index > 0 ? ' border-l border-border pl-1' : ''}`}>
                    {group.map((action) => <IconAction key={action.label} {...action} />)}
                </div>)}
            </div>
            <div className="lg:hidden" aria-label="More workflow actions" role="group">
                <OverflowMenu actions={groups.flat()} />
            </div>
        </div>
        <div className="flex items-center gap-2" aria-label="Workflow persistence actions" role="group">
            <span role="status" aria-live="polite" aria-label={`Save status: ${saveText}`} title={props.save.message ?? saveText} className={`inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium ${saveTone}`}>
                <NodeflowIcon name={saveIcon(props.save)} className="size-3.5" />
                <span className="hidden sm:inline">{saveText}</span>
            </span>
            <button type="button" aria-label="Validate flow" title={validationText} disabled={props.validation.status === 'checking'} onClick={props.onValidate} className={outlineButton}>
                <NodeflowIcon name={props.validation.status === 'invalid' || props.validation.status === 'failed' ? 'alert' : 'check'} className={`size-4${props.validation.status === 'invalid' || props.validation.status === 'failed' ? ' text-destructive' : ''}`} />
                <span>Validate</span>
                <span className={`hidden rounded px-1.5 py-px text-[11px] font-medium leading-4 xl:inline ${validationTone}`}>{validationText}</span>
            </button>
            <span id={publishDescriptionId} role="status" aria-live="polite" aria-label="Publish readiness" className="sr-only">{publishDescription}</span>
            <button type="button" aria-label="Publish" aria-describedby={publishDescriptionId} aria-busy={props.credentialBusy ?? props.publish.status === 'publishing'} title={props.publishDisabledReason ?? props.publish.message ?? publishText} disabled={props.publish.status === 'publishing' || props.publishDisabledReason != null} onClick={props.onPublish} className={`inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md bg-primary px-3 text-sm font-medium text-primary-foreground shadow-xs transition-colors hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50 ${focusRing}`}>
                <NodeflowIcon name={props.publish.status === 'error' ? 'alert' : 'play'} className="size-4" />
                <span>{publishText}</span>
            </button>
        </div>
        {props.slots?.trailing}
    </header>
}
