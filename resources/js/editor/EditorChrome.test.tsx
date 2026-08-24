import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'
import { CanvasHud } from './CanvasHud'
import { EditorNotices, type EditorNoticesProps } from './EditorNotices'
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
        expect(screen.getByRole('status', { name: 'Save status: Saved' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Validate flow' })).toBeInTheDocument()
        expect(screen.getByRole('button', { name: 'Publish' })).toBeInTheDocument()
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
        const indicator = screen.getByRole('status', { name })
        expect(indicator).toHaveTextContent(name.replace('Save status: ', ''))
        expect(indicator).toHaveAttribute('aria-live', 'polite')
        expect(indicator).toHaveAttribute('title')
        expect(indicator).not.toHaveAttribute('tabindex')
        expect(screen.queryByRole('button', { name })).toBeNull()
    })

    it('represents validation and publishing actions, results, and disabled work states', async () => {
        const user = userEvent.setup()
        const { props, rerender } = toolbar({ validation: { status: 'warning', count: 2 }, publish: { status: 'published', version: 8 } })
        expect(screen.getByText('Ready with 2 warnings')).toBeInTheDocument()
        expect(screen.getByText('Published v8')).toBeInTheDocument()
        await user.click(screen.getByRole('button', { name: 'Validate flow' }))
        await user.click(screen.getByRole('button', { name: 'Publish' }))
        expect(props.onValidate).toHaveBeenCalledOnce()
        expect(props.onPublish).toHaveBeenCalledOnce()

        rerender(<EditorToolbar {...props} validation={{ status: 'checking' }} publish={{ status: 'publishing' }} />)
        expect(screen.getByRole('button', { name: 'Validate flow' })).toBeDisabled()
        expect(screen.getByRole('button', { name: 'Publish' })).toBeDisabled()
    })

    it('describes disabled publish readiness through a live region and announces becoming ready', () => {
        const { props, rerender } = toolbar({ publishDisabledReason: 'Add a trigger before publishing this flow.' })
        const publish = screen.getByRole('button', { name: 'Publish' })
        const readiness = screen.getByRole('status', { name: 'Publish readiness' })
        expect(publish).toBeDisabled()
        expect(publish).toHaveAccessibleDescription('Add a trigger before publishing this flow.')
        expect(readiness).toHaveAttribute('aria-live', 'polite')

        rerender(<EditorToolbar {...props} publishDisabledReason={null} />)

        expect(publish).toBeEnabled()
        expect(publish).toHaveAccessibleDescription('Flow is ready to publish.')
        expect(readiness).toHaveTextContent('Flow is ready to publish.')
    })

    it('keeps secondary actions in a named narrow overflow without duplicating primary actions', () => {
        toolbar()
        const overflow = screen.getByRole('group', { name: 'More workflow actions' })
        expect(within(overflow).getByRole('button', { name: 'Auto layout (more actions)' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Fit canvas (more actions)' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Undo (more actions)' })).toBeInTheDocument()
        expect(within(overflow).getByRole('button', { name: 'Redo (more actions)' })).toBeInTheDocument()
        expect(screen.getAllByRole('status', { name: 'Save status: Saved' })).toHaveLength(1)
        expect(screen.getAllByRole('button', { name: 'Validate flow' })).toHaveLength(1)
        expect(screen.getAllByRole('button', { name: 'Publish' })).toHaveLength(1)
        const details = overflow.querySelector('details')
        const menu = details?.querySelector(':scope > div')
        expect(details).toHaveClass('relative')
        expect(menu).toHaveClass('absolute', 'right-0', 'top-full', 'mt-1', 'z-20')
    })
})

describe('EditorNotices and CanvasHud', () => {
    // The conflict actions are part of the view contract, not optional wiring a caller may forget.
    // @ts-expect-error EditorNotices requires both conflict-resolution callbacks.
    const incompleteNoticeProps: EditorNoticesProps = { save: { status: 'idle' } }
    void incompleteNoticeProps

    it('renders persistent resolution, save, structural, graph, publish and validation feedback using their alert semantics', async () => {
        const user = userEvent.setup()
        const keepMine = vi.fn()
        const useTheirs = vi.fn()
        render(<EditorNotices
            save={{ status: 'conflict', message: 'A newer revision exists.' }}
            structuralError="The editor could not build this graph."
            graphMessages={['Start node is required', 'The node legacy is unplaceable']}
            publish={{ status: 'error', message: 'Publish failed.' }}
            validation={{ status: 'failed' }}
            validationMessage="Validation service unavailable."
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
        const callbacks = { onKeepMine: vi.fn(), onUseTheirs: vi.fn() }
        const { rerender } = render(<EditorNotices save={{ status: 'saved' }} {...callbacks} />)
        expect(screen.queryByRole('alert')).toBeNull()
        rerender(<EditorNotices save={{ status: 'idle' }} publish={{ status: 'published', version: 4 }} {...callbacks} />)
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

    it.each([
        [{ status: 'checking' }, 'Checking'],
        [{ status: 'valid' }, 'Ready to publish'],
        [{ status: 'failed' }, 'Validation failed'],
        [{ status: 'warning', count: 1 }, 'Ready with 1 warning'],
        [{ status: 'invalid', count: 2 }, '2 issues'],
    ] as const)('directly labels %o readiness in the HUD', (validation, label) => {
        render(<CanvasHud nodeCount={2} connectionCount={1} validation={validation} />)
        expect(screen.getByRole('status')).toHaveTextContent(label)
    })
})
