import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

export function Text({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>{(controlId) => (
            <input
                id={controlId}
                type="text"
                className={inputClass}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => onChange(event.target.value)}
            />
        )}</FieldShell>
    )
}
