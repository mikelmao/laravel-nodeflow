import { FieldShell } from './Field'
import type { FieldControlProps } from './types'

export function BooleanControl({ field, value, onChange, errors }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>
            <input
                id={`nf-${field.key}`}
                type="checkbox"
                className="size-4 rounded border-input"
                checked={Boolean(value)}
                onChange={(event) => onChange(event.target.checked)}
            />
        </FieldShell>
    )
}
