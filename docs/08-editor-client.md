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
`min-h-[32rem]`—are absent. Utilities the host happens to use elsewhere can mask
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

The `nodeflow/editor` component name rendered by the package controller is relative
to the host's configured Inertia pages root. With a lowercase
`resources/js/pages` root, put the file at
`resources/js/pages/nodeflow/editor.tsx`. A conventional `resources/js/Pages`
host must instead use `resources/js/Pages/nodeflow/editor.tsx`, preserving the
configured casing. Inertia's resolver globs the host's pages root; it will never
find a page inside `vendor/`.

This thin page is three seams at once: the Inertia resolver entry, the place to
wrap the editor in the host's layout, and the place where host theming reaches the
package source. Wrap `FlowEditor` here, or let the host's global `layout` resolver
provide the layout.

## Register a custom field control

Declare the custom type in the node definition:

```php
Field::custom('destination', 'town')->options([
    'bucharest' => 'Bucharest',
    'cluj-napoca' => 'Cluj-Napoca',
])
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

## Run view

`FlowRun` renders a run's frozen graph with live per-node counts painted onto
it. It ships as a second export alongside `FlowEditor`, reuses the same
`Canvas`/`NodeCard`/`layout` primitives, and imports nothing from `editor/` —
there is no autosave, no dirty state and no publish path here, because a run
already executed and nothing about looking at it should be able to change it.

Wire it with a second thin page, the same shape as the editor's:

```tsx
// resources/js/pages/nodeflow/run.tsx
import { FlowRun } from '@nodeflow/editor'
import type { FlowRunProps } from '@nodeflow/editor'

export default function Page(props: FlowRunProps) {
    return <FlowRun {...props} />
}
```

`GET runs/{run}` renders the `nodeflow/run` Inertia component with that props
shape:

```ts
type FlowRunProps = {
    run: RunSummary
    graph: Graph
    palette: NodeTypePayload[]
    overlay: OverlaySnapshot
    urls: RunUrls
    nodeRenderers?: NodeRendererMap
    pollIntervalMs?: number
    className?: string
}
```

`graph` is `$run->flowVersion->graph` — **the run's pinned version, never
`draft_graph` and never `flow->currentVersion`.** A run executed a frozen
graph; the flow's draft or current version may have diverged from it since,
and painting live counts onto a graph the run never executed is exactly the
misreading this component exists to prevent. There is no way to ask `FlowRun`
for the draft instead — the prop is the only graph it can render.

### The overlay

`overlay` (and every polled response at `urls.overlay`) carries one entry per
node in that pinned graph:

```ts
type NodeOverlay = {
    reached: boolean
    byOutput: Record<string, number>
    waiting: number
    failed: number
    error: string | null
}
```

A node reads as **reached** when the run recorded an execution against it, or
when subjects are sitting on it right now. A node that ran, released nobody
and is now empty — `core.exit` is the common case — reads as never reached,
because the engine records no row for it. The counts are right; only that
node's dimming is misleading. See
[Execution model](05-execution-model.md#known-limitations) for why, and open
issue C-1 for the related caveat on when polling stops.

### Polling

`FlowRun` polls `urls.overlay` on a 5-second interval and stops once the
server reports the run as **terminal**. `terminal` travels in both the initial
prop and every polled response, computed server-side rather than hardcoded on
the client — today that means the run's status is `completed`, and nothing
else, so a run that dies leaves the client polling until the page is closed.

Failure policy: a 401, 403, 404 or 419 response halts polling — the run is
gone or the viewer's access was revoked, and retrying forever would just be
noise. A 5xx response or a network failure keeps polling while surfacing the
last error, rather than silently freezing a stale overlay.

### The canvas seam

`nodeRenderers` behaves exactly as it does for `FlowEditor` — a host does not
need it to use `FlowRun` at all. The one thing worth knowing in advance:
run decoration (dimming and badges) reaches `NodeCard` through a separate,
additive `nodeDecorations` prop on the canvas, keyed by node id rather than
node type. A host overriding a node's appearance keeps that decoration for
free; there is nothing to opt into or wire up.

### What's still manual

The five host-wiring requirements above are unchanged by any of this —
`FlowRun` shares the same Vite alias, tsconfig path, Tailwind `@source`,
`@xyflow/react` dependency and `dedupe` setting as `FlowEditor`, and adds no
sixth. The `nodeflow:install` command that verifies all five is still Plan 5
work.
