import { useEffect, useId, useRef, useState } from 'react'
import type { WebhookMetadata } from '../graph/types'
import { NodeflowIcon } from '../presentation/icons'
import { containDialogFocus } from './dialogFocus'

export type WebhookDetailsProps = {
    metadata: WebhookMetadata | null
    oneTimeSecret: string | null
    rotating: boolean
    rotationError: string | null
    onAcknowledgeSecret: () => void
    onRotate: () => void
}

async function copyText(value: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(value)
        return true
    } catch {
        return false
    }
}

/** Secret-aware webhook UI. Plaintext exists only in the parent/component React state. */
export function WebhookDetails({
    metadata,
    oneTimeSecret,
    rotating,
    rotationError,
    onAcknowledgeSecret,
    onRotate,
}: WebhookDetailsProps) {
    const [confirming, setConfirming] = useState(false)
    const [copied, setCopied] = useState<'endpoint' | 'secret' | null>(null)
    const titleId = `nodeflow-rotate-webhook-${useId().replace(/:/g, '')}`
    const rotateButton = useRef<HTMLButtonElement>(null)

    useEffect(() => setCopied(null), [oneTimeSecret, metadata?.endpoint_url])

    function closeConfirmation(): void {
        setConfirming(false)
        rotateButton.current?.focus()
    }

    useEffect(() => {
        if (!confirming) return
        const close = (event: KeyboardEvent) => {
            if (event.key === 'Escape') closeConfirmation()
        }
        document.addEventListener('keydown', close)
        return () => document.removeEventListener('keydown', close)
    }, [confirming])

    if (metadata === null && oneTimeSecret === null) return null

    return (
        <section aria-label="Webhook details" className="space-y-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Webhook</h3>
            {metadata !== null && (
                <dl className="space-y-2">
                    <div>
                        <dt className="text-xs font-medium text-muted-foreground">Endpoint</dt>
                        <dd className="flex min-w-0 items-center gap-2">
                            {metadata.endpoint_url === null
                                ? <span>Endpoint URL unavailable</span>
                                : <>
                                    <a aria-label="Webhook endpoint" href={metadata.endpoint_url} target="_blank" rel="noreferrer" className="min-w-0 truncate font-mono text-xs underline">{metadata.endpoint_url}</a>
                                    <button type="button" aria-label="Copy webhook endpoint" onClick={() => { void copyText(metadata.endpoint_url!).then((ok) => setCopied(ok ? 'endpoint' : null)) }} className="rounded border border-border p-1"><NodeflowIcon name="copy" className="size-3.5" /></button>
                                </>}
                        </dd>
                    </div>
                    <div><dt className="text-xs font-medium text-muted-foreground">Status</dt><dd>{metadata.active ? 'Active' : 'Inactive'}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">Secret last rotated</dt><dd>{metadata.secret_rotated_at ?? 'Never'}</dd></div>
                </dl>
            )}
            {copied === 'endpoint' && <p role="status">Endpoint copied.</p>}
            {oneTimeSecret !== null && (
                <div role="alert" className="space-y-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3">
                    <p className="font-semibold">Save this signing secret now. It is shown only once.</p>
                    <code className="block break-all select-all rounded bg-background p-2">{oneTimeSecret}</code>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" aria-label="Copy webhook secret" onClick={() => { void copyText(oneTimeSecret).then((ok) => setCopied(ok ? 'secret' : null)) }} className="rounded-md border border-border px-2 py-1">Copy</button>
                        <button type="button" aria-label="Acknowledge webhook secret" onClick={onAcknowledgeSecret} className="rounded-md bg-primary px-2 py-1 text-primary-foreground">Acknowledge</button>
                    </div>
                    {copied === 'secret' && <p role="status">Secret copied.</p>}
                </div>
            )}
            {metadata !== null && (
                <button ref={rotateButton} type="button" aria-label="Rotate webhook secret" disabled={rotating} onClick={() => setConfirming(true)} className="w-full rounded-md border border-border px-3 py-2 font-medium disabled:opacity-50">
                    {rotating ? 'Rotating secret' : 'Rotate secret'}
                </button>
            )}
            {rotationError !== null && <p role="alert" aria-label="Webhook rotation error" className="text-destructive">{rotationError}</p>}
            {confirming && (
                <div role="dialog" aria-modal="true" aria-labelledby={titleId} onKeyDown={containDialogFocus} className="fixed inset-0 z-50 grid place-items-center bg-background/70 p-4">
                    <div className="w-full max-w-md space-y-4 rounded-lg border border-border bg-card p-5 shadow-lg">
                        <h3 id={titleId} className="text-base font-semibold">Rotate webhook secret</h3>
                        <p className="text-sm text-muted-foreground">The current signing secret stops working immediately. Update the webhook sender before its next request.</p>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={closeConfirmation} className="rounded-md border border-border px-3 py-2">Cancel</button>
                            <button type="button" autoFocus onClick={() => { closeConfirmation(); onRotate() }} className="rounded-md bg-destructive px-3 py-2 text-destructive-foreground">Confirm rotation</button>
                        </div>
                    </div>
                </div>
            )}
        </section>
    )
}
