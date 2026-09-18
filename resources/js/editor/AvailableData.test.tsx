import { fireEvent, render, screen } from '@testing-library/react'
import { expect, it } from 'vitest'
import { AvailableData } from './AvailableData'
it('groups by origin and searches names and descriptions without exposing reference fields as templates', () => {
    render(<AvailableData data={{ summary: 'Alert context', fields: [
        { key: 'name', label: 'Full name', origin: 'Audience', type: 'text', example: 'Amina', description: 'Recipient profile', availability: 'available' },
        { key: 'order_id', label: 'Order ID', origin: 'Order created', type: 'text', example: '11', description: 'Identity', availability: 'reference' },
    ] }} />)
    fireEvent.click(screen.getByText('Available data'))
    expect(screen.getByRole('heading', { name: 'Audience' })).toBeInTheDocument()
    expect(screen.getByText('{{ name }}')).toBeInTheDocument()
    expect(screen.queryByText('{{ order_id }}')).toBeNull()
    fireEvent.change(screen.getByRole('searchbox'), { target: { value: 'identity' } })
    expect(screen.queryByText('{{ name }}')).toBeNull()
    expect(screen.getByText('order_id')).toBeInTheDocument()
})
