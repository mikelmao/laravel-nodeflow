import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { FactCataloguesContext, type FactCataloguesState } from '../facts/FactCataloguesContext'
import type { FactPredicate } from '../facts/types'
import { FactPredicateControl } from './FactPredicate'
import { FactPredicatesControl } from './FactPredicates'

const catalogue: FactCataloguesState = {
    catalogues: [{
        provider: 'crm',
        revision: 'a'.repeat(64),
        facts: [{
            provider: 'crm',
            key: 'profile.segment',
            version: 1,
            label: 'Customer segment',
            type: 'text',
            capabilities: ['audience_filter', 'runtime_condition'],
            operators: { audience_filter: ['in'], runtime_condition: ['equals', 'not_equals', 'in'] },
            options: [
                { value: 'agriculture', label: 'Agriculture', active: true },
                { value: 'legacy', label: 'Legacy', active: false },
            ],
            missing_behavior: 'missing',
        }],
    }],
    loading: false,
    error: null,
    retry: vi.fn(),
}

function factField(type: 'fact_predicate' | 'fact_predicates', capability: string, maximum: number): FieldPayload {
    return {
        key: 'predicate', type, label: 'Fact', help: null, default: type === 'fact_predicates' ? [] : null,
        required: true, options: {}, dynamic_options: false, fact_capability: capability, max_items: maximum,
    }
}

describe('fact controls', () => {
    it('authors a provider-qualified singular predicate from dependent dropdowns', async () => {
        const changed = vi.fn()
        function Harness() {
            const [value, setValue] = useState<FactPredicate | null>(null)
            return <FactPredicateControl
                field={factField('fact_predicate', 'runtime_condition', 1)} value={value}
                onChange={(next) => { setValue(next as FactPredicate); changed(next) }}
                errors={[]} options={{}} optionsLoading={false}
            />
        }
        render(<FactCataloguesContext.Provider value={catalogue}><Harness /></FactCataloguesContext.Provider>)

        await userEvent.selectOptions(screen.getByLabelText('Fact value'), 'crm:profile.segment:1')
        await userEvent.selectOptions(screen.getByLabelText('Value'), JSON.stringify('agriculture'))

        expect(changed).toHaveBeenLastCalledWith({
            provider: 'crm', key: 'profile.segment', version: 1, operator: 'equals', value: 'agriculture',
        })
    })

    it('uses field capability and max_items for a predicate list', async () => {
        const changed = vi.fn()
        render(<FactCataloguesContext.Provider value={catalogue}>
            <FactPredicatesControl
                field={factField('fact_predicates', 'audience_filter', 1)} value={[]} onChange={changed}
                errors={[]} options={{}} optionsLoading={false}
            />
        </FactCataloguesContext.Provider>)

        const add = screen.getByRole('button', { name: 'Add filter' })
        await userEvent.click(add)

        expect(changed).toHaveBeenCalledWith([{
            provider: 'crm', key: 'profile.segment', version: 1, operator: 'in', value: [],
        }])
    })

    it('keeps an unavailable pinned selection visible instead of silently replacing it', () => {
        render(<FactCataloguesContext.Provider value={catalogue}>
            <FactPredicateControl
                field={factField('fact_predicate', 'runtime_condition', 1)}
                value={{ provider: 'crm', key: 'deleted.fact', version: 1, operator: 'equals', value: 'x' }}
                onChange={vi.fn()} errors={[]} options={{}} optionsLoading={false}
            />
        </FactCataloguesContext.Provider>)

        expect(screen.getByRole('option', { name: 'Unavailable value' })).toBeInTheDocument()
    })
})

