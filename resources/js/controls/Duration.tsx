import { FieldShell, inputClass } from './Field'
import type { FieldControlProps } from './types'

/**
 * An amount and a unit, because ValidDuration is strict and a free-text box
 * makes the author discover that at publish time rather than at type time.
 *
 * The unit list is closed, and each entry was probed against
 * Nodeflow\Schema\Rules\ValidDuration::seconds() before being included.
 * `months` is deliberately absent: Carbon accepts it, but resolves one month to
 * 28 days, which would silently mislead an author writing a monthly follow-up.
 *
 * tests/Unit/DurationControlUnitsTest.php reads DURATION_UNITS out of this file
 * and asserts every string this control can emit passes ValidDuration, so a unit
 * added or renamed here fails a PHP test rather than a host's publish.
 */
export const DURATION_UNITS = ['seconds', 'minutes', 'hours', 'days', 'weeks'] as const

/** Finite so the PHP boundary test can prove every amount this control emits. */
export const MAX_DURATION_AMOUNT = 999

export type DurationUnit = (typeof DURATION_UNITS)[number]

const DEFAULT_UNIT: DurationUnit = 'minutes'

export function formatDuration(amount: number, unit: DurationUnit): string | null {
    return Number.isInteger(amount) && amount >= 1 && amount <= MAX_DURATION_AMOUNT ? `${amount} ${unit}` : null
}

/** Validate the spelling before Number() can turn exponent syntax into an integer. */
export function parseAmount(raw: string): number | null {
    if (!/^\d+$/.test(raw)) {
        return null
    }

    const amount = Number(raw)

    return Number.isSafeInteger(amount) && amount >= 1 && amount <= MAX_DURATION_AMOUNT ? amount : null
}

/** Strict on purpose: anything this does not recognise becomes an empty amount, so the author retypes it rather than publishing it. */
export function parseDuration(value: unknown): { amount: number | null; unit: DurationUnit } {
    const match = typeof value === 'string' ? /^(\d+)\s+(\w+)$/.exec(value.trim()) : null
    const rawAmount = match?.[1]
    const unit = match?.[2] as DurationUnit | undefined

    if (!rawAmount || !unit || !(DURATION_UNITS as readonly string[]).includes(unit)) {
        return { amount: null, unit: DEFAULT_UNIT }
    }

    const amount = parseAmount(rawAmount)

    return amount === null ? { amount: null, unit: DEFAULT_UNIT } : { amount, unit }
}

export function Duration({ field, value, onChange, errors }: FieldControlProps) {
    const { amount, unit } = parseDuration(value)

    // Null, not '0 minutes' and not '': ValidDuration rejects anything resolving
    // to zero or fewer seconds, and '0 days' resolves to 0. Emitting null lets
    // required() produce "this field is required" and lets nullable() pass,
    // which are both the message the author needs.
    const emit = (nextAmount: number | null, nextUnit: DurationUnit) =>
        onChange(nextAmount === null ? null : formatDuration(nextAmount, nextUnit))

    return (
        <FieldShell field={field} errors={errors}>
            <div className="flex gap-1">
                <input
                    id={`nf-${field.key}`}
                    type="number"
                    min="1"
                    max={MAX_DURATION_AMOUNT}
                    step="1"
                    className={inputClass}
                    value={amount === null ? '' : String(amount)}
                    onChange={(event) => emit(parseAmount(event.target.value), unit)}
                />
                <select className={inputClass} value={unit} onChange={(event) => emit(amount, event.target.value as DurationUnit)}>
                    {DURATION_UNITS.map((candidate) => (
                        <option key={candidate} value={candidate}>
                            {candidate}
                        </option>
                    ))}
                </select>
            </div>
        </FieldShell>
    )
}
