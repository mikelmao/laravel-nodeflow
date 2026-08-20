import type { ReactElement } from 'react'
import type { FieldPayload } from '../graph/types'

/**
 * The whole contract between the package and a host's field control (5.7),
 * deliberately narrow and deliberately six keys.
 *
 * Option fetching is the package's job, in useFieldOptions, keyed by (node type,
 * field key). A custom control receives resolved options as data and never
 * learns the URL, so E6's invariant - the options endpoint never accepts a class
 * name from the client - cannot be broken by a host's control.
 *
 * `errors` carries anything that should render beside this field, which includes
 * the server's own validation messages and an option-load failure. Folding the
 * load failure in here rather than adding a seventh key means a host's custom
 * control gets 10's "named error, never an empty select" for free.
 */
export type FieldControlProps = {
    field: FieldPayload
    value: unknown
    onChange: (next: unknown) => void
    errors: string[]
    options: Record<string, string>
    optionsLoading: boolean
}

export type FieldControl = (props: FieldControlProps) => ReactElement | null

export type ControlMap = Record<string, FieldControl>
