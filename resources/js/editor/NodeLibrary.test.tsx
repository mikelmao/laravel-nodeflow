import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { createRef, StrictMode } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { NodeTypePayload } from '../graph/types'
import { filterNodeDefinitions, NodeLibrary } from './NodeLibrary'

function entry(overrides: Partial<NodeTypePayload> = {}): NodeTypePayload {
    return {
        kind: 'executable',
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: 'Deliver a message to every selected recipient.',
        outputs: [],
        fields: [],
        default_config: {},
        cardinality: ['subject'],
        ...overrides,
    }
}

describe('filterNodeDefinitions', () => {
    // A registry's registration order is arbitrary; mutating it or sorting ties differently makes the library jump.
    it('returns a stable, case-insensitive group then label order without mutating its input', () => {
        const tiedFirst = entry({ type: 'first', label: 'Alpha', group: 'messaging' })
        const tiedSecond = entry({ type: 'second', label: 'alpha', group: 'Messaging' })
        const definitions = [
            entry({ type: 'zulu', label: 'Zulu', group: 'Beta' }),
            tiedFirst,
            entry({ type: 'core', label: 'Exit', group: 'alpha' }),
            tiedSecond,
            entry({ type: 'apple', label: 'Apple', group: 'beta' }),
        ]

        expect(filterNodeDefinitions(definitions, '').map(({ type }) => type)).toEqual([
            'core', 'apple', 'zulu', 'first', 'second',
        ])
        expect(definitions.map(({ type }) => type)).toEqual([
            'zulu', 'first', 'core', 'second', 'apple',
        ])
    })

    // Searching only labels would hide definitions whose useful distinguishing text is supplied by the host elsewhere.
    it.each([
        ['label', ' SEND ', 'app.send'],
        ['group', 'messaging', 'app.send'],
        ['description', 'selected recipient', 'app.send'],
        ['technical type', 'APP.SEND', 'app.send'],
        ['combined haystack', 'message app.send', 'app.send'],
    ])('matches a trimmed case-insensitive query in the %s', (_field, query, expectedType) => {
        const send = entry()
        const wait = entry({ type: 'app.wait', label: 'Wait', group: 'Timing', description: null })

        expect(filterNodeDefinitions([wait, send], query).map(({ type }) => type)).toEqual([expectedType])
    })
})

describe('NodeLibrary', () => {
    it('reports the filtered result count through a polite live status', async () => {
        const user = userEvent.setup()
        render(<NodeLibrary palette={[entry(), entry({ type: 'app.wait', label: 'Wait', group: 'Timing' })]} onAdd={vi.fn()} />)

        await user.type(screen.getByRole('searchbox', { name: 'Search nodes' }), 'send')

        const status = screen.getByText('1 node type found')
        expect(status).toHaveAttribute('aria-live', 'polite')
    })

    it('distinguishes a missing registry from a query with no matches', async () => {
        const { rerender } = render(<NodeLibrary palette={[]} onAdd={vi.fn()} />)

        expect(screen.getByText(/No node types are registered/)).toBeInTheDocument()

        rerender(<NodeLibrary palette={[entry()]} onAdd={vi.fn()} />)
        await userEvent.setup().type(screen.getByRole('searchbox', { name: 'Search nodes' }), 'absent')

        expect(screen.getByText(/No nodes match/)).toBeInTheDocument()
        expect(screen.queryByText(/No node types are registered/)).not.toBeInTheDocument()
    })

    it('adds the exact definition once when its button is clicked', () => {
        const send = entry()
        const onAdd = vi.fn()
        render(<NodeLibrary palette={[send]} onAdd={onAdd} />)

        fireEvent.click(screen.getByRole('button', { name: 'Add Send message' }))

        expect(onAdd).toHaveBeenCalledTimes(1)
        expect(onAdd).toHaveBeenCalledWith(send)
        expect(onAdd.mock.calls[0]?.[0]).toBe(send)
    })

    it('adds through the native button Enter behavior exactly once', async () => {
        const onAdd = vi.fn()
        render(<NodeLibrary palette={[entry()]} onAdd={onAdd} />)

        screen.getByRole('button', { name: 'Add Send message' }).focus()
        await userEvent.setup().keyboard('{Enter}')

        expect(onAdd).toHaveBeenCalledTimes(1)
    })

    it('keeps each add button draggable without compromising click or keyboard activation', async () => {
        const onAdd = vi.fn()
        const dataTransfer = {
            setData: vi.fn(),
            effectAllowed: '',
        }
        render(<NodeLibrary palette={[entry()]} onAdd={onAdd} />)
        const button = screen.getByRole('button', { name: 'Add Send message' })

        fireEvent.dragStart(button, { dataTransfer })
        fireEvent.click(button)
        button.focus()
        await userEvent.setup().keyboard('{Enter}')

        expect(button).toHaveAttribute('draggable', 'true')
        expect(dataTransfer.setData).toHaveBeenCalledWith('application/x-nodeflow-node-type', 'app.send')
        expect(dataTransfer.effectAllowed).toBe('copy')
        expect(onAdd).toHaveBeenCalledTimes(2)
    })

    // Code-unit slicing can turn an astral emoji into replacement text; descriptions must remain readable at the limit.
    it('truncates a multi-code-point grapheme without splitting it', () => {
        const prefix = 'a'.repeat(118)
        const expected = `${prefix}👩‍💻…`
        render(<NodeLibrary palette={[entry({ description: `${prefix}👩‍💻 after the limit` })]} onAdd={vi.fn()} />)

        const description = screen.getByText(expected)
        expect(description).toHaveTextContent(expected)
        expect(description.textContent).not.toContain('\uFFFD')
    })

    it('leaves a ZWJ and combining-cluster description intact without Intl.Segmenter', () => {
        const segmenter = Object.getOwnPropertyDescriptor(Intl, 'Segmenter')
        const descriptionText = `${'a'.repeat(118)}👩‍💻e\u0301 after the limit`
        Object.defineProperty(Intl, 'Segmenter', { configurable: true, value: undefined })

        try {
            render(<NodeLibrary palette={[entry({ description: descriptionText })]} onAdd={vi.fn()} />)

            const description = screen.getByText(descriptionText)
            expect(description).toHaveTextContent(descriptionText)
            expect(description.textContent).not.toContain('\uFFFD')
            expect(description.textContent).not.toContain('…')
        } finally {
            if (segmenter === undefined) {
                Reflect.deleteProperty(Intl, 'Segmenter')
            } else {
                Object.defineProperty(Intl, 'Segmenter', segmenter)
            }
        }
    })

    it('renders accessible grouped add controls with presentation details and attached search and close controls', () => {
        const inputRef = createRef<HTMLInputElement>()
        const onRequestClose = vi.fn()
        render(
            <NodeLibrary
                palette={[
                    entry({ icon: '✉', description: 'Deliver a message.' }),
                    entry({ type: 'app.wait', label: 'Wait', group: 'Timing', description: 'Pause until a scheduled moment.' }),
                ]}
                onAdd={vi.fn()}
                onRequestClose={onRequestClose}
                searchInputRef={inputRef}
            />,
        )

        expect(screen.getByRole('complementary', { name: 'Node Library' })).toBeInTheDocument()
        expect(screen.getByRole('searchbox', { name: 'Search nodes' })).toBe(inputRef.current)
        expect(screen.getByRole('button', { name: 'Close node library' })).toBeInTheDocument()
        expect(screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual(['Messaging', 'Timing'])

        const send = screen.getByRole('button', { name: 'Add Send message' })
        expect(send).toHaveTextContent('✉')
        expect(send).toHaveTextContent('Deliver a message.')
        expect(send).toHaveTextContent('app.send')
        expect(screen.getByRole('button', { name: 'Add Wait' }).querySelector('svg')).toBeInTheDocument()

        fireEvent.click(screen.getByRole('button', { name: 'Close node library' }))
        expect(onRequestClose).toHaveBeenCalledTimes(1)
    })

    it('keeps a cleanup callback ref attached while the search query changes', async () => {
        const user = userEvent.setup()
        const cleanup = vi.fn()
        const searchInputRef = vi.fn((node: HTMLInputElement | null) => node === null ? undefined : cleanup)
        const { unmount } = render(<NodeLibrary palette={[entry()]} onAdd={vi.fn()} searchInputRef={searchInputRef} />)

        const search = screen.getByRole('searchbox', { name: 'Search nodes' })
        expect(searchInputRef).toHaveBeenCalledWith(search)

        await user.type(search, 'send')

        expect(cleanup).not.toHaveBeenCalled()
        expect(searchInputRef).toHaveBeenCalledTimes(1)

        unmount()

        expect(cleanup).toHaveBeenCalledTimes(1)
        expect(searchInputRef).not.toHaveBeenCalledWith(null)
    })

    it('replaces cleanup callback refs without a null callback and cleans each lifecycle once', () => {
        const firstCleanup = vi.fn()
        const secondCleanup = vi.fn()
        const first = vi.fn((node: HTMLInputElement | null) => node === null ? undefined : firstCleanup)
        const second = vi.fn((node: HTMLInputElement | null) => node === null ? undefined : secondCleanup)
        const { rerender, unmount } = render(<NodeLibrary palette={[entry()]} onAdd={vi.fn()} searchInputRef={first} />)
        const search = screen.getByRole('searchbox', { name: 'Search nodes' })

        rerender(<NodeLibrary palette={[entry()]} onAdd={vi.fn()} searchInputRef={second} />)

        expect(first).toHaveBeenCalledWith(search)
        expect(firstCleanup).toHaveBeenCalledTimes(1)
        expect(first).not.toHaveBeenCalledWith(null)
        expect(second).toHaveBeenCalledWith(search)

        unmount()

        expect(secondCleanup).toHaveBeenCalledTimes(1)
        expect(second).not.toHaveBeenCalledWith(null)
    })

    it('clears a void callback ref when it unmounts', () => {
        const searchInputRef = vi.fn()
        const { unmount } = render(<NodeLibrary palette={[entry()]} onAdd={vi.fn()} searchInputRef={searchInputRef} />)
        const search = screen.getByRole('searchbox', { name: 'Search nodes' })

        unmount()

        expect(searchInputRef).toHaveBeenNthCalledWith(1, search)
        expect(searchInputRef).toHaveBeenLastCalledWith(null)
    })

    it('balances every StrictMode callback attachment with one cleanup', () => {
        let attachments = 0
        let cleanups = 0
        let liveAttachments = 0
        const searchInputRef = vi.fn((node: HTMLInputElement | null) => {
            if (node === null) return

            attachments += 1
            liveAttachments += 1

            return () => {
                cleanups += 1
                liveAttachments -= 1
            }
        })
        const { unmount } = render(
            <StrictMode>
                <NodeLibrary palette={[entry()]} onAdd={vi.fn()} searchInputRef={searchInputRef} />
            </StrictMode>,
        )

        expect(attachments).toBeGreaterThan(0)
        expect(liveAttachments).toBe(1)

        unmount()

        expect(liveAttachments).toBe(0)
        expect(cleanups).toBe(attachments)
        expect(searchInputRef).not.toHaveBeenCalledWith(null)
    })

    it('gives each mounted library search label its own input id', () => {
        render(<><NodeLibrary palette={[entry()]} onAdd={vi.fn()} /><NodeLibrary palette={[entry()]} onAdd={vi.fn()} /></>)

        const inputs = screen.getAllByRole('searchbox', { name: 'Search nodes' })
        expect(inputs[0]?.id).not.toBe(inputs[1]?.id)
        expect((screen.getAllByText('Search nodes', { selector: 'label' }) as HTMLLabelElement[]).map((label) => label.htmlFor)).toEqual(inputs.map((input) => input.id))
    })
})
