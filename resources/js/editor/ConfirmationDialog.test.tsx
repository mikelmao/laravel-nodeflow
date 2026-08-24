import { StrictMode, useRef, useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { ConfirmationDialog } from './ConfirmationDialog'

function Harness({ second = false }: { second?: boolean }) {
    const [firstOpen, setFirstOpen] = useState(true)
    const [secondOpen, setSecondOpen] = useState(second)
    const firstOpener = useRef<HTMLButtonElement>(null)
    const secondOpener = useRef<HTMLButtonElement>(null)
    return <>
        <section data-testid="background" data-nodeflow-editor-root aria-hidden="false">
            <button ref={firstOpener} type="button">First opener</button>
            <button ref={secondOpener} type="button">Second opener</button>
        </section>
        <ConfirmationDialog
            open={firstOpen}
            title="First confirmation"
            description="First description"
            confirmLabel="Confirm first"
            openerRef={firstOpener}
            onCancel={() => setFirstOpen(false)}
            onConfirm={() => setFirstOpen(false)}
        />
        <ConfirmationDialog
            open={secondOpen}
            title="Second confirmation"
            description="Second description"
            confirmLabel="Confirm second"
            openerRef={secondOpener}
            onCancel={() => setSecondOpen(false)}
            onConfirm={() => setSecondOpen(false)}
        />
    </>
}

describe('ConfirmationDialog', () => {
    it('reference-counts background isolation and restores original attributes', () => {
        render(<Harness second />)
        const background = screen.getByTestId('background')
        expect(background).toHaveAttribute('inert')
        expect(background).toHaveAttribute('aria-hidden', 'true')

        fireEvent.keyDown(screen.getByRole('dialog', { name: 'Second confirmation' }), { key: 'Escape' })
        expect(background).toHaveAttribute('inert')

        fireEvent.keyDown(screen.getByRole('dialog', { name: 'First confirmation' }), { key: 'Escape' })
        expect(background).not.toHaveAttribute('inert')
        expect(background).toHaveAttribute('aria-hidden', 'false')
    })

    it('restores background isolation when StrictMode unmounts an open dialog', () => {
        const view = render(<StrictMode><Harness /></StrictMode>)
        const background = screen.getByTestId('background')
        expect(background).toHaveAttribute('inert')

        view.unmount()

        expect(document.querySelector('[inert]')).toBeNull()
    })
})
