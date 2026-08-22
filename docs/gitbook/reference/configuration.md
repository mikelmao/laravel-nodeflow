# Configuration reference

Nodeflow has nine current leaf keys across five configuration groups. Publish the package configuration when you want an application-owned copy, then clear and rebuild Laravel's configuration cache after changing it.

```bash
php artisan vendor:publish --tag=nodeflow-config
php artisan config:clear
php artisan config:cache
```

## All keys

| Path | Default | Accepted value | Environment value | Runtime effect and when to change it |
| --- | --- | --- | --- | --- |
| `nodeflow.tables.prefix` | `'nodeflow_'` | **No current runtime contract.** The runtime never reads or validates this key, so every configured value is inert. | None. | **Currently configuration-only.** The shipped migrations and the one raw insert use literal `nodeflow_*` table names, so changing this does not rename or redirect tables. Leave the default unless the package implementation and migrations are changed together. |
| `nodeflow.retention.runs_days` | `90` | Any value Laravel can return; not validated here. `nodeflow:prune` casts its selected value to `int`. | None. | Default retention window for `nodeflow:prune` when `--days` is falsey, including omitted, empty, or literal `0`; only a truthy supplied option overrides it. Set it to the intended age, in calendar days, for terminal runs. |
| `nodeflow.retention.node_executions_days` | `90` | Any value; not validated. | None. | **Currently configuration-only.** Pruning deletes node executions with their selected parent run and never reads this key. Keep it aligned with `runs_days` only as a statement of intended policy; it does not independently retain executions. |
| `nodeflow.limits.max_steps_per_run` | `1000` | Integer-like value; it is cast to `int` when a run starts and is otherwise not validated. | None. | Caps interpreter node steps for each newly started engine workflow. Each `RunNodeStep`/node activity counts, including multiple nodes in the same cursor round. Reaching the cap stops the loop without an explicit error, then the completion activity marks the run completed; subjects already advanced into an unprocessed cursor can remain active. `0` or a negative value completes immediately without executing a node. Use a positive value above the worst-case legitimate node-activity count for a run, while retaining a loop guard. |
| `nodeflow.limits.subject_chunk` | `500` | Integer-like value; no package validation. | None. | Number of active subjects loaded per batch for nodes that implement only `HandlesSubject`. Raise it for throughput only when model resolution and per-subject work fit memory and downstream capacity. Zero or a negative value is unsafe for Laravel chunking. |
| `nodeflow.limits.audience_chunk` | `5000` | Integer-like value; no package validation. | None. | Number of active subjects passed to each `HandlesAudience` invocation. Tune for native batch APIs and memory use. Zero or a negative value is unsafe for Laravel chunking. |
| `nodeflow.limits.subject_page` | `50` | Integer-like value; it is cast to `int` for cursor pagination and is not validated. | None. | Page size for the run-view endpoint that lists active subjects at a node. Change for UI payload size and operator usability. Zero or a negative value is unsafe for pagination. |
| `nodeflow.tenancy` | `env('NODEFLOW_TENANCY', 'auto')` | Exactly the strings `'auto'`, `'disabled'`, or `'resolver'`. Values are case-sensitive. | `NODEFLOW_TENANCY`; absent means `'auto'`. Laravel's `env()` coercion can turn values such as `true` into a boolean, which is invalid rather than a mode. | Defines what a `null` tenant from `TenantResolver::currentTenantId()` means for scoped reads. Set `'resolver'` for a tenancy-aware host, especially if resolution can be absent in workers or console; set `'disabled'` only for a genuinely non-tenanted host. |
| `nodeflow.check_node_types_on_boot` | `false` | Boolean-like value; checked by PHP truthiness and not independently validated. | None. | When truthy, once per process at provider boot it scans live-run flow versions for node types that no longer resolve and logs errors. Failures of the scan itself are logged as warnings; boot continues. Enable in worker/deploy contexts where the query cost and diagnostic are worthwhile. |

## Tenancy modes

`tenancy` never changes the treatment of a non-null tenant ID: reads are scoped to that tenant in every valid mode. It controls only `null`.

| Value | `null` result | Use it when |
| --- | --- | --- |
| `'auto'` | If the container still has Nodeflow's fallback `NoTenancyResolver`, reads are unscoped. If the host bound its own resolver, the read throws `TenancyUnresolvedException`. | The default only when the host binds its resolver unconditionally during provider registration. |
| `'disabled'` | Reads are unscoped. | The application has no tenant boundary. |
| `'resolver'` | Reads throw `TenancyUnresolvedException`. | A missing tenant must fail closed, including in queues, console commands, and unauthenticated requests. |

An unknown, absent, differently cased, or non-string value throws `InvalidArgumentException` on scoped reads rather than falling back to an unscoped query. Do not bind a tenancy resolver only in middleware while relying on `'auto'`: a worker or console process can then see the fallback resolver and treat a missing tenant as non-tenanted. Bind it in a service provider or use `'resolver'`.

## Validation and caching notes

Only `tenancy` has runtime enum validation. The numeric limits and retention values are deliberately not range-checked by the current package. In particular, `nodeflow:prune --days` overrides `runs_days`, casts without validation, and should be previewed with `--dry-run`; a nonnumeric value becomes `0` and a negative value creates a future cutoff.

`NODEFLOW_TENANCY` is read while Laravel loads configuration. With `config:cache`, editing `.env` alone has no effect until the cache is rebuilt. Published configuration is merged with package defaults, but a stale cached configuration that lacks `nodeflow.tenancy` is rejected on scoped reads; clear the cache before retrying.

## Next step

Use [Pruning and retention](../operations/pruning-and-retention.md) to schedule retention safely, and [Tenancy](../integration/tenancy.md) to bind the required resolver.
