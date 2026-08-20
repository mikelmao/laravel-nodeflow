import { BooleanControl } from './Boolean'
import { Duration } from './Duration'
import { Multiselect } from './Multiselect'
import { NumberControl } from './Number'
import { Select } from './Select'
import { Text } from './Text'
import type { ControlMap, FieldControl } from './types'
import { Unregistered } from './Unregistered'

/**
 * The built-in set, closed at the six field types FieldType declares. Custom
 * types are the extension path - Field::custom() plus an entry on the `controls`
 * prop - because FieldType is a PHP enum a host cannot add a case to.
 *
 * A plain object, not a registry: E5. A module-level registry populated by
 * import side-effects is order-dependent and does not survive Inertia SSR.
 */
export const defaultControls: ControlMap = {
    text: Text,
    number: NumberControl,
    boolean: BooleanControl,
    select: Select,
    multiselect: Multiselect,
    duration: Duration,
}

/** Host overrides last, so a host may replace a built-in as well as add a type. */
export function mergeControls(overrides?: ControlMap): ControlMap {
    return { ...defaultControls, ...(overrides ?? {}) }
}

export function controlFor(type: string, controls: ControlMap): FieldControl {
    return controls[type] ?? Unregistered
}

export { Unregistered }
export type { ControlMap, FieldControl, FieldControlProps } from './types'
