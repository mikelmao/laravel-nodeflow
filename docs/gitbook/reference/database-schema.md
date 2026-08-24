# Database schema

The bundled migration creates eight `nodeflow_` tables. The prefix is baked into the current migration; `nodeflow.tables.prefix` is not used to rename an installed schema.

## `nodeflow_flows`

| Column | Shape and meaning |
| --- | --- |
| `id` | Primary key |
| `tenant_id` | Required string; indexed; filled/scoped by `BelongsToTenant` |
| `name` | Required string |
| `status` | Required string, default `draft`; publication writes `active` |
| `reentry_policy` | Required string, default `reenter` |
| `current_version_id` | Nullable version pointer; intentionally no database foreign key |
| `draft_graph` | Nullable JSON working graph |
| `draft_updated_at` | Nullable timestamp for display |
| `draft_revision` | Unsigned integer compare-and-swap token, default `0` |
| `created_at`, `updated_at` | Laravel timestamps |

Indexes are `tenant_id` and `(tenant_id, status)`. Eloquent validates that `current_version_id`, when present, names a version belonging to this same flow and tenant. Query-builder/raw writes bypass that model guard.

## `nodeflow_flow_versions`

| Column | Shape and meaning |
| --- | --- |
| `id` | Primary key |
| `tenant_id` | Required indexed string, inherited from the parent flow |
| `flow_id` | Foreign key to flows, cascade on delete |
| `version` | Unsigned per-flow sequence |
| `graph` | Required JSON immutable published graph |
| `content_hash` | Required string SHA-256 of the stored JSON encoding |
| `published_at` | Nullable timestamp |
| `published_by` | Nullable string actor identity |
| `created_at`, `updated_at` | Laravel timestamps |

`(flow_id, version)` is unique. The model validates the parent tenant on creation and prevents event-firing changes to `flow_id`. Package services treat graph/version content as immutable; the database does not prevent raw updates.

## `nodeflow_trigger_activations`

One row is the current compiled trigger snapshot for one active flow publication.

| Column | Shape and meaning |
| --- | --- |
| `id` | Primary key |
| `flow_id` | Unique foreign key to flows, cascade on delete |
| `flow_version_id` | Unique foreign key to versions, cascade on delete |
| `tenant_id` | Required indexed string copied from the flow |
| `driver` | Required stable key, max 191; indexed |
| `source` | Required stable key, max 191; indexed |
| `qualifier` | Nullable stable routing value, max 191; indexed |
| `trigger_node_id` | Required graph node ID |
| `descriptor` | Required JSON compiled metadata supplied to the source |
| `created_at`, `updated_at` | Laravel timestamps |

The composite routing index is `(driver, source, qualifier)`. MySQL/MariaDB use binary collation on those three routing columns so differently cased bytes cannot alias. The model verifies flow/version/tenant consistency on creation and rejects every event-firing update to routing or ownership fields. Publication replaces this row instead of mutating it.

## `nodeflow_webhook_endpoints`

| Column | Shape and meaning |
| --- | --- |
| `id` | Primary key |
| `flow_id` | Unique foreign key to flows, cascade on delete |
| `token` | Unique stable 64-character lowercase-hex endpoint token |
| `signing_secret` | Required encrypted text; hidden from array/JSON serialization |
| `secret_rotated_at` | Nullable timestamp |
| `created_at`, `updated_at` | Laravel timestamps |

The Eloquent `encrypted` cast encrypts the signing secret at rest with the Laravel application key. Nodeflow returns plaintext only on initial creation or explicit authorized rotation. Endpoint `flow_id` and `token` are immutable through event-firing writes; rotation changes only secret material and timestamp.

The endpoint row is stable across webhook publications. It can exist even when the host has not mounted a resolvable public route; in that case the publication URL is null.

## `nodeflow_runs`

| Column | Shape and meaning |
| --- | --- |
| `id` | Primary key |
| `flow_version_id` | Required foreign key to the exact published version; no cascade delete |
| `tenant_id` | Required indexed string |
| `correlation_id` | Nullable indexed host correlation/sub-flow lineage |
| `engine_workflow_id` | Nullable indexed durable-engine handle |
| `engine_entry_node_id` | Nullable persisted executable start intent; immutable after creation |
| `engine_dispatch_status` | Nullable indexed `pending`, `dispatched`, or `failed` dispatch state |
| `engine_dispatch_error` | Nullable sanitized dispatch failure text |
| `strategy` | Required string (`subject` or `cohort` in package starts) |
| `status` | Required string, default `pending` |
| `is_test` | Required boolean, default false |
| `idempotency_key` | Nullable string occurrence identity hash/raw manual key |
| `started_via` | Required origin (`manual`, `subflow`, or driver key) |
| `trigger_node_id` | Required trigger graph node ID |
| `trigger_data` | Nullable JSON source-owned value snapshot |
| `steps_taken` | Unsigned integer, default `0` |
| `error` | Nullable run-level text |
| `started_at`, `ended_at` | Nullable timestamps |
| `created_at`, `updated_at` | Laravel timestamps |

`(flow_version_id, idempotency_key)` is unique. SQL permits multiple null keys, so starts without an identity are not deduplicated. Creation validates version/tenant consistency. Dispatch intent is committed with the run and audience before durable engine start; a failed start retains the row for `RetryRunDispatch`/`CreateRun::resume()` recovery.

## Supporting tables

- `nodeflow_run_subjects` stores one unique `(run_id, subject_type, subject_id)` current-state row, cascades with the run, and indexes `(run_id, current_node_id, status, id)` for cursor pagination.
- `nodeflow_node_executions` stores aggregate execution records, cascades with the run, and indexes `(run_id, node_id)`.
- `nodeflow_templates` stores global or tenant templates and indexes nullable `tenant_id`.

`RunSubject`, `NodeExecution`, and `WebhookEndpoint` do not carry their own tenant column/scope. Reach them through a tenant-authorized parent flow/run. For tenancy invariants and event-bypass warnings, see [Tenancy](../integration/tenancy.md).
