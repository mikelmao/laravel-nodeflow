import { useState } from 'react'
import { fireEvent, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import { TemplateText } from './TemplateText'

const fields = [
    { key: 'order_name', label: 'Order name', type: 'text', origin: 'Order created', example: 'Garden supplies', description: 'The alert title.', availability: 'available' as const },
    { key: 'severity', label: 'Severity', type: 'text', origin: 'Order created', example: 'orange', description: 'Warning level.', availability: 'available' as const },
    { key: 'order_id', label: 'Order ID', type: 'text', origin: 'Order created', example: '11', description: 'Event identity.', availability: 'reference' as const },
]
function Harness({ initial = '', available = fields }: { initial?: string; available?: typeof fields }) {
    const [value, setValue] = useState(initial)
    return <TemplateText id="message" label="Description" value={value} onChange={setValue} fields={available} multiline />
}

describe('TemplateText', () => {
    it('filters on typing and inserts with Enter without adding a newline', async () => {
        const user = userEvent.setup()
        render(<Harness />)
        const input = screen.getByRole('textbox', { name: 'Description' })
        await user.click(input)
        await user.paste('Hi {{ or')
        expect(screen.getAllByRole('option')).toHaveLength(1)
        expect(screen.getByRole('option')).toHaveTextContent('Garden supplies')
        await user.keyboard('{Enter}')
        expect(input).toHaveValue('Hi {{ order_name }}')
        expect(screen.queryByRole('listbox')).toBeNull()
        expect(input).toHaveFocus()
    })
    it('supports arrow navigation and mouse selection, preserving surrounding text', async () => {
        const user = userEvent.setup()
        render(<Harness initial="Before {{ or }} after" />)
        const input = screen.getByRole('textbox') as HTMLTextAreaElement
        input.focus()
        input.setSelectionRange(12, 12)
        fireEvent.select(input)
        await user.click(screen.getByRole('option', { name: /Order name/ }))
        expect(input).toHaveValue('Before {{ order_name }} after')
        expect(input.selectionStart).toBe(23)
    })
    it('dismisses on Escape and lets Enter behave normally afterwards', async () => {
        const user = userEvent.setup()
        render(<Harness />)
        const input = screen.getByRole('textbox')
        await user.click(input)
        await user.paste('{{')
        await user.keyboard('{ArrowDown}')
        expect(screen.getByRole('option', { selected: true })).toHaveTextContent('Severity')
        await user.keyboard('{Escape}{Enter}')
        expect(input).toHaveValue('{{\n')
        expect(screen.queryByRole('listbox')).toBeNull()
    })
    it('flags saved placeholders when their source becomes unavailable', () => {
        const { rerender } = render(<Harness initial="{{ order_name }}" />)
        expect(screen.queryByRole('alert')).toBeNull()
        rerender(<Harness initial="{{ order_name }}" available={[]} />)
        expect(screen.getByRole('alert')).toHaveTextContent('order_name')
        expect(screen.getByRole('textbox')).toHaveValue('{{ order_name }}')
    })
    it('never suggests reference fields and explains no matches', async () => {
        const user = userEvent.setup()
        render(<Harness />)
        await user.click(screen.getByRole('textbox'))
        await user.paste('{{ order_id')
        expect(screen.queryByRole('option')).toBeNull()
        expect(screen.getByRole('status')).toHaveTextContent('No matching placeholders')
    })
})

it('does not intercept composition Enter or trap Tab in suggestions', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const input = screen.getByRole('textbox')
    await user.click(input)
    await user.paste('{{')
    fireEvent.keyDown(input, { key: 'Enter', isComposing: true })
    expect(input).toHaveValue('{{')
    await user.tab()
    expect(screen.queryByRole('listbox')).toBeNull()
})

it('inserts the keyboard-selected option and closes when the token is deleted', async () => {
    const user = userEvent.setup()
    render(<Harness />)
    const input = screen.getByRole('textbox')
    await user.click(input)
    await user.paste('{{')
    await user.keyboard('{ArrowDown}{Enter}')
    expect(input).toHaveValue('{{ severity }}')
    await user.clear(input)
    await user.paste('{{')
    await user.keyboard('{Backspace}')
    expect(screen.queryByRole('listbox')).toBeNull()
})

it('offers conditional values with context guidance instead of labelling them invalid', async () => {
    render(<TemplateText id="conditional" label="Message" value="{{" onChange={() => {}} fields={[{ ...fields[0]!, availability: 'conditional' }]} />)
    const input = screen.getByRole('combobox') as HTMLInputElement
    input.focus()
    input.setSelectionRange(2, 2)
    fireEvent.select(input)
    expect(screen.getByRole('option')).toHaveTextContent('Requires upstream context')
})

it('uses host-provided context guidance without embedding application rules', () => {
    const field = { ...fields[0]!, availability: 'conditional' as const, availabilityNote: 'Requires an approved purchase.' }
    render(<TemplateText id="host-note" label="Message" value="{{ order_name }}" onChange={() => {}} fields={[field]} />)
    expect(screen.getByText(/Requires an approved purchase/)).toBeInTheDocument()
    expect(screen.queryByRole('alert')).toBeNull()
})
