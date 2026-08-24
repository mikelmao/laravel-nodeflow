import { createPortal } from 'react-dom'
import { useEffect, useId, useRef, type RefObject } from 'react'
import type { KeyboardEvent as ReactKeyboardEvent } from 'react'
import { containDialogFocus } from './dialogFocus'

type IsolationSnapshot = { inert: string | null; ariaHidden: string | null }
type ModalEntry = { token: symbol; root: HTMLElement; cancel: () => void }
type ModalRegistry = {
    entries: ModalEntry[]
    isolated: Map<HTMLElement, IsolationSnapshot>
    removeListeners: (() => void) | null
}

const registries = new WeakMap<Document, ModalRegistry>()
const focusableSelector = 'button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function registryFor(document: Document): ModalRegistry {
    let registry = registries.get(document)
    if (registry === undefined) {
        registry = { entries: [], isolated: new Map(), removeListeners: null }
        registries.set(document, registry)
    }
    return registry
}

function focusFirst(root: HTMLElement): void {
    ;(root.querySelector<HTMLElement>('[data-nodeflow-dialog-initial-focus]')
        ?? root.querySelector<HTMLElement>(focusableSelector)
        ?? root).focus({ preventScroll: true })
}

function restore(element: HTMLElement, snapshot: IsolationSnapshot): void {
    if (snapshot.inert === null) element.removeAttribute('inert')
    else element.setAttribute('inert', snapshot.inert)
    if (snapshot.ariaHidden === null) element.removeAttribute('aria-hidden')
    else element.setAttribute('aria-hidden', snapshot.ariaHidden)
}

function refreshIsolation(document: Document, registry: ModalRegistry): void {
    const top = registry.entries.at(-1)?.root ?? null
    const candidates = new Set(document.querySelectorAll<HTMLElement>('[data-nodeflow-editor-root], [data-nodeflow-modal-root]'))
    for (const element of candidates) {
        const isolate = element.hasAttribute('data-nodeflow-editor-root') || element !== top
        if (isolate && !registry.isolated.has(element)) {
            registry.isolated.set(element, {
                inert: element.getAttribute('inert'),
                ariaHidden: element.getAttribute('aria-hidden'),
            })
            element.setAttribute('inert', '')
            element.setAttribute('aria-hidden', 'true')
        } else if (!isolate) {
            const snapshot = registry.isolated.get(element)
            if (snapshot !== undefined) {
                restore(element, snapshot)
                registry.isolated.delete(element)
            }
        }
    }
    for (const [element, snapshot] of registry.isolated) {
        if (candidates.has(element)) continue
        restore(element, snapshot)
        registry.isolated.delete(element)
    }
}

function installListeners(document: Document, registry: ModalRegistry): () => void {
    const keydown = (event: KeyboardEvent) => {
        const top = registry.entries.at(-1)
        if (top === undefined || (event.target instanceof Node && top.root.contains(event.target))) return
        event.preventDefault()
        event.stopImmediatePropagation()
        if (event.key === 'Escape') top.cancel()
    }
    const blockBackground = (event: Event) => {
        const top = registry.entries.at(-1)
        if (top === undefined || (event.target instanceof Node && top.root.contains(event.target))) return
        event.preventDefault()
        event.stopImmediatePropagation()
    }
    const containFocus = (event: FocusEvent) => {
        const top = registry.entries.at(-1)
        if (top === undefined || (event.target instanceof Node && top.root.contains(event.target))) return
        event.preventDefault()
        event.stopImmediatePropagation()
        focusFirst(top.root)
    }
    document.addEventListener('keydown', keydown, true)
    document.addEventListener('pointerdown', blockBackground, true)
    document.addEventListener('click', blockBackground, true)
    document.addEventListener('focusin', containFocus, true)
    return () => {
        document.removeEventListener('keydown', keydown, true)
        document.removeEventListener('pointerdown', blockBackground, true)
        document.removeEventListener('click', blockBackground, true)
        document.removeEventListener('focusin', containFocus, true)
    }
}

function registerModal(document: Document, entry: ModalEntry): () => HTMLElement | null {
    const registry = registryFor(document)
    registry.entries.push(entry)
    registry.removeListeners ??= installListeners(document, registry)
    refreshIsolation(document, registry)
    focusFirst(entry.root)
    return () => {
        registry.entries = registry.entries.filter((candidate) => candidate.token !== entry.token)
        refreshIsolation(document, registry)
        const remaining = registry.entries.at(-1)?.root ?? null
        if (registry.entries.length === 0) {
            registry.removeListeners?.()
            registry.removeListeners = null
            for (const [element, snapshot] of registry.isolated) restore(element, snapshot)
            registry.isolated.clear()
            registries.delete(document)
        }
        return remaining
    }
}

export type ConfirmationDialogProps = {
    open: boolean
    title: string
    description: string
    confirmLabel: string
    openerRef: RefObject<HTMLElement | null>
    onCancel: () => void
    onConfirm: () => void
    destructive?: boolean
}

/** Shared confirmation surface with real modal isolation, focus ownership, and shortcut containment. */
export function ConfirmationDialog({ open, title, description, confirmLabel, openerRef, onCancel, onConfirm, destructive = false }: ConfirmationDialogProps) {
    const rootRef = useRef<HTMLDivElement>(null)
    const cancelRef = useRef(onCancel)
    cancelRef.current = onCancel
    const titleId = `nodeflow-confirmation-${useId().replace(/:/g, '')}`
    const descriptionId = `${titleId}-description`

    useEffect(() => {
        const root = rootRef.current
        if (!open || root === null) return
        const release = registerModal(root.ownerDocument, { token: Symbol('nodeflow-modal'), root, cancel: () => cancelRef.current() })
        return () => {
            const remaining = release()
            if (remaining !== null) focusFirst(remaining)
            else openerRef.current?.focus({ preventScroll: true })
        }
    }, [open, openerRef])

    if (!open || typeof document === 'undefined') return null

    function handleKeyDown(event: ReactKeyboardEvent<HTMLDivElement>): void {
        event.stopPropagation()
        containDialogFocus(event)
        if (event.key !== 'Escape') return
        event.preventDefault()
        onCancel()
    }

    return createPortal(
        <div ref={rootRef} data-nodeflow-modal-root role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={descriptionId} onKeyDown={handleKeyDown} className="fixed inset-0 z-50 grid place-items-center bg-background/70 p-4">
            <div className="w-full max-w-md space-y-4 rounded-lg border border-border bg-card p-5 shadow-lg">
                <h3 id={titleId} className="text-base font-semibold">{title}</h3>
                <p id={descriptionId} className="text-sm text-muted-foreground">{description}</p>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="rounded-md border border-border px-3 py-2 text-sm">Cancel</button>
                    <button type="button" autoFocus data-nodeflow-dialog-initial-focus onClick={onConfirm} className={`rounded-md px-3 py-2 text-sm ${destructive ? 'bg-destructive text-destructive-foreground' : 'bg-primary text-primary-foreground'}`}>{confirmLabel}</button>
                </div>
            </div>
        </div>,
        document.body,
    )
}
