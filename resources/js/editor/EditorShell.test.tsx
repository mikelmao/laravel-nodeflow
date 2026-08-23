import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { EditorShell } from './EditorShell'

function shell(overrides: Partial<React.ComponentProps<typeof EditorShell>> = {}) {
    const props: React.ComponentProps<typeof EditorShell> = {
        mode: 'workspace',
        toolbar: <div>Toolbar region</div>,
        library: <div>Library region<button type="button">Library action</button></div>,
        canvas: <div>Canvas region</div>,
        inspector: <div>Inspector region</div>,
        notices: <div>Notices region</div>,
        libraryOpen: false,
        inspectorOpen: false,
        onLibraryOpenChange: vi.fn(),
        onInspectorOpenChange: vi.fn(),
        ...overrides,
    }
    return { props, ...render(<EditorShell {...props} />) }
}

function installMediaQuery(initiallyNarrow: boolean) {
    let matches = initiallyNarrow
    const listeners = new Set<(event: MediaQueryListEvent) => void>()
    const mediaQuery = {
        get matches() { return matches },
        media: '(max-width: 1023px)',
        onchange: null,
        addListener: (listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
        removeListener: (listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
        addEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
        removeEventListener: (_type: string, listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
        dispatchEvent: () => true,
    }
    vi.stubGlobal('matchMedia', vi.fn(() => mediaQuery))
    return {
        setNarrow(next: boolean) {
            matches = next
            listeners.forEach((listener) => listener({ matches: next } as MediaQueryListEvent))
        },
    }
}

afterEach(() => vi.unstubAllGlobals())

describe('EditorShell', () => {
    it('uses its exact workspace or embedded shell classes while preserving final className', () => {
        const { props, rerender } = shell({ className: 'host-frame' })
        expect(screen.getByTestId('editor-shell')).toHaveClass('h-dvh', 'min-h-[42rem]', 'overflow-hidden', 'host-frame')
        rerender(<EditorShell {...props} mode="embedded" className="host-frame" />)
        expect(screen.getByTestId('editor-shell')).toHaveClass('min-h-[42rem]', 'overflow-hidden', 'rounded-xl', 'border', 'bg-background', 'host-frame')
    })

    it('keeps one DOM instance of every supplied region', () => {
        shell()
        expect(screen.getAllByText('Toolbar region')).toHaveLength(1)
        expect(screen.getAllByText('Library region')).toHaveLength(1)
        expect(screen.getAllByText('Canvas region')).toHaveLength(1)
        expect(screen.getAllByText('Inspector region')).toHaveLength(1)
        expect(screen.getAllByText('Notices region')).toHaveLength(1)
    })

    it('uses non-overlapping five-track desktop layout and flexes only its body below natural chrome', () => {
        shell()
        const root = screen.getByTestId('editor-shell')
        const body = root.querySelector('[data-nodeflow-shell-body]')
        const main = root.querySelector('main')
        const libraryResize = screen.getByRole('separator', { name: 'Resize Node Library' })
        const inspectorResize = screen.getByRole('separator', { name: 'Resize Inspector' })
        const library = screen.getByText('Library region').closest('aside')
        const inspector = screen.getByText('Inspector region').closest('aside')
        expect(root).toHaveClass('flex', 'flex-col')
        expect(body).toHaveClass('flex-1', 'min-h-0', 'lg:grid-cols-[var(--nodeflow-library-width)_4px_minmax(0,1fr)_4px_var(--nodeflow-inspector-width)]')
        expect(body).not.toHaveClass('h-full')
        expect(library).toHaveClass('lg:col-start-1')
        expect(libraryResize).toHaveClass('lg:col-start-2')
        expect(main).toHaveClass('lg:col-start-3', 'min-h-0', 'overflow-hidden')
        expect(inspectorResize).toHaveClass('lg:col-start-4')
        expect(inspector).toHaveClass('lg:col-start-5')
    })

    it('calls controlled panel toggles from their named triggers and close actions', async () => {
        installMediaQuery(true)
        const user = userEvent.setup()
        const { props, rerender } = shell()
        await user.click(screen.getByRole('button', { name: 'Open Node Library' }))
        await user.click(screen.getByRole('button', { name: 'Open Inspector' }))
        expect(props.onLibraryOpenChange).toHaveBeenCalledWith(true)
        expect(props.onInspectorOpenChange).toHaveBeenCalledWith(true)

        rerender(<EditorShell {...props} libraryOpen inspectorOpen />)
        await user.click(screen.getByRole('button', { name: 'Close Node Library' }))
        expect(props.onLibraryOpenChange).toHaveBeenLastCalledWith(false)
    })

    it('opens focusable drawer headings, closes the top panel with Escape, and returns focus to its trigger', async () => {
        installMediaQuery(true)
        const user = userEvent.setup()
        const { props, rerender } = shell()
        await user.click(screen.getByRole('button', { name: 'Open Inspector' }))
        rerender(<EditorShell {...props} inspectorOpen />)
        expect(screen.getByRole('heading', { name: 'Inspector' })).toHaveFocus()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(props.onInspectorOpenChange).toHaveBeenLastCalledWith(false)
        rerender(<EditorShell {...props} inspectorOpen={false} />)
        expect(screen.getByRole('button', { name: 'Open Inspector' })).toHaveFocus()
    })

    it('makes closed narrow drawers inert to assistive technology and resolves simultaneous open props to inspector', async () => {
        installMediaQuery(true)
        const { props } = shell({ libraryOpen: true, inspectorOpen: true })
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Inspector' })).toBeInTheDocument())
        expect(screen.queryByRole('dialog', { name: 'Node Library' })).toBeNull()
        expect(props.onLibraryOpenChange).toHaveBeenCalledWith(false)
        const library = screen.getByText('Library region').closest('aside')
        expect(library).toHaveAttribute('aria-hidden', 'true')
        expect(library).toHaveAttribute('inert')
        expect(screen.queryByRole('button', { name: 'Library action' })).toBeNull()
    })

    it('switches narrow drawers without returning focus to the outgoing trigger, then returns focus when the active drawer closes', async () => {
        installMediaQuery(true)
        const user = userEvent.setup()
        const { props, rerender } = shell({ libraryOpen: true })
        await waitFor(() => expect(screen.getByRole('heading', { name: 'Node Library' })).toHaveFocus())
        await user.click(screen.getByRole('button', { name: 'Open Inspector' }))
        expect(props.onLibraryOpenChange).toHaveBeenLastCalledWith(false)
        expect(props.onInspectorOpenChange).toHaveBeenLastCalledWith(true)
        rerender(<EditorShell {...props} libraryOpen={false} inspectorOpen />)
        expect(screen.getByRole('heading', { name: 'Inspector' })).toHaveFocus()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(props.onInspectorOpenChange).toHaveBeenLastCalledWith(false)
        rerender(<EditorShell {...props} libraryOpen={false} inspectorOpen={false} />)
        expect(screen.getByRole('button', { name: 'Open Inspector' })).toHaveFocus()
    })

    it('keeps desktop panels persistent without drawer roles, focus theft, or Escape callbacks', () => {
        installMediaQuery(false)
        const { props } = shell({ libraryOpen: true, inspectorOpen: true })
        expect(screen.queryByRole('dialog')).toBeNull()
        expect(screen.getByRole('button', { name: 'Library action' })).toBeInTheDocument()
        expect(document.body).toHaveFocus()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(props.onLibraryOpenChange).not.toHaveBeenCalled()
        expect(props.onInspectorOpenChange).not.toHaveBeenCalled()
    })

    it('clamps keyboard and pointer resizing and exposes exact CSS custom properties', () => {
        shell()
        const root = screen.getByTestId('editor-shell')
        const libraryResize = screen.getByRole('separator', { name: 'Resize Node Library' })
        const inspectorResize = screen.getByRole('separator', { name: 'Resize Inspector' })
        expect(libraryResize).toHaveAttribute('aria-valuemin', '240')
        expect(libraryResize).toHaveAttribute('aria-valuemax', '400')
        expect(inspectorResize).toHaveAttribute('aria-valuemin', '288')
        expect(inspectorResize).toHaveAttribute('aria-valuemax', '480')
        fireEvent.keyDown(libraryResize, { key: 'ArrowRight' })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '336')
        fireEvent.keyDown(inspectorResize, { key: 'ArrowRight' })
        expect(inspectorResize).toHaveAttribute('aria-valuenow', '304')
        fireEvent.pointerDown(libraryResize, { pointerId: 1, clientX: 100 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 1000 })
        fireEvent.pointerUp(document, { pointerId: 1 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '400')
        expect(root).toHaveStyle({ '--nodeflow-library-width': '400px', '--nodeflow-inspector-width': '304px' })
    })

    it('derives every library drag move from its immutable pointer-down width and stops on pointerup', () => {
        shell()
        const libraryResize = screen.getByRole('separator', { name: 'Resize Node Library' })
        fireEvent.pointerDown(libraryResize, { pointerId: 1, clientX: 100 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 110 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '330')
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 120 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '340')
        fireEvent.pointerUp(document, { pointerId: 1 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 200 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '340')
    })

    it('derives every inspector drag move from its immutable pointer-down width and grows leftward', () => {
        shell()
        const inspectorResize = screen.getByRole('separator', { name: 'Resize Inspector' })
        fireEvent.pointerDown(inspectorResize, { pointerId: 2, clientX: 100 })
        fireEvent.pointerMove(document, { pointerId: 2, clientX: 90 })
        expect(inspectorResize).toHaveAttribute('aria-valuenow', '330')
        fireEvent.pointerMove(document, { pointerId: 2, clientX: 80 })
        expect(inspectorResize).toHaveAttribute('aria-valuenow', '340')
        fireEvent.pointerUp(document, { pointerId: 2 })
        fireEvent.pointerMove(document, { pointerId: 2, clientX: 0 })
        expect(inspectorResize).toHaveAttribute('aria-valuenow', '340')
    })

    it('ignores another pointer ending while an active drag continues until its own pointer ends', () => {
        shell()
        const libraryResize = screen.getByRole('separator', { name: 'Resize Node Library' })
        fireEvent.pointerDown(libraryResize, { pointerId: 1, clientX: 100 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 110 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '330')
        fireEvent.pointerUp(document, { pointerId: 2 })
        fireEvent.pointerCancel(document, { pointerId: 2 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 120 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '340')
        fireEvent.pointerUp(document, { pointerId: 1 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 150 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '340')
    })

    it('keeps the first primary drag when a second pointerdown attempts to take ownership', () => {
        shell()
        const libraryResize = screen.getByRole('separator', { name: 'Resize Node Library' })
        fireEvent.pointerDown(libraryResize, { pointerId: 1, clientX: 100, button: 0, isPrimary: true })
        fireEvent.pointerDown(libraryResize, { pointerId: 2, clientX: 500, button: 0, isPrimary: true })
        fireEvent.pointerMove(document, { pointerId: 2, clientX: 600 })
        fireEvent.pointerUp(document, { pointerId: 2 })
        fireEvent.pointerMove(document, { pointerId: 1, clientX: 120 })
        expect(libraryResize).toHaveAttribute('aria-valuenow', '340')
        fireEvent.pointerUp(document, { pointerId: 1 })
    })
})
