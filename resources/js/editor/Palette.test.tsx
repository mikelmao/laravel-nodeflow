import { fireEvent, render, screen } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import type { NodeTypePayload } from '../graph/types'
import { Palette } from './Palette'

function entry(type: string, label: string, group: string): NodeTypePayload {
    return {
        type,
        label,
        group,
        icon: null,
        description: `${label} help`,
        outputs: [],
        fields: [],
        default_config: {},
        cardinality: ['subject'],
    }
}

describe('Palette', () => {
    // Group then label order is stable; counterfactual rendering registration order makes the palette arbitrary.
    it('pins groups and labels into alphabetical order', () => {
        render(
            <Palette
                palette={[
                    entry('z', 'Zulu', 'Messaging'),
                    entry('e', 'Exit', 'Core'),
                    entry('a', 'Alpha', 'Messaging'),
                ]}
                onAdd={vi.fn()}
            />,
        )

        expect(screen.getAllByRole('heading', { level: 3 }).map((heading) => heading.textContent)).toEqual([
            'Core',
            'Messaging',
        ])
        expect(screen.getAllByRole('button').map((button) => button.textContent)).toEqual([
            'Exite',
            'Alphaa',
            'Zuluz',
        ])
    })

    // An empty registry needs an actionable explanation; counterfactual blank chrome looks like a render bug.
    it('explains when no node types are registered', () => {
        render(<Palette palette={[]} onAdd={vi.fn()} />)

        expect(screen.getByText(/No node types are registered/)).toHaveTextContent('Nodeflow::register')
    })

    // The server payload is the definition contract; counterfactual reconstructing it drops extension metadata.
    it('returns the exact definition object that was clicked', () => {
        const send = entry('app.send', 'Send', 'Messaging')
        const onAdd = vi.fn()
        render(<Palette palette={[send]} onAdd={onAdd} />)

        fireEvent.click(screen.getByRole('button', { name: /Sendapp\.send/ }))

        expect(onAdd).toHaveBeenCalledWith(send)
        expect(onAdd.mock.calls[0]?.[0]).toBe(send)
    })
})
