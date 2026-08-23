# Database schema reference

Nodeflow's shipped migration creates six tables with literal `nodeflow_` names. `nodeflow.tables.prefix` is currently inert: the migration, Eloquent models, and the runtime raw insert use these literal names, so changing that configuration value does not rename or redirect a database.

Only the foreign keys, unique constraints, and indexes explicitly declared by the migration are listed below. Do not assume an additional index from a database engine's foreign-key implementation. A declared foreign-key cascade takes effect only when the database enforces foreign keys; in particular, SQLite requires `foreign_keys` to be enabled. The package's SQLite test driver needs that enforcement setting too, and pruning explicitly removes child rows before runs for this reason.

## `nodeflow_flows`

Stores the editable flow and its pointer to the current published version.

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `tenant_id` | string, required; indexed |
| `name` | string, required |
| `trigger_type` | string, required |
| `trigger_config` | JSON, nullable |
| `status` | string, default `draft` |
| `reentry_policy` | string, default `reenter` |
| `current_version_id` | unsigned big integer, nullable; no foreign key or explicit index is declared |
| `draft_graph` | JSON, nullable |
| `draft_updated_at` | timestamp, nullable |
| `draft_revision` | unsigned integer, default `0` |
| `created_at`, `updated_at` | nullable timestamps |

The migration also declares the composite index `(tenant_id, trigger_type, status)`. A flow has many versions and has many runs through versions. `current_version_id` is set by publication from the just-created version; it is not database-enforced to belong to the same flow or tenant.

## `nodeflow_flow_versions`

Stores published graph snapshots. Package publication creates new versions and does not mutate their graphs, but the model and database do not block host updates or deletes; do not alter a version required by a run. See [Publishing flows](../building-automations/publishing-flows.md#know-what-publishing-changes).

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `tenant_id` | string, required; indexed |
| `flow_id` | unsigned big integer, required; foreign key to `nodeflow_flows.id`, cascade on delete |
| `version` | unsigned integer, required |
| `graph` | JSON, required |
| `content_hash` | string, required |
| `published_at` | timestamp, nullable |
| `published_by` | string, nullable |
| `created_at`, `updated_at` | nullable timestamps |

The unique constraint is `(flow_id, version)`. A version belongs to a flow and has many runs. Its creating hook requires its `tenant_id` to match the parent flow's tenant.

## `nodeflow_runs`

Stores one execution of one pinned flow version.

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `flow_version_id` | unsigned big integer, required; foreign key to `nodeflow_flow_versions.id` with no cascade declared |
| `tenant_id` | string, required; indexed |
| `correlation_id` | string, nullable; indexed |
| `engine_workflow_id` | string, nullable; indexed |
| `strategy` | string, required |
| `status` | string, default `pending` |
| `is_test` | boolean, default `false` |
| `idempotency_key` | string, nullable |
| `steps_taken` | unsigned integer, default `0` |
| `error` | text, nullable |
| `started_at`, `ended_at` | timestamps, nullable |
| `created_at`, `updated_at` | nullable timestamps |

The unique constraint is `(flow_version_id, idempotency_key)`. A null key provides no package-level idempotency: `StartRun` only looks up and recovers duplicate-key races for a non-null key. Supply a non-null stable key when duplicate run suppression is required. A run belongs to a flow version and has many run subjects and node executions.

## `nodeflow_run_subjects`

Stores each subject's current location and terminal result inside a run.

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `run_id` | unsigned big integer, required; foreign key to `nodeflow_runs.id`, cascade on delete |
| `subject_type` | string, required |
| `subject_id` | string, required |
| `current_node_id` | string, nullable |
| `status` | string, default `active` |
| `last_error` | text, nullable |
| `exited_at` | timestamp, nullable |

The unique constraint is `(run_id, subject_type, subject_id)`. The explicit index `(run_id, current_node_id, status, id)` supports the active-subject cursor drill-down. This table has no Laravel timestamps. It is a current-state table: terminal transitions clear `current_node_id`, and it contains no per-subject node-visit history.

## `nodeflow_node_executions`

Stores aggregate execution rows written by the node runner.

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `run_id` | unsigned big integer, required; foreign key to `nodeflow_runs.id`, cascade on delete |
| `node_id` | string, required |
| `output` | string, nullable |
| `subject_count` | unsigned integer, default `0` |
| `duration_ms` | unsigned integer, nullable |
| `error` | text, nullable |
| `created_at`, `updated_at` | nullable timestamps |

The explicit index is `(run_id, node_id)`. An output of `NULL` is the aggregate failure bucket; normal output rows aggregate subjects sent to that named output. These are aggregate rows, not a per-subject audit trail, so they cannot reconstruct which subject visited or failed at a node.

## `nodeflow_templates`

Stores reusable graph templates.

| Column | Type and default |
| --- | --- |
| `id` | unsigned big integer primary key |
| `scope` | string, required |
| `tenant_id` | string, nullable; indexed |
| `name` | string, required |
| `description` | text, nullable |
| `graph` | JSON, required |
| `version` | unsigned integer, default `1` |
| `created_at`, `updated_at` | nullable timestamps |

The migration declares no template foreign keys, unique constraints, or other explicit indexes. A null `tenant_id` is a global template row: when a tenant is resolved, template reads include rows for that tenant and global rows.

## Tenant scope and relation invariants

`Flow`, `FlowVersion`, `Run`, and `Template` have the tenant global scope. With a resolved non-null tenant, the first three match only that tenant; `Template` matches that tenant or `NULL` global rows. `RunSubject` and `NodeExecution` have no `tenant_id` and no global scope. Reach them through an already tenant-scoped `Run` relation; direct child-model queries are not tenant-scoped.

Several parent relations intentionally remove the tenant scope after the parent row has already been reached: `Flow::versions()`, `Flow::currentVersion()`, and `Run::flowVersion()`. Their safety depends on package writers deriving child foreign keys and tenant IDs from the same trusted flow. In particular, the database has no composite foreign key proving a flow version belongs to the run's tenant or that `current_version_id` belongs to its flow.

Tenant IDs are immutable for model-instance updates. The guard does not apply to query-builder `update()` calls, which bypass Eloquent model events; use scoped, trusted package writes rather than treating that bypass as a safe tenant move. See [Tenancy](../integration/tenancy.md) for scope modes and explicit `withoutTenancy()` reads.

## Retention and observability boundaries

These six tables are Nodeflow's package tables. Durable-workflow engine tables are separate infrastructure and are not created or pruned by this migration or by `nodeflow:prune`; configure their retention independently.

The run overlay combines the current subject rows with aggregate node-execution rows. It can show active subjects currently at a node, aggregate output counts, and aggregate failures, but not historical subject membership at a node or an individual failure's subject after the cursor is cleared. See [Statuses](statuses.md) for the exact lifecycle and pruning status rules.

## Next step

Read [Graph format](graph-format.md) for the JSON stored in flow versions and templates, then [Pruning and retention](../operations/pruning-and-retention.md) for run cleanup.
