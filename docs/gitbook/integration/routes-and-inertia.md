# Routes and Inertia

Mount the authenticated Nodeflow editor and run view under your application's URL and middleware conventions while keeping the package's server-authored props intact.

## Register the routes

Call `Nodeflow::routes()` inside the host route group. The package does not add middleware, a prefix, or a domain, so the host remains responsible for all three.

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('admin')
    ->group(function (): void {
        Nodeflow::routes();
    });
```

The `auth` middleware is intentionally host-owned: package controllers authorize an already authenticated actor, but do not choose how your application authenticates one. Define the Nodeflow gates described in [Authorization](authorization.md) before exposing this group.

The call registers these seven routes. Their canonical names are shown below; the controller authorizes the model before it sends any page or JSON payload.

| Method | URI relative to the group | Canonical name | Controller purpose | Required policy gate |
| --- | --- | --- | --- | --- |
| `GET` | `flows/{flow}/edit` | `nodeflow.flows.edit` | Renders the editor's Inertia props. | `update` (`nodeflow.update`) |
| `PUT` | `flows/{flow}/draft` | `nodeflow.flows.draft` | Saves a structurally valid draft. | `update` (`nodeflow.update`) |
| `POST` | `flows/{flow}/publish` | `nodeflow.flows.publish` | Publishes a graph and returns validation errors when needed. | `publish` (`nodeflow.publish`) |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `nodeflow.fields.options` | Resolves a declared dynamic field's options. | `update` (`nodeflow.update`) |
| `GET` | `runs/{run}` | `nodeflow.runs.show` | Renders the read-only run view's Inertia props. | `view` (`nodeflow.viewAny`) |
| `GET` | `runs/{run}/overlay` | `nodeflow.runs.overlay` | Returns the current run-overlay snapshot for polling. | `view` (`nodeflow.viewAny`) |
| `GET` | `runs/{run}/nodes/{node}/subjects` | `nodeflow.runs.subjects` | Returns active subjects at a pinned-graph node. | `view` (`nodeflow.viewAny`) |

Use the unprefixed canonical route names as the simplest integration. Do not add a host route-name prefix merely to namespace Nodeflow: the package already owns its canonical `nodeflow.*` names. A containing `Route::name('admin.')` group is supported when you need it; controllers recover that prefix from the matched route and use it for their sibling URLs. Apply it consistently to the group containing `Nodeflow::routes()`.

> **Warning:** Do not hand-write or reconstruct a package URL in JavaScript. The controllers resolve the URLs after the host prefix and any supported route-name prefix have been applied, then send them in `urls` props.

## Preserve tenant reachability

When a non-null tenant is resolved, `{flow}` and `{run}` use the package models' tenant-scoped route-model binding. In that scoped case, an ID from another tenant is not reachable and returns **404** before controller authorization. A reachable row that the current actor may not operate returns **403** from the policy gate. Keep both boundaries: tenant scoping avoids revealing a row exists, while the policy implements your application's roles and permissions.

That 404 isolation guarantee depends on an active tenant scope. In `disabled` mode, and in `auto` mode when the package's no-tenancy resolver is in use, reads with a null tenant are unscoped. In `auto` or `resolver` mode, a bound custom resolver that returns null throws `TenancyUnresolvedException` instead. Configure and bind the resolver deliberately; see [Tenancy](tenancy.md).

The `{node}` segment in the subjects endpoint is not a database ID. It must name a node in that run's pinned graph; an unknown node returns 404 rather than an ambiguous empty subject list.

## Add the Inertia page adapters

The package owns the controllers, prop shapes, and endpoint URLs. The host owns the layout, Inertia resolver, and the pages named by `Inertia::render('nodeflow/editor')` and `Inertia::render('nodeflow/run')`. Place lower-case `nodeflow/editor.tsx` and `nodeflow/run.tsx` beneath the page root and casing your resolver already uses. For a resolver rooted at lower-case `resources/js/pages`, the files are `resources/js/pages/nodeflow/editor.tsx` and `resources/js/pages/nodeflow/run.tsx`; a `resources/js/Pages` or custom-root resolver must retain that configured root and casing.

```tsx
import { FlowEditor, type FlowEditorProps } from '@nodeflow/editor'

export default function Editor(props: FlowEditorProps) {
    return <FlowEditor {...props} />
}
```

```tsx
import { FlowRun, type FlowRunProps } from '@nodeflow/editor'

export default function Run(props: FlowRunProps) {
    return <FlowRun {...props} />
}
```

These intentionally thin adapters let the host wrap either page in its application layout or error boundary without giving the package an Inertia dependency. Configure the alias and dependencies first in [Frontend setup](frontend-setup.md).

## Next step

Set up the Vite, TypeScript, Tailwind, and React dependencies in [Frontend setup](frontend-setup.md).
