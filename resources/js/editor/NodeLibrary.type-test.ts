import type { NodeLibraryProps } from './NodeLibrary'

declare const foreignRefCleanup: unique symbol

/**
 * A consuming application's separately resolved React declarations have their
 * own private cleanup marker. It is structurally a React ref, but not assignable
 * to this copy's `React.Ref` when that marker leaks through a callback return.
 */
type ForeignReactRef<T> =
    | { current: T | null }
    | ((instance: T | null) => void | (() => typeof foreignRefCleanup))
    | null

declare const foreignSearchRef: ForeignReactRef<HTMLInputElement>

const acceptsForeignReactRef = {
    searchInputRef: foreignSearchRef,
} satisfies Pick<NodeLibraryProps, 'searchInputRef'>

void acceptsForeignReactRef
