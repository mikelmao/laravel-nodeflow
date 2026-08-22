# Queues and workers

Use a non-`sync` Laravel queue connection and keep workers running. Nodeflow starts `FlowInterpreter` through the installed Durable Workflow v2 runtime, whose task dispatch mode defaults to `queue`; without a worker, a run can be created but will not execute its activities.

> **Experimental:** This guide describes the embedded `durable-workflow/workflow` runtime pinned by this package. It does not configure or support a particular container platform, process manager, or Horizon topology.

## Install both sets of migrations

Nodeflow loads its migrations automatically and `nodeflow:install --publish-migrations` can publish a host copy. The Durable Workflow package also loads and can publish its own migrations. Run normal Laravel migrations after installing both packages:

```bash
php artisan nodeflow:install --publish-migrations
php artisan migrate
```

`nodeflow:install` verifies Nodeflow's host wiring; it does not replace the durable engine's schema. The durable package stores its own workflow instances, runs, histories, tasks, timers, and activity records. By default that package uses Laravel's default database connection; its `workflows.storage.connection` setting can direct all of its persistence and migrations to a dedicated configured connection.

## Start a worker

For local development, configure a real queue driver and run a Laravel worker in a second terminal:

```dotenv
QUEUE_CONNECTION=database
```

Nodeflow and Durable Workflow migrations do not create Laravel's `jobs` or `failed_jobs` tables. If this Laravel application does not already have those queue migrations, generate and run them first:

```bash
php artisan make:queue-table
php artisan make:queue-failed-table
php artisan migrate
```

For Redis, SQS, or another queue backend, provision that backend and its Laravel connection according to the host application's normal queue setup.

```bash
php artisan queue:work
```

For a production Laravel queue, run the normal worker command under your process supervisor. If the application uses named queues, make the worker listen to the queues selected by its Laravel and durable-workflow configuration:

```bash
php artisan queue:work --queue=default --tries=3
```

Horizon is a Laravel queue worker manager, so it can manage the queue workers used by the embedded runtime when it is already part of the host application. Configure and supervise Horizon according to Laravel's documentation; this package does not ship a Horizon configuration, queue name, or worker count.

By default each Laravel queue worker also performs the durable engine's repair/broad-poll pass on its queue loop. If the durable configuration disables `workflows.v2.matching_role.queue_wake_enabled`, run its supported dedicated role instead:

```bash
php artisan workflow:v2:repair-pass --loop
```

Do not run both arrangements casually. Choose the normal in-worker matching role or deliberately operate the dedicated repair loop, then observe the result with the dependency doctor command.

## Verify a missing-worker symptom

After an authorized start, Nodeflow commits a `pending` run and its subjects before it asks the engine to start. A missing worker commonly leaves the run with no node execution records and no advancing subjects; it may remain `pending` until the engine's load activity runs.

Verify the worker and backend rather than attempting to “replay” the Nodeflow run manually:

```bash
php artisan workflow:v2:doctor --strict
```

When the host uses Laravel's failed-job storage and has its `failed_jobs` table, also run `php artisan queue:failed`.

The doctor reports the durable v2 database, queue, cache, codec, matching role, and local topology. `--strict` exits non-zero when required capabilities are missing. It is separate from `nodeflow:install --check` and from Nodeflow's node-type check.

For an individual run, use the authorized run view. It exposes the run's pinned graph and per-node overlay without accepting arbitrary child-record IDs. Do not diagnose by querying `nodeflow_run_subjects` or `nodeflow_node_executions` unscoped: those rows are isolated through their parent run.

## Treat the database and cache differently

The durable engine's shared database is the correctness substrate in a multi-node deployment: task discovery, claims, lease expiry, and repair are durable database operations. All nodes must reach the same durable workflow database. Nodeflow's own flows, versions, runs, subjects, and node executions also require their migrated database tables.

The engine's cache-backed long-poll wake store is an acceleration layer. It shortens task discovery but does not decide correctness. In a multi-node deployment, Redis, a shared database cache, or Memcached lets every node observe wake signals. File and array cache cannot propagate them across nodes, so work remains correct but can wait for the durable polling and repair cadence.

For a single node, file cache is acceptable. For more than one node, set the durable runtime's multi-node setting and use a shared cache store to recover fast wakeups. Its boot-time cache validation is warning-only; confirm the effective configuration with:

```bash
php artisan workflow:v2:doctor
```

## Deploy long-running work deliberately

Before restarting workers, deploy code that can still resolve every node type used by live runs. A run may resume days later from its pinned graph, so deleting or renaming a type can fail its next node activity. Keep a direct `NodeRegistry::alias()` for a renamed type when appropriate, then run:

```bash
php artisan nodeflow:check-node-types
```

Run database migrations once in the deployment sequence before rolling application and worker processes. Restart workers only after code and migrations are compatible. The node-type check and aliases are prevention, not recovery: an already failed durable execution requires history and engine-state inspection plus an application-defined repair or a safe new idempotent run after its root cause is fixed. The durable dependency has worker-compatibility and replay facilities, but their configuration is optional and version-specific; do not claim zero-downtime compatibility merely by restarting a generic Laravel worker.

A practical release order is:

1. Run application and durable-workflow migrations once.
2. Deploy the release with all live node types or their direct aliases.
3. Run `nodeflow:check-node-types` and `workflow:v2:doctor --strict` on the release.
4. Restart or roll the queue-worker fleet using the host's normal process manager.
5. Observe a known authorized run through its run view and worker logs.

## Next step

Add deploy gates and startup diagnostics in [Health checks](health-checks.md).
