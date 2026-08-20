// Deliberately not @types/node, even though it ships no runtime code and a
// types-only devDependency cannot itself violate the single-React-instance
// constraint that name might suggest. The real reason is narrower and
// sharper: tsconfig.json's "types" compiler option is package-wide, not
// per-file, so installing @types/node would make `process`, `Buffer`,
// `__dirname` and the rest ambient across every file under resources/js —
// including editor and canvas code a host's Vite compiles for the browser
// (package.json's decision D2), where none of those exist at runtime. A
// client component reading `process.env` is a real bug that @types/node
// would let typecheck silently. This shim's narrowness — exactly the two
// functions boundary.test.ts's directory scan calls, and nothing else Node
// offers — is the feature, not a limitation: it cannot leak a server-only
// global into browser code the way installing the real type package would.
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
