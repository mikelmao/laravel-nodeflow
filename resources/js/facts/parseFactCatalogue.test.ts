import { describe, expect, it } from 'vitest'
import { parseFactCatalogue } from './types'

const payload = {
    contract_version: 1,
    revision: 'a'.repeat(64),
    facts: [{
        key: 'profile.segment',
        version: 1,
        label: 'Customer segment',
        type: 'text',
        capabilities: ['audience_filter', 'runtime_condition'],
        operators: {
            audience_filter: ['in'],
            runtime_condition: ['equals', 'not_equals', 'in'],
        },
        options: [
            { value: 'agriculture', label: 'Agriculture', active: true },
            { value: 'legacy', label: 'Legacy', active: false },
        ],
        missing_behavior: 'missing',
    }],
}

describe('parseFactCatalogue', () => {
    it('attaches the configured provider to every validated definition', () => {
        expect(parseFactCatalogue(payload, 'crm', 1)).toEqual({
            provider: 'crm',
            revision: 'a'.repeat(64),
            facts: [{ provider: 'crm', ...payload.facts[0] }],
        })
    })

    it('rejects unsupported contract versions and malformed typed options', () => {
        expect(() => parseFactCatalogue({ ...payload, contract_version: 2 }, 'crm', 1)).toThrow(/catalogue/i)
        expect(() => parseFactCatalogue({
            ...payload,
            facts: [{ ...payload.facts[0], options: [{ value: 10, label: 'Ten', active: true }] }],
        }, 'crm', 1)).toThrow(/catalogue/i)
    })
})
