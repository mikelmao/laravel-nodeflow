export type History<T> = {
    past: T[]
    present: T
    future: T[]
    transaction: string | null
}

export function createHistory<T>(initial: T): History<T> {
    return {
        past: [],
        present: initial,
        future: [],
        transaction: null,
    }
}

export function commitHistory<T>(
    history: History<T>,
    next: T,
    transaction: string | null = null,
): History<T> {
    if (Object.is(history.present, next)) {
        return history
    }

    if (transaction !== null && transaction === history.transaction) {
        return { ...history, present: next, future: [] }
    }

    return {
        past: [...history.past, history.present].slice(-100),
        present: next,
        future: [],
        transaction,
    }
}

export function closeTransaction<T>(history: History<T>): History<T> {
    if (history.transaction === null) {
        return history
    }

    return { ...history, transaction: null }
}

export function undoHistory<T>(history: History<T>): History<T> {
    if (history.past.length === 0) {
        return history
    }

    return {
        past: history.past.slice(0, -1),
        present: history.past.at(-1) as T,
        future: [history.present, ...history.future],
        transaction: null,
    }
}

export function redoHistory<T>(history: History<T>): History<T> {
    if (history.future.length === 0) {
        return history
    }

    return {
        past: [...history.past, history.present],
        present: history.future[0] as T,
        future: history.future.slice(1),
        transaction: null,
    }
}

export function resetHistory<T>(next: T): History<T> {
    return createHistory(next)
}
