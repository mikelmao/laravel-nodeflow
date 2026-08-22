# Route reference

Nodeflow registers seven routes when the host calls `Nodeflow::routes()`. The package declares neither a URL prefix, middleware, domain, nor authentication; the containing host route group owns all of those choices.

## Register the routes

**Outcome:** this simple, unprefixed-name setup exposes the editor and run view below `/nodeflow` while leaving the canonical names unchanged.

```php
use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('nodeflow')
    ->group(fn () => Nodeflow::routes());
```

Use middleware appropriate to the host; the package policies do not authenticate a request on their own. The host also owns the page shell required by the returned Inertia pages.

## Routes

The names below are the canonical, unprefixed names registered by the package. `FlowPolicy` delegates `update` and `publish` to the similarly named host gates. `RunPolicy::view` delegates to `nodeflow.viewAny`. An undefined gate denies access.

| Method | Relative URI | Canonical name | Response | Controller purpose | Policy ability / host gate |
| --- | --- | --- | --- | --- | --- |
| `GET` | `flows/{flow}/edit` | `nodeflow.flows.edit` | Inertia `nodeflow/editor` page | Supplies the flow, draft-or-current graph, palette, triggers, and server-authored URLs. | `update` / `nodeflow.update` |
| `PUT` | `flows/{flow}/draft` | `nodeflow.flows.draft` | JSON | Structurally validates and saves a draft with its revision token. | `update` / `nodeflow.update` |
| `POST` | `flows/{flow}/publish` | `nodeflow.flows.publish` | JSON | Validates and publishes an immutable flow version. | `publish` / `nodeflow.publish` |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `nodeflow.fields.options` | JSON | Resolves a registered node field's dynamic options. | `update` / `nodeflow.update` |
| `GET` | `runs/{run}` | `nodeflow.runs.show` | Inertia `nodeflow/run` page | Supplies a run's pinned graph, overlay, palette, and server-authored URLs. | `view` / `nodeflow.viewAny` |
| `GET` | `runs/{run}/overlay` | `nodeflow.runs.overlay` | JSON | Returns only the current overlay snapshot. | `view` / `nodeflow.viewAny` |
| `GET` | `runs/{run}/nodes/{node}/subjects` | `nodeflow.runs.subjects` | JSON | Cursor-paginates active subjects at one node of the pinned graph. | `view` / `nodeflow.viewAny` |

The options route accepts a node *type* and field *key*, never a class name. Unknown types, undeclared fields, and fields without a dynamic option source return `404`; an options response is `{"options": {}}` when the source has no options.

## Host URL and name prefixes

The host may add a URI prefix, middleware, domain, and a consistent route-name prefix on the same containing group. For example:

```php
Route::middleware(['web', 'auth'])
    ->domain('{account}.example.test')
    ->prefix('admin')
    ->name('admin.')
    ->group(fn () => Nodeflow::routes());
```

That group registers names such as `admin.nodeflow.flows.edit` and `admin.nodeflow.runs.show`. A request to either Inertia page recovers its own leading name prefix and uses it for sibling URLs, so its `urls` properties continue to point at the host's routes. This support requires the package routes to be registered together under one consistent prefix; do not generate client URLs from the canonical names or hard-code a `/nodeflow` path. Consume the `urls` sent by the editor or run page, including the `__NODEFLOW_TYPE__`, `__NODEFLOW_FIELD__`, and `__NODEFLOW_NODE__` replacement sentinels.

## Binding and authorization behavior

`{flow}` binds through `Flow` and `{run}` through `Run`. With a non-null resolved tenant, both models are tenant-scoped before controller authorization runs. A row from another tenant therefore gives `404`, not `403`, so the response does not reveal that it exists. A same-tenant row which fails the policy gives `403`.

The meaning of a null tenant depends on `nodeflow.tenancy`: the default `auto` mode is unscoped only while the package fallback resolver is installed; `disabled` is unscoped; and `resolver` refuses the scoped read. A non-null tenant always scopes. See [Tenancy](../integration/tenancy.md) for the resolver and mode contract.

`{node}` is a graph node ID, not a database record ID. The subjects endpoint authorizes the route-bound run, then checks that the ID exists in that run's pinned graph before querying. An absent node, including one that exists only in another run's graph, returns `404`; an existing node with no active subjects returns an empty page.

## Error responses

Malformed draft and publish payloads use Laravel validation responses. A stale draft returns `409` with `message`, a graph-shaped `graph`, and `draft_revision`. A semantically invalid publish returns `422` with `message`, `errors`, and `node_errors`; see [Graph format](graph-format.md#drafts-publishing-and-errors) for the payload rules and error shape.

## Next step

Use [Graph format](graph-format.md) to build draft and publish payloads, then [Inspecting runs](../editor-and-run-view/inspecting-runs.md) for the run-view response model.
