import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { EditorShell } from './EditorShell'

function shell(overrides: Partial<React.ComponentProps<typeof EditorShell>> = {}) {
    const props: React.ComponentProps<typeof EditorShell> = {
        mode: 'workspace',
        toolbar: <div>Toolbar region</div>,
        library: <div>Library region</div>,
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

    it('calls controlled panel toggles from their named triggers and close actions', async () => {
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
        const user = userEvent.setup()
        const { props, rerender } = shell()
        const inspectorTrigger = screen.getByRole('button', { name: 'Open Inspector' })
        await user.click(inspectorTrigger)
        rerender(<EditorShell {...props} inspectorOpen />)
        expect(screen.getByRole('heading', { name: 'Inspector' })).toHaveFocus()
        fireEvent.keyDown(document, { key: 'Escape' })
        expect(props.onInspectorOpenChange).toHaveBeenLastCalledWith(false)
        rerender(<EditorShell {...props} inspectorOpen={false} />)
        expect(inspectorTrigger).toHaveFocus()
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
})
