import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * Emits a number, or null for an empty box - never ''. The server's base rule
 * for a number field is `numeric`, and `nullable` does not exempt '' from it, so
 * an emptied optional number field would make the flow unpublishable.
 */
export function NumberControl({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <input
                id={`nf-${field.key}`}
                type="number"
                className={inputClass}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => {
                    const raw = event.target.value

                    onChange(raw === '' ? null : Number(raw))
                }}
            />
        </FieldShell>
    )
}
