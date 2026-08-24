import { describe, expect, it } from 'vitest'
import { cloneGraphConfig, cloneJsonValue } from './json'

describe('JSON-safe graph config cloning', () => {
    it('deep-clones arrays, objects, scalars, and special own keys', () => {
        const source = Object.defineProperty({ nested: [{ value: null }] }, '__proto__', {
            value: { safe: true }, enumerable: true,
        })
        const clone = cloneJsonValue(source) as Record<string, unknown>

        expect(clone).toEqual(source)
        expect(clone).not.toBe(source)
        expect(Object.prototype.hasOwnProperty.call(clone, '__proto__')).toBe(true)
        expect(Object.getPrototypeOf(clone)).toBeNull()
    })

    it('rejects unsupported values and normalizes a malformed whole config', () => {
        const cyclic: Record<string, unknown> = {}
        cyclic.self = cyclic

        expect(() => cloneJsonValue({ value: undefined })).toThrow(TypeError)
        expect(() => cloneJsonValue({ value: Number.NaN })).toThrow(TypeError)
        expect(() => cloneJsonValue(cyclic)).toThrow(TypeError)
        expect(cloneGraphConfig({ value: undefined })).toEqual({})
        expect(cloneGraphConfig(new Date())).toEqual({})
    })
})
