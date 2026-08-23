import type { Ref } from 'react'
import type { CompatibleRef, NodeLibraryProps } from './NodeLibrary'

declare const foreignMarker: unique symbol

type ForeignVoidOrUndefinedOnly = void | { [foreignMarker]: never }

/**
 * A consuming application's separately resolved React declarations have their
 * own private cleanup marker. It is structurally a React ref, but not assignable
 * to this copy's `React.Ref` when that marker leaks through a callback return.
 */
type ForeignReactRef<T> =
    | { current: T | null }
    | ((instance: T | null) => void | (() => ForeignVoidOrUndefinedOnly))
    | null

declare const foreignSearchRef: ForeignReactRef<HTMLInputElement>

const acceptsForeignReactRef = {
    searchInputRef: foreignSearchRef,
} satisfies Pick<NodeLibraryProps, 'searchInputRef'>

const acceptsForeignCompatibleRef: CompatibleRef<HTMLInputElement> = foreignSearchRef

// The package's local React copy must continue to reject the foreign private cleanup marker.
// @ts-expect-error Foreign React callback cleanups are not assignable to this copy's React.Ref.
const rejectsForeignLocalReactRef: Ref<HTMLInputElement> = foreignSearchRef

void acceptsForeignReactRef
void acceptsForeignCompatibleRef
void rejectsForeignLocalReactRef
