import { describe, expect, it } from 'vitest'

import {
    closeTransaction,
    commitHistory,
    createHistory,
    redoHistory,
    resetHistory,
    undoHistory,
} from './history'

describe('workflow document history', () => {
    it('creates an initial document with empty stacks and no transaction', () => {
        const initial = { nodes: [] }

        expect(createHistory(initial)).toEqual({
            past: [],
            present: initial,
            future: [],
            transaction: null,
        })
    })

    it('pushes the previous document on the first commit', () => {
        const initial = { nodes: [] }
        const next = { nodes: ['welcome'] }

        expect(commitHistory(createHistory(initial), next)).toEqual({
            past: [initial],
            present: next,
            future: [],
            transaction: null,
        })
    })

    it('coalesces repeated commits in one transaction', () => {
        const initial = { value: 0 }
        const first = { value: 1 }
        const second = { value: 2 }
        const history = commitHistory(createHistory(initial), first, 'config:node:key')

        expect(commitHistory(history, second, 'config:node:key')).toEqual({
            past: [initial],
            present: second,
            future: [],
            transaction: 'config:node:key',
        })
    })

    it('starts a new undo step for a different transaction', () => {
        const initial = { value: 0 }
        const first = { value: 1 }
        const second = { value: 2 }
        const history = commitHistory(createHistory(initial), first, 'config:first')

        expect(commitHistory(history, second, 'config:second')).toEqual({
            past: [initial, first],
            present: second,
            future: [],
            transaction: 'config:second',
        })
    })

    it('closes an active transaction without changing document stacks', () => {
        const initial = { value: 0 }
        const next = { value: 1 }
        const history = commitHistory(createHistory(initial), next, 'config:node:key')
        const closed = createHistory(initial)

        expect(closeTransaction(history)).toEqual({
            past: [initial],
            present: next,
            future: [],
            transaction: null,
        })
        expect(closeTransaction(closed)).toBe(closed)
    })

    it('undoes one document and closes the active transaction', () => {
        const initial = { value: 0 }
        const middle = { value: 1 }
        const present = { value: 2 }
        const history = commitHistory(
            commitHistory(createHistory(initial), middle),
            present,
            'config:node:key',
        )

        expect(undoHistory(history)).toEqual({
            past: [initial],
            present: middle,
            future: [present],
            transaction: null,
        })
    })

    it('redoes one document and closes the active transaction', () => {
        const initial = { value: 0 }
        const middle = { value: 1 }
        const present = { value: 2 }
        const undone = undoHistory(commitHistory(
            commitHistory(createHistory(initial), middle),
            present,
        ))
        const reopened = { ...undone, transaction: 'config:node:key' }

        expect(redoHistory(reopened)).toEqual({
            past: [initial, middle],
            present,
            future: [],
            transaction: null,
        })
    })

    it('restores documents in chronological order across multiple undo and redo steps', () => {
        const initial = { value: 0 }
        const one = { value: 1 }
        const two = { value: 2 }
        const three = { value: 3 }
        let history = commitHistory(
            commitHistory(
                commitHistory(createHistory(initial), one),
                two,
            ),
            three,
        )

        history = undoHistory(undoHistory(history))

        expect(history.present).toBe(one)
        expect(history.future).toEqual([two, three])

        history = redoHistory(history)
        expect(history.present).toBe(two)
        expect(history.future).toEqual([three])

        history = redoHistory(history)
        expect(history).toEqual({
            past: [initial, one, two],
            present: three,
            future: [],
            transaction: null,
        })
    })

    it('clears redo history when committing after an undo', () => {
        const initial = { value: 0 }
        const middle = { value: 1 }
        const discarded = { value: 2 }
        const replacement = { value: 3 }
        const undone = undoHistory(commitHistory(
            commitHistory(createHistory(initial), middle),
            discarded,
        ))

        expect(commitHistory(undone, replacement)).toEqual({
            past: [initial, middle],
            present: replacement,
            future: [],
            transaction: null,
        })
    })

    it('resets history around an authoritative document', () => {
        const initial = { value: 0 }
        const next = { value: 1 }
        const authoritative = { value: 2 }
        const history = commitHistory(commitHistory(createHistory(initial), next), authoritative)

        expect(resetHistory(history.present)).toEqual({
            past: [],
            present: authoritative,
            future: [],
            transaction: null,
        })
    })

    it('returns the original object for empty undo and redo', () => {
        const history = createHistory({ value: 0 })

        expect(undoHistory(history)).toBe(history)
        expect(redoHistory(history)).toBe(history)
    })

    it('returns the original object when committing the identical document', () => {
        const document = { value: 0 }
        const history = createHistory(document)

        expect(commitHistory(history, document, 'config:node:key')).toBe(history)
    })

    it('caps the past stack at the most recent 100 documents', () => {
        let history = createHistory(0)

        for (let document = 1; document <= 101; document += 1) {
            history = commitHistory(history, document)
        }

        expect(history.past).toHaveLength(100)
        expect(history.past[0]).toBe(1)
        expect(history.past.at(-1)).toBe(100)
        expect(history.present).toBe(101)
    })
})
