# Configuration reference

Nodeflow ships six top-level entries: four nested groups and two scalar settings. Together they contain twelve leaf keys. Publish an application-owned copy only when you need to change a value, then rebuild Laravel's configuration cache.

```bash
php artisan vendor:publish --tag=nodeflow-config
php artisan config:clear
php artisan config:cache
```

## All keys

| Path | Default | Accepted value | Environment value | Runtime effect and when to change it |
| --- | --- | --- | --- | --- |
| `nodeflow.tables.prefix` | `'nodeflow_'` | **No current runtime contract.** The runtime does not read or validate this key. | None | **Currently configuration-only.** Shipped migrations and SQL use literal `nodeflow_*` names, so changing the value does not rename tables. |
| `nodeflow.retention.runs_days` | `90` | Any value; `nodeflow:prune` casts the selected value to `int`. | None | Default age in calendar days for pruning terminal runs when `--days` is omitted or falsey. Preview unusual values with `--dry-run`. |
| `nodeflow.retention.node_executions_days` | `90` | Any value; not independently validated. | None | **Currently configuration-only.** Node executions are deleted with their selected parent runs; this key is not read as an independent retention window. |
| `nodeflow.limits.max_steps_per_run` | `1000` | Integer-like value; cast to `int` when a run starts, with no positive-range validation. | None | Maximum interpreter node activities for a newly started workflow. Use a positive value large enough for legitimate loops; zero or a negative value prevents node execution. |
| `nodeflow.limits.subject_chunk` | `500` | Integer-like value; no package validation. | None | Active subjects loaded per batch for nodes implementing only `HandlesSubject`. Zero or a negative value is unsafe for Laravel chunking. |
| `nodeflow.limits.audience_chunk` | `5000` | Integer-like value; no package validation. | None | Active subjects passed to each `HandlesAudience` invocation. Zero or a negative value is unsafe for Laravel chunking. |
| `nodeflow.limits.subject_page` | `50` | Integer-like value; cast to `int` for cursor pagination, with no positive-range validation. | None | Page size for the run-view active-subject endpoint. Zero or a negative value is unsafe for pagination. |
| `nodeflow.limits.trigger_data_bytes` | `65_536` | A positive integer or a digit-only positive integer string. Other strings, zero, and negatives are rejected. | None | Maximum byte length of JSON-encoded `trigger_data` at run creation. Raise it only after reviewing storage, observability, and sensitive-data exposure. |
| `nodeflow.webhooks.replay_window_seconds` | `300` | Positive integer. Numeric strings are rejected. | None | Maximum absolute age of `X-Nodeflow-Timestamp` for a signed webhook. A bad configured value makes signature verification unavailable rather than weakening replay protection. |
| `nodeflow.webhooks.max_body_bytes` | `1_048_576` | Positive integer. Numeric strings are rejected. | None | Raw webhook body limit enforced before HMAC calculation and JSON decoding. Oversized requests receive `413`; a bad configured value fails safely. |
| `nodeflow.tenancy` | `env('NODEFLOW_TENANCY', 'auto')` | Exactly the case-sensitive string `'auto'`, `'disabled'`, or `'resolver'`. | `NODEFLOW_TENANCY` | Defines what a `null` result from `TenantResolver::currentTenantId()` means for scoped reads. |
| `nodeflow.check_node_types_on_boot` | `false` | Checked by PHP truthiness; not independently validated as a strict boolean. | None | When truthy, schedules the trigger-aware component resolver once per process after the application has booted. Findings are logged and do not fail boot. |

No shipped setting other than `nodeflow.tenancy` reads an environment variable directly. You may add host-specific `env()` calls to a published config file, but that becomes application configuration rather than a package-defined variable.

## Trigger and webhook limits

`trigger_data_bytes` is the one numeric setting that deliberately accepts a digit-only string, which is useful when a published host config reads an environment value. Nodeflow casts that form to an integer, requires a value greater than zero, JSON-encodes the source-owned array with exceptions enabled, and compares the encoded byte length with the limit. A non-array, non-null value or non-JSON-safe data is rejected separately.

The two `webhooks` settings are stricter: each must already be a PHP integer greater than zero. Numeric strings are rejected. `replay_window_seconds` controls timestamp verification; `max_body_bytes` controls the raw request-body check performed before signature work. See [Writing triggers](../building-automations/writing-triggers.md) for the signed-message format, response semantics, route hardening, and secure logging rules. Unsigned webhooks are not supported.

## Tenancy modes

`tenancy` never changes a non-null tenant ID: every valid mode scopes reads to that tenant. It controls only `null`.

| Value | `null` result | Use it when |
| --- | --- | --- |
| `'auto'` | Reads are unscoped only while the container still holds Nodeflow's fallback `NoTenancyResolver`; a host-bound resolver returning `null` throws `TenancyUnresolvedException`. | The default when the host binds its resolver unconditionally during provider registration. |
| `'disabled'` | Reads are unscoped. | The application genuinely has no tenant boundary. |
| `'resolver'` | Reads throw `TenancyUnresolvedException`. | Missing tenant context must fail closed in HTTP, queues, and console commands. |

An unknown, absent, differently cased, or non-string value throws `InvalidArgumentException` on a scoped read. Do not bind a resolver only in middleware while relying on `'auto'`: a queue or console process may still hold the fallback resolver and interpret `null` as a non-tenanted host. Bind the resolver in a service provider or use `'resolver'`. Review [Tenancy](../integration/tenancy.md) and [Authorization](../integration/authorization.md) together; tenancy scoping does not replace authorization gates.

## Boot health check

When `check_node_types_on_boot` is truthy, Nodeflow uses Laravel's `booted` callback so host providers can register components first. Once per process it checks active trigger activations for their pinned trigger node, driver, and source. It also checks versions pinned by live `pending`, `running`, `waiting`, or `blocked` runs: all executable downstream node types, plus trigger components for trigger-origin live runs. Manual and sub-flow runs bypass trigger matching, so they require only their executable pinned types unless the same version is also live through a trigger origin.

Each missing registration is logged as an error and a resolver/query exception is logged as a warning; neither aborts application boot. Because long-lived processes run it only once, use `php artisan nodeflow:check-node-types` as the authoritative deployment check. [Health checks](../operations/health-checks.md) documents identities, aliases, remediation, and the exact command output.

## Validation and caching notes

The remaining numeric limits and retention values are not positive-range validated by the package. In particular, only a truthy `nodeflow:prune --days` option overrides `runs_days`; omitted, empty, and literal `0` values fall back to configuration. A truthy nonnumeric option casts to zero, and a negative value creates a future cutoff. Use `--dry-run` before applying an unusual retention value.

`NODEFLOW_TENANCY` is read while Laravel loads configuration. With `config:cache`, editing `.env` alone has no effect until the cache is rebuilt. A stale cached configuration without `nodeflow.tenancy` is rejected on scoped reads rather than silently treated as unscoped.

## Next step

Use [Pruning and retention](../operations/pruning-and-retention.md) to schedule data removal, and audit trigger payloads with the security guidance in [Writing triggers](../building-automations/writing-triggers.md).
