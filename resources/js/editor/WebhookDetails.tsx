import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import type { WebhookMetadata } from '../graph/types'
import { NodeflowIcon } from '../presentation/icons'
import { ConfirmationDialog } from './ConfirmationDialog'

export type WebhookDetailsProps = {
    metadata: WebhookMetadata | null
    oneTimeSecret: string | null
    publishing?: boolean
    rotating: boolean
    rotationError: string | null
    onAcknowledgeSecret: () => void
    onRotate: () => void
}

/** Secret-aware webhook UI. Plaintext exists only in the parent/component React state. */
export function WebhookDetails({
    metadata,
    oneTimeSecret,
    publishing = false,
    rotating,
    rotationError,
    onAcknowledgeSecret,
    onRotate,
}: WebhookDetailsProps) {
    const [confirming, setConfirming] = useState(false)
    const [endpointCopy, setEndpointCopy] = useState<'copied' | 'failed' | null>(null)
    const [secretCopy, setSecretCopy] = useState<'copied' | 'failed' | null>(null)
    const rotationDescriptionId = `nodeflow-rotate-description-${useId().replace(/:/g, '')}`
    const rotateButton = useRef<HTMLButtonElement>(null)
    const mounted = useRef(true)
    const secretIdentity = useRef(oneTimeSecret)
    const secretGeneration = useRef(0)
    const secretCopyRequest = useRef(0)
    const endpointIdentity = useRef(metadata?.endpoint_url ?? null)
    const endpointGeneration = useRef(0)
    const endpointCopyRequest = useRef(0)
    const rotationDescription = rotating
        ? 'Webhook secret rotation is in progress.'
        : publishing ? 'Publishing is in progress.' : 'Rotate the webhook signing secret.'

    useEffect(() => {
        mounted.current = true
        return () => {
            mounted.current = false
            secretCopyRequest.current += 1
            endpointCopyRequest.current += 1
        }
    }, [])

    useLayoutEffect(() => {
        if (secretIdentity.current === oneTimeSecret) return
        secretIdentity.current = oneTimeSecret
        secretGeneration.current += 1
        secretCopyRequest.current += 1
        setSecretCopy(null)
    }, [oneTimeSecret])

    useLayoutEffect(() => {
        const endpoint = metadata?.endpoint_url ?? null
        if (endpointIdentity.current === endpoint) return
        endpointIdentity.current = endpoint
        endpointGeneration.current += 1
        endpointCopyRequest.current += 1
        setEndpointCopy(null)
    }, [metadata?.endpoint_url])

    async function copyEndpoint(): Promise<void> {
        const value = metadata?.endpoint_url
        if (value === null || value === undefined) return
        const generation = endpointGeneration.current
        const request = ++endpointCopyRequest.current
        let result: 'copied' | 'failed'
        try {
            await navigator.clipboard.writeText(value)
            result = 'copied'
        } catch {
            result = 'failed'
        }
        if (!mounted.current || request !== endpointCopyRequest.current || generation !== endpointGeneration.current || endpointIdentity.current !== value) return
        setEndpointCopy(result)
    }

    async function copySecret(): Promise<void> {
        const value = oneTimeSecret
        if (value === null) return
        const generation = secretGeneration.current
        const request = ++secretCopyRequest.current
        let result: 'copied' | 'failed'
        try {
            await navigator.clipboard.writeText(value)
            result = 'copied'
        } catch {
            result = 'failed'
        }
        if (!mounted.current || request !== secretCopyRequest.current || generation !== secretGeneration.current || secretIdentity.current !== value) return
        setSecretCopy(result)
    }

    function acknowledgeSecret(): void {
        secretGeneration.current += 1
        secretCopyRequest.current += 1
        secretIdentity.current = null
        setSecretCopy(null)
        onAcknowledgeSecret()
    }

    function closeConfirmation(): void {
        setConfirming(false)
    }

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
                                    <span aria-label="Webhook endpoint" className="min-w-0 truncate font-mono text-xs">{metadata.endpoint_url}</span>
                                    <button type="button" aria-label="Copy webhook endpoint" onClick={() => { void copyEndpoint() }} className="rounded border border-border p-1"><NodeflowIcon name="copy" className="size-3.5" /></button>
                                </>}
                        </dd>
                    </div>
                    <div><dt className="text-xs font-medium text-muted-foreground">Status</dt><dd>{metadata.active ? 'Active' : 'Inactive'}</dd></div>
                    <div><dt className="text-xs font-medium text-muted-foreground">Secret last rotated</dt><dd>{metadata.secret_rotated_at ?? 'Never'}</dd></div>
                </dl>
            )}
            {endpointCopy === 'copied' && <p role="status">Endpoint copied.</p>}
            {endpointCopy === 'failed' && <p role="alert" aria-label="Webhook endpoint copy status">Could not copy the webhook endpoint. Copy it manually.</p>}
            {oneTimeSecret !== null && (
                <div role="alert" className="space-y-2 rounded-md border border-amber-500/50 bg-amber-500/10 p-3">
                    <p className="font-semibold">Save this signing secret now. It is shown only once.</p>
                    <code className="block break-all select-all rounded bg-background p-2">{oneTimeSecret}</code>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" aria-label="Copy webhook secret" onClick={() => { void copySecret() }} className="rounded-md border border-border px-2 py-1">Copy</button>
                        <button type="button" aria-label="Acknowledge webhook secret" onClick={acknowledgeSecret} className="rounded-md bg-primary px-2 py-1 text-primary-foreground">Acknowledge</button>
                    </div>
                    {secretCopy === 'copied' && <p role="status">Secret copied.</p>}
                    {secretCopy === 'failed' && <p role="alert" aria-label="Webhook secret copy status">Could not copy the webhook secret. Copy it manually.</p>}
                </div>
            )}
            {metadata !== null && (
                <button ref={rotateButton} type="button" aria-label="Rotate webhook secret" aria-describedby={rotationDescriptionId} aria-busy={rotating || publishing} disabled={rotating || publishing} onClick={() => setConfirming(true)} className="w-full rounded-md border border-border px-3 py-2 font-medium disabled:opacity-50">
                    {rotating ? 'Rotating secret' : 'Rotate secret'}
                </button>
            )}
            <span id={rotationDescriptionId} role="status" aria-live="polite" aria-label="Webhook credential operation" className="sr-only">{rotationDescription}</span>
            {rotationError !== null && <p role="alert" aria-label="Webhook rotation error" className="text-destructive">{rotationError}</p>}
            <ConfirmationDialog
                open={confirming}
                title="Rotate webhook secret"
                description="The current signing secret stops working immediately. Update the webhook sender before its next request."
                confirmLabel="Confirm rotation"
                openerRef={rotateButton}
                destructive
                onCancel={closeConfirmation}
                onConfirm={() => { closeConfirmation(); onRotate() }}
            />
        </section>
    )
}
