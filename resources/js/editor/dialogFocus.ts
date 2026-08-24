import type { KeyboardEvent } from 'react'

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',')

/** Keep keyboard focus inside an aria-modal confirmation surface. */
export function containDialogFocus(event: KeyboardEvent<HTMLElement>): void {
    if (event.key !== 'Tab') return
    const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>(focusableSelector))
        .filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true')
    const first = focusable[0]
    const last = focusable.at(-1)
    if (first === undefined || last === undefined) return

    const activeElement = event.currentTarget.ownerDocument.activeElement
    if (event.shiftKey && activeElement === first) {
        event.preventDefault()
        last.focus()
    } else if (!event.shiftKey && activeElement === last) {
        event.preventDefault()
        first.focus()
    }
}
