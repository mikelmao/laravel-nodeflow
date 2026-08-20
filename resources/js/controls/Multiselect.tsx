import { FieldShell } from './Field'
import type { FieldControlProps } from './types'

/**
 * The gap 1 recorded: `multiselect` existed in PHP and the prototype degraded
 * it to a single <select>, so an author could only ever pick one.
 *
 * A checkbox list rather than a multiple <select>, because a multiple select is
 * an accessibility and discoverability problem - an author has to know to
 * ctrl-click - and because the server's base rule is `array`, which a checkbox
 * list satisfies structurally.
 */
export function Multiselect({ field, value, onChange, errors, options, optionsLoading }: FieldControlProps) {
    // A config written while this field was a `select` holds a scalar. Coerce
    // rather than crash: the author is most likely opening this node precisely
    // because it needs fixing.
    const selected: string[] = Array.isArray(value)
        ? value.map(String)
        : value === null || value === undefined || value === ''
          ? []
          : [String(value)]

    const toggle = (key: string) =>
        onChange(selected.includes(key) ? selected.filter((existing) => existing !== key) : [...selected, key])

    if (optionsLoading) {
        return (
            <FieldShell field={field} errors={errors} grouped>
                <p className="text-[11px] text-muted-foreground">Loading...</p>
            </FieldShell>
        )
    }

    return (
        <FieldShell field={field} errors={errors} grouped>
            <div className="space-y-1 rounded-md border border-input bg-background p-2">
                {Object.keys(options).length === 0 && <p className="text-[11px] text-muted-foreground">No choices available.</p>}
                {Object.entries(options).map(([key, label]) => (
                    <label key={key} className="flex items-center gap-2 text-xs text-foreground">
                        <input
                            type="checkbox"
                            className="size-3.5 rounded border-input"
                            checked={selected.includes(key)}
                            onChange={() => toggle(key)}
                        />
                        {label}
                    </label>
                ))}
            </div>
        </FieldShell>
    )
}
