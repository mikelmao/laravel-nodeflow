import { useContext, useId } from 'react'
import { FactCataloguesContext } from '../facts/FactCataloguesContext'
import { projectFactPredicate, type FactDefinition, type FactPredicate, type FactScalar } from '../facts/types'
import type { FieldControlProps } from './types'

const inputClass = 'w-full rounded-md border border-input bg-background px-2 py-1.5 text-xs text-foreground focus:outline-none focus:ring-1 focus:ring-ring disabled:opacity-50'
const encodedFact = (fact: FactDefinition): string => `${fact.provider}:${fact.key}:${fact.version}`
const sameValue = (left: FactScalar, right: FactScalar): boolean => typeof left === typeof right && left === right

function defaultScalar(fact: FactDefinition): FactScalar {
    const active = fact.options.find((option) => option.active)?.value
    if (active !== undefined) return active
    if (fact.type === 'boolean') return false
    if (fact.type === 'number') return 0
    return ''
}

export function newFactPredicate(fact: FactDefinition, capability: string): FactPredicate {
    const operator = fact.operators[capability]?.[0] ?? 'equals'
    return {
        provider: fact.provider,
        key: fact.key,
        version: fact.version,
        operator,
        value: operator === 'in' ? [] : defaultScalar(fact),
    }
}

function FactPredicateFields({ facts, capability, predicate, onChange, disabled }: {
    facts: FactDefinition[]
    capability: string
    predicate: FactPredicate | null
    onChange: (value: FactPredicate | null) => void
    disabled: boolean
}) {
    const id = useId()
    const available = facts.filter((fact) => fact.capabilities.includes(capability))
    const fact = predicate === null ? undefined : available.find((candidate) => candidate.provider === predicate.provider
        && candidate.key === predicate.key && candidate.version === predicate.version)
    const choices = fact === undefined || predicate === null ? [] : fact.options.filter((option) =>
        option.active || (Array.isArray(predicate.value)
            ? predicate.value.some((value) => sameValue(value, option.value))
            : sameValue(predicate.value, option.value)))
    const unavailable = predicate !== null && fact === undefined

    const changeFact = (encoded: string) => {
        const selected = available.find((candidate) => encodedFact(candidate) === encoded)
        onChange(selected === undefined ? null : newFactPredicate(selected, capability))
    }
    const changeOperator = (operator: string) => {
        if (fact === undefined || predicate === null) return
        onChange({
            ...predicate,
            operator,
            value: operator === 'in'
                ? (Array.isArray(predicate.value) ? predicate.value : [])
                : (Array.isArray(predicate.value) ? defaultScalar(fact) : predicate.value),
        })
    }
    const toggle = (value: FactScalar) => {
        if (predicate === null) return
        const current = Array.isArray(predicate.value) ? predicate.value : []
        onChange({
            ...predicate,
            value: current.some((item) => sameValue(item, value))
                ? current.filter((item) => !sameValue(item, value))
                : [...current, value],
        })
    }

    return <div className="space-y-2 rounded-md border border-input bg-background p-2 text-foreground">
        <label htmlFor={`${id}-fact`} className="block text-xs font-medium">Fact value</label>
        <select id={`${id}-fact`} className={inputClass} disabled={disabled} value={fact === undefined ? '' : encodedFact(fact)} onChange={(event) => changeFact(event.target.value)}>
            <option value="">{unavailable ? 'Unavailable value' : 'Choose a value'}</option>
            {available.map((candidate) => <option key={encodedFact(candidate)} value={encodedFact(candidate)}>{candidate.label}</option>)}
        </select>
        {fact !== undefined && predicate !== null && <>
            <label htmlFor={`${id}-operator`} className="block text-xs font-medium">Operator</label>
            <select id={`${id}-operator`} className={inputClass} disabled={disabled} value={predicate.operator} onChange={(event) => changeOperator(event.target.value)}>
                {(fact.operators[capability] ?? []).map((operator) => <option key={operator} value={operator}>{operator.replaceAll('_', ' ')}</option>)}
            </select>
            <label htmlFor={`${id}-value`} className="block text-xs font-medium">Value</label>
            {fact.options.length > 0 && predicate.operator !== 'in' && <select
                id={`${id}-value`} className={inputClass} disabled={disabled} value={JSON.stringify(predicate.value)}
                onChange={(event) => {
                    const option = fact.options.find((candidate) => JSON.stringify(candidate.value) === event.target.value)
                    if (option !== undefined) onChange({ ...predicate, value: option.value })
                }}
            >{choices.map((choice) => <option key={JSON.stringify(choice.value)} value={JSON.stringify(choice.value)} disabled={!choice.active}>{choice.label}{choice.active ? '' : ' (inactive)'}</option>)}</select>}
            {fact.options.length > 0 && predicate.operator === 'in' && <div id={`${id}-value`} role="group" aria-label="Value" className="space-y-1">
                {choices.map((choice) => {
                    const checked = Array.isArray(predicate.value) && predicate.value.some((item) => sameValue(item, choice.value))
                    return <label key={JSON.stringify(choice.value)} className="flex items-center gap-2 text-xs">
                        <input type="checkbox" disabled={disabled || (!choice.active && !checked)} checked={checked} onChange={() => toggle(choice.value)} />
                        {choice.label}{choice.active ? '' : ' (inactive)'}
                    </label>
                })}
            </div>}
            {fact.options.length === 0 && predicate.operator === 'in' && <input
                id={`${id}-value`} className={inputClass} disabled={disabled} value={Array.isArray(predicate.value) ? predicate.value.join(', ') : ''}
                onChange={(event) => onChange({ ...predicate, value: parseList(event.target.value, fact.type) })}
            />}
            {fact.options.length === 0 && fact.type === 'boolean' && predicate.operator !== 'in' && <select
                id={`${id}-value`} className={inputClass} disabled={disabled} value={String(predicate.value)}
                onChange={(event) => onChange({ ...predicate, value: event.target.value === 'true' })}
            ><option value="true">True</option><option value="false">False</option></select>}
            {fact.options.length === 0 && fact.type !== 'boolean' && predicate.operator !== 'in' && <input
                id={`${id}-value`} className={inputClass} disabled={disabled} type={fact.type === 'number' ? 'number' : 'text'}
                value={Array.isArray(predicate.value) ? '' : String(predicate.value)}
                onChange={(event) => {
                    if (fact.type !== 'number') {
                        onChange({ ...predicate, value: event.target.value })
                        return
                    }
                    if (Number.isFinite(event.target.valueAsNumber)) {
                        onChange({ ...predicate, value: event.target.valueAsNumber })
                    }
                }}
            />}
        </>}
    </div>
}

function parseList(raw: string, type: FactDefinition['type']): FactScalar[] {
    const result: FactScalar[] = []
    for (const token of raw.split(',').map((item) => item.trim()).filter(Boolean)) {
        const value: FactScalar | null = type === 'boolean'
            ? (token === 'true' ? true : token === 'false' ? false : null)
            : type === 'number' ? (Number.isFinite(Number(token)) ? Number(token) : null) : token
        if (value !== null && !result.some((item) => sameValue(item, value))) result.push(value)
    }
    return result.slice(0, 100)
}

export function FactPredicateControl({ field, value, onChange, errors }: FieldControlProps) {
    const state = useContext(FactCataloguesContext)
    const facts = state.catalogues.flatMap((catalogue) => catalogue.facts)
    const capability = field.fact_capability ?? 'runtime_condition'
    return <fieldset className="space-y-2">
        <legend className="text-xs font-medium">{field.label}{field.required ? ' *' : ''}</legend>
        {state.loading && <p className="text-xs text-muted-foreground">Loading values…</p>}
        {state.error !== null && <div role="alert" className="flex items-center gap-2 text-xs text-destructive">
            <span>{state.error}</span><button type="button" onClick={state.retry}>Retry</button>
        </div>}
        <FactPredicateFields facts={facts} capability={capability} predicate={projectFactPredicate(value)} onChange={onChange} disabled={state.loading || state.error !== null} />
        {field.help && <p className="text-[11px] text-muted-foreground">{field.help}</p>}
        {errors.length > 0 && <ul role="alert" className="text-[11px] text-destructive">{errors.map((error) => <li key={error}>{error}</li>)}</ul>}
    </fieldset>
}

export { FactPredicateFields }
