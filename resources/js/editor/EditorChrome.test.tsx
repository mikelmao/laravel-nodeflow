import { fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { CanvasHud } from './CanvasHud'
import { EditorNotices } from './EditorNotices'
import { EditorToolbar, type EditorToolbarProps } from './EditorToolbar'

function toolbar(overrides: Partial<EditorToolbarProps> = {}) {
    const props: EditorToolbarProps = {
        flowName: 'Welcome shoppers',
        triggerLabel: 'Order placed',
        publishedVersion: 7,
        save: { status: 'saved' },
        validation: { status: 'unchecked' },
        publish: { status: 'idle' },
        canUndo: true,
        canRedo: false,
        hasSelection: false,
        onUndo: vi.fn(),
        onRedo: vi.fn(),
        onAutoLayout: vi.fn(),
        onFit: vi.fn(),
        onDeleteSelected: vi.fn(),
        onValidate: vi.fn(),
        onPublish: vi.fn(),
        ...overrides,
    }
    return { props, ...render(<EditorToolbar {...props} />) }
}

describe('EditorToolbar', () => {
    it('keeps human workflow context and package controls ahead of optional slots', () => {
        toolbar({ slots: { leading: <span>Host back link</span>, trailing: <span>Host help</span> } })

        expect(screen.getByRole('heading', { name: 'Welcome shoppers' })).toBeInTheDocument()
        expect(screen.getByText('Trigger: Order placed')).toBeInTheDocument()
        expect(screen.getByText('Published v7')).toBeInTheDocument()
        expect(screen.getByText('Host back link')).toBeInTheDocument()
        expect(screen.getByText('Host help')).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Save status: Saved' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Validate flow' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Publish flow' })).toBeInTheDocument()
    })

    it('exposes disabled history and invokes visible canvas actions including contextual deletion', async () => {
        const user = userEvent.setup()
        const { props, rerender } = toolbar({ canUndo: false, canRedo: false, hasSelection: false })
        expect(screen.getByRole('button', { name: 'Undo' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Redo' })).toBeDisabled()
        expect(screen.queryByRole('button', { name: 'Delete selected' })).toBeNull()

        rerender(<EditorToolbar {...props} canUndo canRedo hasSelection />)
        await user.click(screen.getByRole('button', { name: 'Undo' }))
        await user.click(screen.getByRole('button', { name: 'Redo' }))
        await user.click(screen.getByRole('button', { name: 'Auto layout' }))
        await user.click(screen.getByRole('button', { name: 'Fit canvas' }))
        await user.click(screen.getByRole('button', { name: 'Delete selected' }))
        expect(props.onUndo).toHaveBeenCalledOnce()
        expect(props.onRedo).toHaveBeenCalledOnce()
        expect(props.onAutoLayout).toHaveBeenCalledOnce()
        expect(props.onFit).toHaveBeenCalledOnce()
        expect(props.onDeleteSelected).toHaveBeenCalledOnce()
    })

    it.each([
        ['idle', 'Save status: Changes saved'],
        ['saving', 'Save status: Saving changes'],
        ['saved', 'Save status: Saved'],
        ['error', 'Save status: Save failed'],
        ['conflict', 'Save status: Save conflict'],
    ] as const)('keeps %s save feedback visible and live', (status, name) => {
        toolbar({ save: { status } })
        expect(screen.getByRole('status')).toHaveTextContent(name.replace('Save status: ', ''))
        expect(screen.getByRole('button', { name })).toHaveAttribute('title')
    })

    it('represents validation and publishing actions, results, and disabled work states', async () => {
        const user = userEvent.setup()
        const { props, rerender } = toolbar({ validation: { status: 'warning', count: 2 }, publish: { status: 'published', version: 8 } })
        expect(screen.getByText('2 warnings')).toBeInTheDocument()
        expect(screen.getByText('Published v8')).toBeInTheDocument()
        await user.click(screen.getByRole('button', { name: 'Validate flow' }))
        await user.click(screen.getByRole('button', { name: 'Publish flow' }))
        expect(props.onValidate).toHaveBeenCalledOnce()
        expect(props.onPublish).toHaveBeenCalledOnce()

        rerender(<EditorToolbar {...props} validation={{ status: 'checking' }} publish={{ status: 'publishing' }} />)
        expect(screen.getByRole('button', { name: 'Validate flow' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Publish flow' })).toBeDisabled()
    })

    it('keeps secondary actions in a named narrow overflow without duplicating primary actions', () => {
        toolbar()
        const overflow = screen.getByRole('group', { name: 'More workflow actions' })
        expect(within(overflow).getByRole('button', { name: 'Auto layout' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Fit canvas' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Undo' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Redo' })).toBeInTheDocument()
        expect(screen.getAllByRole('button', { name: 'Save status: Saved' })).toHaveLength(1)
        expect(screen.getAllByRole('button', { name: 'Validate flow' })).toHaveLength(1)
        expect(screen.getAllByRole('button', { name: 'Publish flow' })).toHaveLength(1)
    })
})

describe('EditorNotices and CanvasHud', () => {
    it('renders persistent resolution, save, structural, graph, publish and validation feedback using their alert semantics', async () => {
        const user = userEvent.setup()
        const keepMine = vi.fn()
        const useTheirs = vi.fn()
        render(<EditorNotices
            save={{ status: 'conflict', message: 'A newer revision exists.' }}
            structuralError="The editor could not build this graph."
            graphMessages={['Start node is required', 'The node legacy is unplaceable']}
            publish={{ status: 'error', message: 'Publish failed.' }}
            validation={{ status: 'failed', message: 'Validation service unavailable.' }}
            onKeepMine={keepMine}
            onUseTheirs={useTheirs}
        />)
        expect(screen.getAllByRole('alert')).toHaveLength(5)
        expect(screen.getByText('Start node is required')).toBeInTheDocument()
        expect(screen.getByText('The node legacy is unplaceable')).toBeInTheDocument()
        await user.click(screen.getByRole('button', { name: 'Keep mine' }))
        await user.click(screen.getByRole('button', { name: 'Use theirs' }))
        expect(keepMine).toHaveBeenCalledOnce()
        expect(useTheirs).toHaveBeenCalledOnce()
    })

    it('does not duplicate routine saved feedback as a notice and reports published success as status', () => {
        const { rerender } = render(<EditorNotices save={{ status: 'saved' }} />)
        expect(screen.queryByRole('alert')).toBeNull()
        rerender(<EditorNotices save={{ status: 'idle' }} publish={{ status: 'published', version: 4 }} />)
        expect(screen.getByRole('status')).toHaveTextContent('Published v4')
    })

    it('shows count grammar and readiness without taking canvas pointer events', () => {
        const { rerender } = render(<CanvasHud nodeCount={1} connectionCount={2} validation={{ status: 'unchecked' }} />)
        const hud = screen.getByRole('status')
        expect(hud).toHaveClass('pointer-events-none')
        expect(hud).toHaveTextContent('1 node')
        expect(hud).toHaveTextContent('2 connections')
        expect(hud).toHaveTextContent('Not validated')
        rerender(<CanvasHud nodeCount={2} connectionCount={1} validation={{ status: 'warning', count: 3 }} />)
        expect(hud).toHaveTextContent('Ready with 3 warnings')
        rerender(<CanvasHud nodeCount={0} connectionCount={0} validation={{ status: 'invalid', count: 1 }} />)
        expect(hud).toHaveTextContent('1 issue')
    })
})
