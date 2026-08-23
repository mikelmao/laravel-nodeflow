import { createContext, type ReactNode, useContext, useId } from 'react'

const FieldControlIdContext = createContext<string | null>(null)

export function FieldControlIdProvider({ id, children }: { id: string; children: ReactNode }) {
    return <FieldControlIdContext.Provider value={id}>{children}</FieldControlIdContext.Provider>
}

/** Built-ins share a row-provided ID, while standalone controls get their own stable React ID. */
export function useFieldControlId(): string {
    const provided = useContext(FieldControlIdContext)
    const fallback = useId().replace(/:/g, '')

    return provided ?? `nf-${fallback}`
}
