import { describe, expect, it, vi } from 'vitest'
import type { NodeCardData, NodeTypePayload } from '../graph/types'
import { categoryPresentation, categoryClasses, nodeSummary } from './node'

function definition(overrides: Partial<NodeTypePayload> = {}): NodeTypePayload {
    return {
        kind: 'executable',
        type: 'app.send',
        label: 'Send message',
        group: 'Messaging',
        icon: null,
        description: 'Send a message to an audience.',
        outputs: ['sent'],
        fields: [
            { key: 'subject', type: 'text', label: 'Subject', help: null, default: null, required: true, options: {}, dynamic_options: false },
            { key: 'audiences', type: 'select', label: 'Audience', help: null, default: null, required: false, options: {}, dynamic_options: false },
        ],
        default_config: {},
        cardinality: ['audience'],
        ...overrides,
    }
}

function node(config: Record<string, unknown>): NodeCardData {
    return { id: 'internal-node-id', type: 'app.send', kind: 'executable', config, isStart: false }
}

describe('categoryPresentation', () => {
    it('is deterministic for a category and supplies a supported accent and icon', () => {
        const first = categoryPresentation('Messaging')
        expect(categoryPresentation('Messaging')).toEqual(first)
        expect(categoryClasses[first.accent]).toBeTruthy()
        expect(first.icon).toBeTruthy()
    })

    it('handles empty and unknown categories without exposing implementation details', () => {
        expect(categoryPresentation('')).toEqual(categoryPresentation(''))
        expect(categoryPresentation('Unusually Specific Host Category').accent).toMatch(/sky|emerald|amber|violet|rose|slate/)
    })

    it('normalizes I-containing category names without consulting the active locale', () => {
        const localeLower = vi.spyOn(String.prototype, 'toLocaleLowerCase').mockImplementation(function (this: string) {
            return this === 'INTEGRATION' ? 'locale-sensitive-form-x' : this.toLowerCase()
        })

        try {
            expect(categoryPresentation('INTEGRATION')).toEqual(categoryPresentation('integration'))
        } finally {
            localeLower.mockRestore()
        }
    })
})

describe('nodeSummary', () => {
    it('uses field definition order rather than configuration insertion order', () => {
        expect(nodeSummary(node({ audiences: 'Everyone', subject: 'Welcome' }), definition())).toBe('Subject: Welcome')
    })

    it('summarizes booleans and arrays concisely', () => {
        const bool = definition({ fields: [{ key: 'enabled', type: 'toggle', label: 'Enabled', help: null, default: false, required: false, options: {}, dynamic_options: false }] })
        const list = definition({ fields: [{ key: 'tags', type: 'tags', label: 'Tags', help: null, default: [], required: false, options: {}, dynamic_options: false }] })

        expect(nodeSummary(node({ enabled: true }), bool)).toBe('Enabled: Yes')
        expect(nodeSummary(node({ enabled: false }), bool)).toBe('Enabled: No')
        expect(nodeSummary(node({ tags: ['first', 'second', 'third', 'fourth'] }), list)).toBe('Tags: first, second +2 more')
    })

    it('truncates long configured text to a stable concise summary', () => {
        const summary = nodeSummary(node({ subject: 'A'.repeat(120) }), definition())

        expect(summary).toMatch(/^Subject: A+…$/)
        expect(summary.length).toBeLessThanOrEqual(80)
    })

    it('calls out the first absent required field before later configuration', () => {
        expect(nodeSummary(node({ audiences: 'Everyone' }), definition())).toBe('Needs configuration')
    })

    it('falls back to the definition description for an optional empty node', () => {
        const optional = definition({ fields: [{ key: 'note', type: 'text', label: 'Note', help: null, default: null, required: false, options: {}, dynamic_options: false }] })
        expect(nodeSummary(node({}), optional)).toBe('Send a message to an audience.')
    })
})
