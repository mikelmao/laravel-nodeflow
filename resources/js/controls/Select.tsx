import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * The empty choice emits null, not ''.
 *
 * Field::rules() adds `in:<option keys>` to any field with static options, and
 * Laravel's `nullable` exempts null from the following rules but not ''. So a
 * field the author deliberately left blank would fail `in:` at publish time with
 * a message about an invalid selection rather than about a blank field.
 */
export function Select({ field, value, onChange, errors, options, optionsLoading }: FieldControlProps) {
    return (
        <FieldShell field={field} errors={errors}>{(controlId) => (
            <select
                id={controlId}
                className={inputClass}
                disabled={optionsLoading}
                value={value === null || value === undefined ? '' : String(value)}
                onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
            >
                <option value="">{optionsLoading ? 'Loading...' : '-'}</option>
                {Object.entries(options).map(([key, label]) => (
                    <option key={key} value={key}>
                        {label}
                    </option>
                ))}
            </select>
        )}</FieldShell>
    )
}
