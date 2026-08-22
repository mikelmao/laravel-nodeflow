# Pruning and retention

`nodeflow:prune` deletes old terminal Nodeflow runs and their Nodeflow child records. It intentionally preserves every run that could still resume or be recovered.

## Run a preview first

The command signature is:

```bash
php artisan nodeflow:prune {--days=} {--dry-run}
```

Use `--dry-run` before enabling a schedule:

```bash
php artisan nodeflow:prune --days=90 --dry-run
```

`--days` is a number of calendar days. When omitted or empty, the command uses `nodeflow.retention.runs_days`, whose default is **90** days. A supplied CLI value overrides that configuration for this invocation. The default `nodeflow.retention.node_executions_days` is also 90 days, but the current command does **not** read it: node executions are retained or removed with their parent run.

> **Warning — current limitation:** `--days` is cast to an integer without validation. Use only a positive integer. A nonnumeric value becomes `0`; a negative value makes a future cutoff and can select far more terminal rows than intended. Always run the exact command and value with `--dry-run`, review its count, and only then run the live command or schedule it.

The cutoff is `now()->subDays($days)`. The command selects only rows whose `created_at` is strictly older than that cutoff; it does not prune by `ended_at`.

## Know what is deleted

Only these terminal run statuses are eligible: `completed`, `failed`, and `cancelled`. `pending`, `running`, `waiting`, and `blocked` are conservatively preserved at every age as nonterminal or application-reserved states. The current interpreter itself writes `pending`, `running`, and `completed`; it does not automatically turn a missing-type or activity failure into a Nodeflow `failed` or `blocked` row. The command nevertheless recognizes those terminal status values when application code has written them.

For each selected batch of 500 runs, the command explicitly deletes matching `nodeflow_run_subjects`, then matching `nodeflow_node_executions`, then deletes each `nodeflow_runs` row. It does not wrap the whole prune in one transaction. The foreign keys also declare cascade deletion, but explicit child deletion keeps pruning correct where SQLite foreign-key enforcement is disabled.

The command does not delete flows, flow versions, templates, or any durable-workflow runtime records. Retention is therefore a separate concern on each side:

| Records | Owner | Current retention behavior |
| --- | --- | --- |
| `nodeflow_runs`, `nodeflow_run_subjects`, `nodeflow_node_executions` | Nodeflow | `nodeflow:prune` deletes eligible terminal runs and their two child tables. |
| Flows, versions, templates | Nodeflow | Not deleted by `nodeflow:prune`. |
| Durable engine workflow/history/task tables | `durable-workflow/workflow` | Not deleted by `nodeflow:prune`; manage them with the pinned dependency's documented lifecycle and backup policy. |

If the host adds foreign keys or other records pointing at Nodeflow runs, account for their delete behavior before scheduling pruning. Cascades can delete host data; restrictive foreign keys can cause a batch to fail. Keep host-owned references intentional and test the policy on a copy of production-shaped data.

## Schedule the command

Add the command to the host application's scheduler and run Laravel's scheduler by the mechanism already used by the application:

```php
// Partial snippet: routes/console.php.

use Illuminate\Support\Facades\Schedule;

Schedule::command('nodeflow:prune --days=90')
    ->dailyAt('02:30')
    ->withoutOverlapping();
```

`withoutOverlapping()` is Laravel scheduler behavior; choose a lock-capable cache driver appropriate to the host scheduler deployment. The Nodeflow command itself is cross-tenant and uses `Run::withoutTenancy()` so a central scheduled process can prune all eligible tenants.

## Next step

Use [Troubleshooting](troubleshooting.md) to diagnose a retained blocked or waiting run before making a manual retention decision.
