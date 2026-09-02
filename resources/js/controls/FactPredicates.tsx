import { useContext } from 'react'
import { FactCataloguesContext } from '../facts/FactCataloguesContext'
import { projectFactPredicate, type FactPredicate } from '../facts/types'
import { FactPredicateFields, newFactPredicate } from './FactPredicate'
import type { FieldControlProps } from './types'

const factIdentity = ({ provider, key, version }: { provider: string; key: string; version: number }): string =>
    `${provider}:${key}@${version}`

export function FactPredicatesControl({ field, value, onChange, errors }: FieldControlProps) {
    const state = useContext(FactCataloguesContext)
    const capability = field.fact_capability ?? 'audience_filter'
    const maximum = field.max_items ?? 10
    const facts = state.catalogues.flatMap((catalogue) => catalogue.facts).filter((fact) => fact.capabilities.includes(capability))
    const predicates = Array.isArray(value) ? value.flatMap((candidate) => {
        const predicate = projectFactPredicate(candidate)
        return predicate === null ? [] : [predicate]
    }) : []
    const selectedIdentities = new Set(predicates.map(factIdentity))
    const unselectedFacts = facts.filter((fact) => !selectedIdentities.has(factIdentity(fact)))
    const disabled = state.loading || state.error !== null
    const update = (next: FactPredicate[]) => onChange(next)

    return <fieldset className="space-y-2">
        <legend className="text-xs font-medium">{field.label}{field.required ? ' *' : ''}</legend>
        {state.loading && <p className="text-xs text-muted-foreground">Loading values…</p>}
        {state.error !== null && <div role="alert" className="flex items-center gap-2 text-xs text-destructive">
            <span>{state.error}</span><button type="button" onClick={state.retry}>Retry</button>
        </div>}
        {predicates.map((predicate, index) => <div key={`${predicate.provider}:${predicate.key}:${predicate.version}:${index}`} className="space-y-1">
            <FactPredicateFields
                facts={facts.filter((fact) => predicates.every((other, otherIndex) =>
                    otherIndex === index || factIdentity(other) !== factIdentity(fact)))}
                capability={capability} predicate={predicate} disabled={disabled}
                onChange={(next) => { if (next !== null) update(predicates.map((current, candidate) => candidate === index ? next : current)) }}
            />
            <button type="button" className="text-xs text-destructive" disabled={disabled} onClick={() => update(predicates.filter((_, candidate) => candidate !== index))}>Remove filter</button>
        </div>)}
        <button
            type="button" className="rounded border border-input bg-background px-2 py-1 text-xs"
            disabled={disabled || predicates.length >= maximum || unselectedFacts.length === 0}
            onClick={() => { if (unselectedFacts[0] !== undefined) update([...predicates, newFactPredicate(unselectedFacts[0], capability)]) }}
        >Add filter</button>
        {field.help && <p className="text-[11px] text-muted-foreground">{field.help}</p>}
        {errors.length > 0 && <ul role="alert" className="text-[11px] text-destructive">{errors.map((error) => <li key={error}>{error}</li>)}</ul>}
    </fieldset>
}
