import type { ReactNode } from 'react'
import type { FieldPayload } from '../graph/types'

/**
 * Label, help and errors, once.
 *
 * Only Tailwind utility classes, and only tokens a host's theme defines -
 * text-foreground, text-muted-foreground, text-destructive, border-input,
 * bg-background, ring-ring - because D2's entire point is that this renders
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
    children: ReactNode
    grouped?: boolean
}) {
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
            <fieldset className="space-y-1">
                <legend className="block text-xs font-medium text-foreground">{label}</legend>

                {children}
                {supportingContent}
            </fieldset>
        )
    }

    return (
        <div className="space-y-1">
            <label className="block text-xs font-medium text-foreground" htmlFor={`nf-${field.key}`}>
                {label}
            </label>

            {children}
            {supportingContent}
        </div>
    )
}

export const inputClass =
    'w-full rounded-md border border-input bg-background px-2 py-1 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50'
