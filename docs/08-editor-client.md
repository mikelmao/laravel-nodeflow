# Editor client

The package ships the editor's TypeScript source and React components under
`resources/js`. Your application's Vite build compiles that source against your
application's React installation and Tailwind design tokens. The package exports
components, not Inertia pages: it has no prebuilt bundle, published npm package or
CSS file of its own.

## Wire the host application

The host needs all five settings below. Two failures stop a build loudly; three
allow some or all of the build to succeed and are therefore quiet.

### 1. Alias the package source in Vite

```ts
// vite.config.ts
import path from 'node:path'

export default defineConfig({
    resolve: {
        alias: {
            '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
        },
    },
})
```

If the alias is missing, Vite cannot resolve `@nodeflow/editor`. The build fails
immediately. **Loud.**

### 2. Teach TypeScript the same paths

```jsonc
// tsconfig.json
{
  "compilerOptions": {
    "paths": {
      "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
      "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
    }
  }
}
```

If these base and wildcard mappings are missing, Vite can still build while the
host's `tsc` and editor IntelliSense report that the import cannot be found.
**Quiet.**

### 3. Include the package source in Tailwind

Add this to the host's CSS entry, adjusting only the relative path if that entry
does not live at `resources/css/app.css`:

```css
@source '../../vendor/atram/laravel-nodeflow/resources/js';
```

Tailwind v4's automatic source detection skips gitignored paths, and applications
normally gitignore `vendor/`. Without the explicit source, the build succeeds and
the editor renders, but utilities used only by the package source—for example
`min-h-[36rem]`—are absent. Utilities the host happens to use elsewhere can mask
part of the damage. **Quiet, and the worst of the five failures.**

### 4. Install React Flow in the host

```bash
npm install @xyflow/react
```

That records the dependency in the host's manifest:

```jsonc
// package.json
{
  "dependencies": {
    "@xyflow/react": "^12.0.0"
  }
}
```

The host's Vite compiles the package source, so Composer and the alias install no
npm dependencies on its behalf. Without `@xyflow/react` in the host's
`package.json`, the build fails to resolve it. **Loud.**

### 5. Deduplicate React for symlink development

```ts
// vite.config.ts
export default defineConfig({
    resolve: {
        dedupe: ['react', 'react-dom', '@xyflow/react'],
    },
})
```

This is required when the Composer package is symlinked for local development.
Vite resolves the symlink to its real path, so a bare `react` import inside
`resources/js` can resolve from the package's own `node_modules`, which exists for
Vitest and `tsc`, instead of the host's installation. Mounting two React copies on
one page produces errors such as "Invalid hook call" or "Cannot read properties of
null (reading 'useState')" when the editor first mounts.

A normal Composer install has no `node_modules` inside
`vendor/atram/laravel-nodeflow`, so resolution walks up to the host's copy. The
`dedupe` setting is harmless in that case and makes both installations work.
**Quiet, because it looks like a React bug rather than a configuration error.**

## Add the thin Inertia page

```tsx
import { FlowEditor } from '@nodeflow/editor'
import type { FlowEditorProps } from '@nodeflow/editor'

export default function Page(props: FlowEditorProps) {
    return <FlowEditor {...props} />
}
```

Put this file in the host at `resources/js/pages/nodeflow/editor.tsx`, matching the
`nodeflow/editor` component rendered by the package controller. Inertia's resolver
globs the host's pages; it will never find a page inside `vendor/`.

This thin page is three seams at once: the Inertia resolver entry, the place to
wrap the editor in the host's layout, and the place where host theming reaches the
package source. Wrap `FlowEditor` here, or let the host's global `layout` resolver
provide the layout.

## Register a custom field control

Declare the custom type in the node definition:

```php
Field::custom('destination', 'town')
```

Then pass a control under the same type name:

```tsx
import { FlowEditor } from '@nodeflow/editor'
import type { FieldControlProps, FlowEditorProps } from '@nodeflow/editor'

function TownPicker({ field, value, onChange, errors, options, optionsLoading }: FieldControlProps) {
    return (
        <label>
            <span>{field.label}</span>
            <select
                value={String(value ?? '')}
                disabled={optionsLoading}
                onChange={(event) => onChange(event.target.value)}
            >
                <option value="">Choose a town</option>
                {Object.entries(options).map(([id, label]) => (
                    <option key={id} value={id}>{label}</option>
                ))}
            </select>
            {errors.map((error) => <span key={error} role="alert">{error}</span>)}
        </label>
    )
}

export default function Page(props: FlowEditorProps) {
    return <FlowEditor {...props} controls={{ town: TownPicker }} />
}
```

The complete control contract is:

```ts
type FieldControlProps = {
    field: FieldPayload
    value: unknown
    onChange: (next: unknown) => void
    errors: string[]
    options: Record<string, string>
    optionsLoading: boolean
}
```

An unregistered custom type renders a visible error that names the missing type;
it never falls back to a text input. A silent text fallback could accept a value
that passes the custom field's base rule but is meaningless to the node, delaying
the real failure until a run executes.

## Override a node's appearance

```tsx
import { FlowEditor } from '@nodeflow/editor'
import type { FlowEditorProps, NodeRendererProps } from '@nodeflow/editor'

function MyCard({ data, def, selected, errors }: NodeRendererProps) {
    return <div>{def?.label ?? data.type}: {data.id}</div>
}

export default function Page(props: FlowEditorProps) {
    return (
        <FlowEditor
            {...props}
            nodeRenderers={{ 'yaya.send_message': MyCard }}
        />
    )
}
```

The complete renderer contract is:

```ts
type NodeRendererProps = {
    data: NodeCardData
    def: NodeTypePayload | undefined
    selected: boolean
    errors: string[]
}
```

A renderer owns only the node body. The package wrapper retains the target handle,
one source handle for every declared output, and the node's error list. Handles are
not the renderer's job, so an appearance override cannot accidentally make the node
unwireable.

## Endpoint behavior

The editor debounces draft saves and echoes the integer `draft_revision` supplied
by the last accepted response. A **409** stops autosave and presents both choices:
**Keep mine** adopts the server's newer revision and saves the local graph over it;
**Use theirs** replaces the canvas with the server graph. The client never chooses
silently.

Publish waits for every accepted draft PUT, force-flushes the graph captured by the
publish click, and holds later edits behind a barrier until the publish POST
completes. Those later edits then become the next draft. The client also refuses to
send an edge whose output it cannot resolve; the author must choose the output
before publishing.

Publish can return two different **422** bodies:

- A semantic failure includes `node_errors`. It is an author-repairable graph or
  node-configuration problem, so the editor places messages in the summary, on
  node cards and beside fields when possible.
- A structural failure has a field-keyed `errors` object and no `node_errors` key.
  The editor labels it as a client bug because its own graph serializer should not
  send malformed wire data; it is not presented as an authoring mistake.

## Not here yet

The run-inspection component, `FlowRun`, lands in Plan 4. The
`nodeflow:install` command lands in Plan 5 and will verify all five host-wiring
requirements. Until then these five steps are manual, and **three of the five fail
quietly**.
