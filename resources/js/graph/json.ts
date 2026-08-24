export type JsonPrimitive = string | number | boolean | null
export type JsonValue = JsonPrimitive | JsonValue[] | { [key: string]: JsonValue }

/**
 * Clone the exact JSON value family without stringify/parse coercions.
 * Unsupported values fail as a unit so config never changes meaning merely by
 * crossing a client boundary.
 */
export function cloneJsonValue(value: unknown, active = new WeakSet<object>()): JsonValue {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') return value
    if (typeof value === 'number' && Number.isFinite(value)) return value
    if (typeof value !== 'object') throw new TypeError('Nodeflow configuration must contain only JSON values.')

    if (active.has(value)) throw new TypeError('Nodeflow configuration cannot contain cycles.')
    active.add(value)
    try {
        if (Array.isArray(value)) return value.map((item) => cloneJsonValue(item, active))
        const prototype = Object.getPrototypeOf(value)
        if (prototype !== Object.prototype && prototype !== null) {
            throw new TypeError('Nodeflow configuration objects must be plain JSON objects.')
        }

        const clone: { [key: string]: JsonValue } = Object.create(null)
        for (const key of Object.keys(value)) {
            Object.defineProperty(clone, key, {
                value: cloneJsonValue((value as Record<string, unknown>)[key], active),
                enumerable: true,
                configurable: true,
                writable: true,
            })
        }
        return clone
    } finally {
        active.delete(value)
    }
}

/** Empty/non-object PHP config and malformed non-JSON input share one safe form. */
export function cloneGraphConfig(value: unknown): Record<string, unknown> {
    if (value === null || typeof value !== 'object' || Array.isArray(value)) return {}
    try {
        return cloneJsonValue(value) as Record<string, unknown>
    } catch {
        return {}
    }
}
