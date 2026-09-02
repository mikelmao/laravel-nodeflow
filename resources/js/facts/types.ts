export type FactScalar = boolean | number | string
export type FactValueType = 'boolean' | 'number' | 'text'

export type FactOption = {
    value: FactScalar
    label: string
    active: boolean
}

export type FactDefinition = {
    provider: string
    key: string
    version: number
    label: string
    type: FactValueType
    capabilities: string[]
    operators: Record<string, string[]>
    options: FactOption[]
    missing_behavior: 'missing' | 'route_no' | 'route_yes' | 'fail'
}

export type FactCatalogue = {
    provider: string
    revision: string
    facts: FactDefinition[]
}

export type FactPredicate = {
    provider: string
    key: string
    version: number
    operator: string
    value: FactScalar | FactScalar[]
}

const stableKey = /^[a-z][a-z0-9._-]*$/
const isRecord = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value)
const exactKeys = (value: Record<string, unknown>, expected: string[]): boolean => {
    const actual = Object.keys(value).sort()
    const wanted = [...expected].sort()
    return actual.length === wanted.length && actual.every((key, index) => key === wanted[index])
}
const scalar = (value: unknown): value is FactScalar => typeof value === 'boolean' || typeof value === 'string'
    || (typeof value === 'number' && Number.isFinite(value))
const matchesType = (value: FactScalar, type: FactValueType): boolean => type === 'text'
    ? typeof value === 'string'
    : typeof value === type

export function parseFactCatalogue(value: unknown, provider: string, contractVersion = 1): FactCatalogue {
    const invalid = (): never => { throw new Error('Invalid fact catalogue.') }
    if (!stableKey.test(provider) || provider.length > 64 || !isRecord(value)) invalid()
    const root = value as Record<string, unknown>
    if (!exactKeys(root, ['contract_version', 'revision', 'facts'])) invalid()
    if (root.contract_version !== contractVersion) invalid()
    if (typeof root.revision !== 'string' || root.revision.length === 0 || root.revision.length > 191) invalid()
    const factCandidates = root.facts
    if (!Array.isArray(factCandidates) || factCandidates.length > 100) invalid()

    const identities = new Set<string>()
    const facts = (factCandidates as unknown[]).map((candidate: unknown): FactDefinition => {
        if (!isRecord(candidate) || !exactKeys(candidate, [
            'key', 'version', 'label', 'type', 'capabilities', 'operators', 'options', 'missing_behavior',
        ])) invalid()
        const item = candidate as Record<string, unknown>
        if (typeof item.key !== 'string' || !stableKey.test(item.key) || item.key.length > 255) invalid()
        if (!Number.isInteger(item.version) || (item.version as number) < 1) invalid()
        if (typeof item.label !== 'string' || item.label.trim() !== item.label || item.label.length === 0 || item.label.length > 255) invalid()
        if (item.type !== 'boolean' && item.type !== 'number' && item.type !== 'text') invalid()
        const type = item.type as FactValueType
        const capabilityCandidates = item.capabilities
        if (!Array.isArray(capabilityCandidates) || capabilityCandidates.length === 0 || capabilityCandidates.length > 20) invalid()
        if (!(capabilityCandidates as unknown[]).every((capability: unknown) => typeof capability === 'string' && stableKey.test(capability) && capability.length <= 64)) invalid()
        const capabilities = capabilityCandidates as string[]
        const operatorCandidates = item.operators
        if (new Set(capabilities).size !== capabilities.length || !isRecord(operatorCandidates)) invalid()
        if (!exactKeys(operatorCandidates as Record<string, unknown>, capabilities)) invalid()

        const operators: Record<string, string[]> = {}
        for (const capability of capabilities) {
            const entries = (operatorCandidates as Record<string, unknown>)[capability]
            if (!Array.isArray(entries) || entries.length === 0 || entries.length > 20
                || !entries.every((entry) => typeof entry === 'string' && stableKey.test(entry) && entry.length <= 64)
                || new Set(entries).size !== entries.length) invalid()
            operators[capability] = entries as string[]
        }

        const optionCandidates = item.options
        if (!Array.isArray(optionCandidates) || optionCandidates.length > 5_000) invalid()
        const optionValues = new Set<string>()
        const options = (optionCandidates as unknown[]).map((candidateOption: unknown): FactOption => {
            if (!isRecord(candidateOption) || !exactKeys(candidateOption, ['value', 'label', 'active'])) invalid()
            const option = candidateOption as Record<string, unknown>
            if (!scalar(option.value) || !matchesType(option.value, type)) invalid()
            if (typeof option.label !== 'string' || option.label.trim() !== option.label || option.label.length === 0 || option.label.length > 255) invalid()
            if (typeof option.active !== 'boolean') invalid()
            const identity = `${typeof option.value}:${JSON.stringify(option.value)}`
            if (optionValues.has(identity)) invalid()
            optionValues.add(identity)
            return {
                value: option.value as FactScalar,
                label: option.label as string,
                active: option.active as boolean,
            }
        })

        if (!['missing', 'route_no', 'route_yes', 'fail'].includes(String(item.missing_behavior))) invalid()
        const identity = `${item.key}@${item.version}`
        if (identities.has(identity)) invalid()
        identities.add(identity)

        return {
            provider,
            key: item.key as string,
            version: item.version as number,
            label: item.label as string,
            type,
            capabilities,
            operators,
            options,
            missing_behavior: item.missing_behavior as FactDefinition['missing_behavior'],
        }
    })

    return { provider, revision: root.revision as string, facts }
}

export function projectFactPredicate(value: unknown): FactPredicate | null {
    if (!isRecord(value)) return null
    if (!exactKeys(value, ['provider', 'key', 'version', 'operator', 'value'])) return null
    if (typeof value.provider !== 'string' || typeof value.key !== 'string' || !Number.isInteger(value.version)
        || typeof value.operator !== 'string') return null
    const predicateValue = value.value
    if (!scalar(predicateValue) && !(Array.isArray(predicateValue) && predicateValue.every(scalar))) return null
    return {
        provider: value.provider,
        key: value.key,
        version: value.version as number,
        operator: value.operator,
        value: predicateValue,
    }
}
