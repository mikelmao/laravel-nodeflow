# Route reference

Nodeflow registers eleven authenticated routes when the host calls `Nodeflow::routes()` and one separate public webhook route when the host calls `Nodeflow::webhookRoutes()`. Neither method declares a prefix, domain, or middleware; the host owns those boundaries.

## Mount routes

```php
use Illuminate\Support\Facades\Route;
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])
    ->prefix('admin/nodeflow')
    ->group(fn () => Nodeflow::routes());

Route::middleware(['api', 'throttle:webhooks'])
    ->domain('hooks.example.com')
    ->group(fn () => Nodeflow::webhookRoutes());
```

The authenticated group requires the host's Inertia page shell and authorization gates. The public webhook group should use an API/CSRF-appropriate middleware stack, strict domain, host rate limiting, trusted proxy configuration, and any network controls the application needs. HMAC authentication remains mandatory regardless of middleware.

Hosts may apply route-name prefixes. Nodeflow resolves authenticated URLs by canonical suffix, and a uniquely prefixed public webhook name can still produce an endpoint URL. If a domain group has unresolved parameters, publication succeeds but returns a null URL; the host can supply its own resolved URL later.

## Authenticated editor and run routes

| Method | Relative URI | Canonical name | Ability |
| --- | --- | --- | --- |
| `GET` | `flows/{flow}/edit` | `nodeflow.flows.edit` | `update` / `nodeflow.update` |
| `PUT` | `flows/{flow}/draft` | `nodeflow.flows.draft` | `update` / `nodeflow.update` |
| `POST` | `flows/{flow}/validate` | `nodeflow.flows.validate` | `publish` / `nodeflow.publish` |
| `POST` | `flows/{flow}/publish` | `nodeflow.flows.publish` | `publish` / `nodeflow.publish` |
| `POST` | `flows/{flow}/webhook-secret/rotate` | `nodeflow.webhooks.secret.rotate` | `update` / `nodeflow.update` |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `nodeflow.fields.options` | `update` / `nodeflow.update` |
| `GET` | `flows/{flow}/trigger-nodes/{type}/fields/{field}/options` | `nodeflow.trigger-fields.options` | `update` / `nodeflow.update` |
| `GET` | `flows/{flow}/trigger-nodes/{type}/sources/{source}/fields/{field}/options` | `nodeflow.trigger-source-fields.options` | `update` / `nodeflow.update` |
| `GET` | `runs/{run}` | `nodeflow.runs.show` | `view` / `nodeflow.viewAny` |
| `GET` | `runs/{run}/overlay` | `nodeflow.runs.overlay` | `view` / `nodeflow.viewAny` |
| `GET` | `runs/{run}/nodes/{node}/subjects` | `nodeflow.runs.subjects` | `view` / `nodeflow.viewAny` |

Tenant-scoped route binding returns `404` before authorization for foreign flow/run IDs. Undefined host gates deny access. Option routes accept stable registered type/source/field keys, never PHP class names. Unknown, incompatible, non-dynamic, or undeclared choices return `404`; an empty dynamic source returns `{"options":{}}`.

## Authoring responses

Draft save returns the next revision:

```json
{"draft_revision": 3}
```

Validation is non-mutating. Success is `{"valid":true,"warnings":[]}`. Semantic failure is `422` with `message` set to `The flow is not ready to publish.`, plus `errors` and structured `node_errors`; it does not save a draft or create a version. Publish always validates again.

Publish requires `graph` and nonnegative `draft_revision`. Normal success returns:

```json
{"version": 2, "draft_revision": 3}
```

First webhook publication may additionally include `webhook_url` and the one-time `webhook_secret`. When a secret is present the response is `no-store`/`no-cache`. A later publication never reveals the existing secret. Stale draft/publish requests return `409` with the winning graph and revision.

Secret rotation returns only the new `secret` and `rotated_at`, with no-store headers. It requires an existing webhook endpoint and the flow `update` ability.

## Public webhook route

| Method | Relative URI | Canonical name | Authentication |
| --- | --- | --- | --- |
| `POST` | `hooks/{token}` | `nodeflow.webhooks.receive` | Token lookup plus required timestamped HMAC |

Required headers are `X-Nodeflow-Timestamp`, `X-Nodeflow-Signature`, and `Idempotency-Key`. The signature is HMAC-SHA256 over the exact timestamp, a dot, and the exact raw request body. See [Writing triggers](../building-automations/writing-triggers.md#sign-and-send-the-request) for construction and limits.

The protocol returns `202 Accepted` with `run_id` and `duplicate`; `404` for unknown/inactive/non-webhook tokens; `401` for signature/replay failure; `413` for body size; `422` for idempotency, JSON, or source-audience rejection; and `503` for verification/source/dispatch infrastructure failure. Retry `503` using the identical delivery identity. Public responses never include raw source errors, bodies, tokens, signatures, or secrets.

## Server-authored editor URLs

The editor receives resolved `draft`, `validate`, `publish`, `rotate_webhook_secret`, executable `options`, trigger `trigger_options`, and `trigger_source_options` URLs. Host wrappers must consume these props; do not hardcode package paths or assume an unprefixed route name.

See [Routes and Inertia](../integration/routes-and-inertia.md) for the host page setup and [Authorization](../integration/authorization.md) for gate signatures.
