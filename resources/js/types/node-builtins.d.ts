// The package has no @types/node dependency (decision D2: this toolchain is
// dev-only and never published, and the boundary test is the only file that
// needs a Node builtin). These are the minimal ambient shapes
// boundary.test.ts's directory scan actually calls, declared locally instead
// of pulling in a new npm dependency for one test file.
declare module 'node:fs' {
    export function readdirSync(path: string): string[]
    export function readFileSync(path: string, encoding: string): string
}

declare module 'node:path' {
    export function join(...segments: string[]): string
}

interface ImportMeta {
    dirname: string
}
