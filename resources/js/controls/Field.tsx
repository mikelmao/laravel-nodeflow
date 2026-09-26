import type { ReactNode } from 'react'
import type { FieldPayload } from '../graph/types'
import { useFieldControlId } from './FieldControlId'

/**
 * Label, help and errors, once.
 *
 * Only Tailwind utility classes, and only tokens a host's theme defines -
 * text-foreground, text-muted-foreground, text-destructive, border-input,
 * bg-background, ring-ring - so this renders
 * inside the host's design system rather than looking like an iframe that isn't
 * one. No colour is hardcoded and no CSS file is shipped.
 */
export function FieldShell({
    field,
    errors,
    children,
    grouped = false,
}: {
    field: FieldPayload
    errors: string[]
    children: ReactNode | ((controlId: string) => ReactNode)
    grouped?: boolean
}) {
    const controlId = useFieldControlId()
    const fieldControl = typeof children === 'function' ? children(controlId) : children
    const label = (
        <>
            {field.label}
            {field.required && <span className="text-destructive"> *</span>}
        </>
    )
    const supportingContent = (
        <>
            {field.help && <p className="text-[11px] text-muted-foreground">{field.help}</p>}

            {errors.length > 0 && (
                <ul role="alert" className="space-y-0.5 text-[11px] text-destructive">
                    {errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            )}
        </>
    )

    if (grouped) {
        return (
            <fieldset className="min-w-0 space-y-1.5">
                <legend className="block text-xs font-medium text-foreground">{label}</legend>

                {fieldControl}
                {supportingContent}
            </fieldset>
        )
    }

    return (
        <div className="space-y-1.5">
            <label className="block text-xs font-medium text-foreground" htmlFor={controlId}>
                {label}
            </label>

            {fieldControl}
            {supportingContent}
        </div>
    )
}

export const inputClass =
    'block min-h-8 w-full min-w-0 rounded-md border border-input bg-background px-2.5 py-1.5 text-xs text-foreground shadow-xs outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/25 disabled:cursor-not-allowed disabled:opacity-50'
