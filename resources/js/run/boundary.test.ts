import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Spec E7's structural guarantee, enforced rather than documented.
 *
 * A run executed a frozen graph; the editor renders a draft that may have
 * diverged. One component for both invites painting a run's counts onto nodes
 * that were never in it, so the run view shares only the canvas primitives and
 * imports nothing from editor/. That means no autosave, no dirty state, no
 * draft and no publish path can reach this view — not by convention, but
 * because the import does not exist.
 *
 * Mutation proof: have FlowRun.tsx import the editor's autosave hook and this
 * fails, naming the file.
 *
 * (This docstring must not itself spell out that import as a quoted `from`
 * statement — the scan below reads its own source file along with every
 * sibling, and a literal example here would make the test fail against
 * itself forever, independent of FlowRun.tsx.)
 */
describe('run view dependency boundary', () => {
    it('imports nothing from the editor', () => {
        const dir = join(import.meta.dirname, '.')
        const offenders: string[] = []

        for (const entry of readdirSync(dir)) {
            if (!entry.endsWith('.ts') && !entry.endsWith('.tsx')) {
                continue
            }

            const source = readFileSync(join(dir, entry), 'utf8')

            if (/from\s+['"][^'"]*\/editor\/|from\s+['"]\.\.\/editor['"]/.test(source)) {
                offenders.push(entry)
            }
        }

        // Asserted rather than assumed: a boundary test that scanned an empty
        // directory would report green forever after a folder rename.
        expect(readdirSync(dir).length).toBeGreaterThan(0)
        expect(offenders).toEqual([])
    })
})
