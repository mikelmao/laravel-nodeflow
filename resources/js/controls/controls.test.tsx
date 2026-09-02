import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { describe, expect, it, vi } from 'vitest'
import type { FieldPayload } from '../graph/types'
import { DURATION_UNITS, formatDuration, parseAmount, parseDuration } from './Duration'
import { controlFor, defaultControls, mergeControls } from './index'
import { Unregistered } from './Unregistered'

function field(overrides: Partial<FieldPayload> = {}): FieldPayload {
    return {
        key: 'template',
        type: 'text',
        label: 'Template',
        help: null,
        default: null,
        required: false,
        options: {},
        dynamic_options: false,
        ...overrides,
    }
}

type Extra = { errors: string[]; options: Record<string, string>; optionsLoading: boolean }

function renderControl(f: FieldPayload, value: unknown, onChange = vi.fn(), extra: Partial<Extra> = {}) {
    const Control = controlFor(f.type, mergeControls())

    function ControlledControl() {
        const [currentValue, setCurrentValue] = useState(value)

        return (
            <Control
                field={f}
                value={currentValue}
                onChange={(next) => {
                    setCurrentValue(next)
                    onChange(next)
                }}
                errors={extra.errors ?? []}
                options={extra.options ?? f.options}
                optionsLoading={extra.optionsLoading ?? false}
            />
        )
    }

    render(<ControlledControl />)

    return onChange
}

describe('control selection', () => {
    // Counterfactual: key defaultControls on anything but the wire `type` string
    // and every field falls through to Unregistered. Leave the shared defaults
    // mutable and one editor can corrupt every later editor in the same process.
    it('has a built-in for each package field type and no more', () => {
        expect(Object.keys(defaultControls).sort()).toEqual(['boolean', 'duration', 'fact_predicate', 'fact_predicates', 'multiselect', 'number', 'select', 'text'])
        expect(Object.isFrozen(defaultControls)).toBe(true)
    })

    // Counterfactual: return a text control as the fallback and a town picker
    // becomes a free-text box that passes `string` validation.
    it('falls back to Unregistered for a type nothing registered', () => {
        expect(controlFor('town', mergeControls())).toBe(Unregistered)
        expect(controlFor('__proto__', mergeControls())).toBe(Unregistered)
        expect(controlFor('constructor', mergeControls())).toBe(Unregistered)
        expect(controlFor('toString', Object.create({ toString: () => null }))).toBe(Unregistered)
    })

    // Counterfactual: spread the overrides before the defaults in mergeControls
    // and a host can never replace a built-in. Return the shared frozen object
    // and one editor's merge is no longer an isolated, host-specific map.
    it('lets a host override a built-in as well as add a custom type', () => {
        const Mine = () => null
        const controls = mergeControls({ town: Mine, text: Mine })

        expect(controlFor('town', controls)).toBe(Mine)
        expect(controlFor('text', controls)).toBe(Mine)
        expect(controlFor('__proto__', mergeControls({ ['__proto__']: Mine }))).toBe(Mine)
        expect(controlFor('constructor', mergeControls({ constructor: Mine }))).toBe(Mine)
        expect(controlFor('toString', mergeControls({ toString: Mine }))).toBe(Mine)
        expect(controls).not.toBe(defaultControls)
        expect(mergeControls()).not.toBe(mergeControls())
    })

    it.each([
        ['text', 'input'],
        ['number', 'input'],
        ['boolean', 'input'],
        ['select', 'select'],
        ['multiselect', 'input'],
        ['duration', 'input, select'],
    ] as const)('gives standalone %s controls collision-free IDs', (type, selector) => {
        const Control = controlFor(type, mergeControls())
        const definition = field({ type, key: 'same', options: { a: 'Ada' } })
        const props = { field: definition, value: null, onChange: vi.fn(), errors: [], options: definition.options, optionsLoading: false }
        const { container } = render(<><Control {...props} /><Control {...props} /></>)
        const controls = [...container.querySelectorAll<HTMLInputElement | HTMLSelectElement>(selector)]

        expect(controls).not.toHaveLength(0)
        expect(new Set(controls.map((control) => control.id)).size).toBe(controls.length)
        expect(controls.every((control) => control.id.length > 0)).toBe(true)
        if (type === 'text' || type === 'number' || type === 'boolean' || type === 'select') {
            const labels = [...container.querySelectorAll<HTMLLabelElement>('label[for]')]
            expect(labels).toHaveLength(2)
            expect(labels[0]!.control).toBe(controls[0])
            expect(labels[1]!.control).toBe(controls[1])
        }
    })
})

describe('Unregistered', () => {
    // The rule from 10, asserted both ways: the type must be named, and there
    // must be nothing an author could type into.
    // Counterfactual: render an <input> alongside the message and the second
    // assertion fails - which is the whole point of the control.
    it('names the missing type and renders no input at all', () => {
        const { container } = render(
            <Unregistered field={field({ type: 'town' })} value={null} onChange={vi.fn()} errors={[]} options={{}} optionsLoading={false} />,
        )

        expect(screen.getByRole('alert').textContent).toContain('town')
        expect(container.querySelectorAll('input, select, textarea')).toHaveLength(0)
    })
})

describe('select', () => {
    // Counterfactual: emit '' for the placeholder option. Field::rules() adds
    // `in:a,b` for a field with static options and `nullable` does not exempt ''
    // from `in:`, so publishing would fail validation on a field the author
    // deliberately left blank.
    it('emits null for the empty choice, never an empty string', async () => {
        const onChange = renderControl(field({ type: 'select', options: { a: 'A', b: 'B' } }), 'a')

        await userEvent.selectOptions(screen.getByRole('combobox'), '')

        expect(onChange).toHaveBeenCalledWith(null)
    })

    // Counterfactual: render the options without the loading guard and an author
    // sees an empty dropdown while the fetch is in flight, which is
    // indistinguishable from "this tenant has no templates".
    it('says it is loading rather than showing an empty list', () => {
        renderControl(field({ type: 'select', dynamic_options: true }), null, vi.fn(), { optionsLoading: true })

        expect(screen.getByRole('combobox')).toBeDisabled()
        expect(screen.getByText(/loading/i)).toBeTruthy()
    })

    // Counterfactual: swallow `errors` and an option-source failure renders as an
    // empty select - 10's "named error, never an empty select".
    it('renders the errors it was given', () => {
        renderControl(field({ type: 'select', dynamic_options: true }), null, vi.fn(), {
            errors: ['Could not load the choices for this field (HTTP 500).'],
        })

        expect(screen.getByRole('alert').textContent).toContain('HTTP 500')
    })
})

describe('multiselect', () => {
    // The 1 gap: the field type existed in PHP and the prototype degraded it to
    // a single <select>. Counterfactual: emit a string and the server's `array`
    // base rule rejects the publish. Wrap the checkbox set in a generic div and
    // assistive technology cannot discover that the choices belong to Towns.
    it('always emits an array', async () => {
        const onChange = renderControl(field({ key: 'towns', type: 'multiselect', label: 'Towns', options: { a: 'Ada', b: 'Bek' } }), [])

        expect(screen.getByRole('group', { name: 'Towns' })).toBeInTheDocument()
        await userEvent.click(screen.getByRole('checkbox', { name: 'Ada' }))

        expect(onChange).toHaveBeenCalledWith(['a'])
    })

    // Counterfactual: always append the clicked key and an author cannot clear a
    // selection once made; the array contains duplicate `a` instead of only b.
    it('removes a value that was already selected', async () => {
        const onChange = renderControl(field({ key: 'towns', type: 'multiselect', options: { a: 'Ada', b: 'Bek' } }), ['a', 'b'])

        await userEvent.click(screen.getByRole('checkbox', { name: 'Ada' }))

        expect(onChange).toHaveBeenCalledWith(['b'])
    })

    // A config saved when the field was a `select` holds a scalar. Counterfactual:
    // call .includes() on it and the panel crashes on the node the author most
    // wants to fix.
    it('survives a scalar left behind by a field that used to be a select', () => {
        renderControl(field({ key: 'towns', type: 'multiselect', options: { a: 'Ada' } }), 'a')

        expect(screen.getByRole('checkbox', { name: 'Ada' })).toBeChecked()
    })
})

describe('number', () => {
    // Counterfactual: emit '' and the `numeric` rule rejects it, so clearing an
    // optional number field makes the flow unpublishable.
    it('emits null for an empty box and a number otherwise', async () => {
        const onChange = renderControl(field({ key: 'count', type: 'number' }), 3)

        await userEvent.clear(screen.getByRole('spinbutton'))
        expect(onChange).toHaveBeenLastCalledWith(null)

        await userEvent.type(screen.getByRole('spinbutton'), '12')
        expect(onChange).toHaveBeenLastCalledWith(12)
    })
})

describe('boolean', () => {
    // Counterfactual: emit event.target.value ("on") and the server's boolean
    // rule rejects what looks like a checked checkbox.
    it('emits a boolean', async () => {
        const onChange = renderControl(field({ key: 'urgent', type: 'boolean' }), false)

        await userEvent.click(screen.getByRole('checkbox'))

        expect(onChange).toHaveBeenCalledWith(true)
    })
})

describe('duration', () => {
    // 9 names this case. The unit list is pinned against ValidDuration by
    // tests/Unit/DurationControlUnitsTest.php, which reads DURATION_UNITS out of
    // this file - so a unit renamed here fails a PHP test.
    // Counterfactual: add 'months' to DURATION_UNITS and the PHP test still
    // passes (Carbon accepts it) but this assertion fails, which is the reminder
    // that Carbon reads a month as 28 days.
    it('offers only units Carbon resolves unambiguously', () => {
        expect(DURATION_UNITS).toEqual(['seconds', 'minutes', 'hours', 'days', 'weeks'])
    })

    // Counterfactual: omit the separating space, or leave amount one paired
    // with a plural unit, and the control diverges from the engine's canonical
    // strings. The PHP boundary test exhausts the same emitted spellings.
    it('formats an amount and a unit into the string the engine parses', () => {
        for (const unit of DURATION_UNITS) {
            expect(formatDuration(1, unit)).toBe(`1 ${unit.slice(0, -1)}`)
            expect(formatDuration(2, unit)).toBe(`2 ${unit}`)
        }
    })

    // Number inputs accept exponent, sign and decimal syntax when typed
    // manually. Counterfactual: call Number(raw) before validating its spelling
    // and `1e2` silently becomes the otherwise-valid integer 100.
    it('accepts only decimal digits inside the exhaustively verified range', () => {
        expect(parseAmount('1')).toBe(1)
        expect(parseAmount('999')).toBe(999)
        expect(parseAmount('1e2')).toBeNull()
        expect(parseAmount('1.5')).toBeNull()
        expect(parseAmount('0')).toBeNull()
        expect(parseAmount('-1')).toBeNull()
        expect(parseAmount('1000')).toBeNull()
        expect(formatDuration(1e21, 'minutes')).toBeNull()
        expect(formatDuration(1.5, 'minutes')).toBeNull()
        expect(formatDuration(0, 'minutes')).toBeNull()
        expect(formatDuration(-1, 'minutes')).toBeNull()
        expect(formatDuration(1000, 'minutes')).toBeNull()
    })

    // Counterfactual: parse with a loose regex that accepts anything and the
    // amount box renders NaN.
    it('parses a stored duration back into its parts, and refuses nonsense', () => {
        for (const unit of DURATION_UNITS) {
            expect(parseDuration(`1 ${unit.slice(0, -1)}`)).toEqual({ amount: 1, unit })
        }

        expect(parseDuration('2 days')).toEqual({ amount: 2, unit: 'days' })
        expect(parseDuration('1 fortnight')).toEqual({ amount: null, unit: 'minutes' })
        expect(parseDuration(null)).toEqual({ amount: null, unit: 'minutes' })
    })

    // The dangerous emission. ValidDuration rejects a value resolving to <= 0,
    // and '0 days' resolves to 0. Counterfactual: pass the raw input straight to
    // formatDuration and clearing the box publishes '0 days', which fails at
    // publish time with a message about seconds rather than about a blank field.
    // Counterfactual: derive the unit only from the now-null serialized value
    // and retyping silently changes the author's chosen days back to minutes.
    it('emits null for an empty amount rather than a zero-second duration', async () => {
        const onChange = renderControl(field({ key: 'duration', type: 'duration' }), '5 days')
        const amount = screen.getByRole('spinbutton')

        await userEvent.clear(amount)

        expect(onChange).toHaveBeenLastCalledWith(null)

        await userEvent.type(amount, '5')

        expect(onChange).toHaveBeenLastCalledWith('5 days')
    })

    // Counterfactual: emit the unit alone when amount is blank, leave amount
    // one pluralized, or reconstruct the partial input only from that null
    // emission and the later amount uses the default minutes instead of the
    // author's selected days.
    it('emits a duration string when both parts are present', async () => {
        const onChange = renderControl(field({ key: 'duration', type: 'duration' }), null)
        const amount = screen.getByRole('spinbutton')

        await userEvent.type(amount, '1')
        expect(onChange).toHaveBeenLastCalledWith('1 minute')

        await userEvent.clear(amount)

        await userEvent.selectOptions(screen.getByRole('combobox'), 'days')
        await userEvent.type(amount, '1')

        expect(onChange).toHaveBeenLastCalledWith('1 day')
    })

    // Counterfactual: drop min/max and the browser advertises values outside the
    // same finite range the PHP test exhausts. Use one label for this compound
    // control and its amount/unit distinction and group name disappear.
    it('advertises the exhaustively verified amount range', () => {
        renderControl(field({ key: 'duration', type: 'duration', label: 'Duration' }), '5 minutes')

        expect(screen.getByRole('group', { name: 'Duration' })).toBeInTheDocument()
        expect(screen.getByRole('spinbutton', { name: 'Duration amount' })).toHaveAttribute('min', '1')
        expect(screen.getByRole('spinbutton', { name: 'Duration amount' })).toHaveAttribute('max', '999')
        expect(screen.getByRole('combobox', { name: 'Duration unit' })).toBeInTheDocument()
    })
})
