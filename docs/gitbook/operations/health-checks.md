# Health checks

Use three separate checks: `nodeflow:install --check` verifies host integration, `nodeflow:check-node-types` protects active activations and live pinned graphs, and `workflow:v2:doctor` inspects the durable engine backend. None substitutes for the others.

## Verify installation wiring

Run this read-only command after installation and in a deployment check:

```bash
php artisan nodeflow:install --check
```

It reports Nodeflow configuration as optional and healthy whether the host copy is absent or customized. It checks the drift state of published Nodeflow migration copies, provider wiring and registration anchors, Tailwind source scanning, Vite alias and React dedupe, TypeScript paths, and the `@xyflow/react` dependency. It does not report whether Laravel migrations have already run. It prints a requirement/status table and returns zero only when every required integration is wired or already present.

`NOT WIRED (would be written)` means a safe writable change was found but `--check` did not write it. `NOT WIRED` means a verify-only or non-automatable requirement is missing. Both are non-zero outcomes. Authorization gates and tenancy mode are reported for operators but do not determine this command's exit code.

## Protect trigger registrations and pinned graphs

Run the command after migrations and after every application provider has registered its Nodeflow components. Put it in the release check before enabling or restarting workers:

```bash
php artisan nodeflow:check-node-types
```

The command is read-only, has no options, bypasses ambient tenancy for its system-wide liveness scan, and checks two reachable sets:

- Every active flow activation must resolve the activation's trigger node, driver, and source. The trigger node type is read from the activation's immutable pinned version and `trigger_node_id`; driver/source routing comes from the activation snapshot.
- Every version pinned by a live `pending`, `running`, `waiting`, or `blocked` run must have a structurally usable graph and every executable downstream type must resolve. For trigger-origin runs it also resolves the pinned start trigger node and derives that node's driver/source against the pinned config. Completed-only historical versions are ignored.

For manual and sub-flow live runs, Nodeflow bypasses trigger matching, so those origins do not keep the trigger node/driver/source registrations alive by themselves. Their executable downstream types are still required. If one pinned version has both a trigger-origin live run and a manual or sub-flow live run, the trigger-origin run makes the trigger components required.

Success exits `0` and prints exactly:

```text
All active trigger and live-run component registrations resolve.
```

A failure exits `1` and writes one deterministic line per issue. After the `Nodeflow health check failed:` prefix, every issue includes the exact identity `flow {flowId} version {versionId} node {nodeId}`. Malformed graph/trigger metadata tells the operator to restore the pinned version metadata. Missing registrations include their stable keys and one of these remedies:

```php
Nodeflow::registerTriggerNodes([\App\Nodeflow\Triggers\OrderTrigger::class]);
Nodeflow::registerTriggerDrivers([\App\Nodeflow\TriggerDrivers\OrderDriver::class]);
Nodeflow::registerTriggerSources([\App\Nodeflow\TriggerSources\OrderSource::class]);
```

Trigger registries have no alias API. Restore or re-register the exact trigger node, driver, or source required by an active activation or trigger-origin live run.

Executable nodes do support a direct alias. When a pinned graph names a retired executable type, register the replacement class first and map the old type directly to its current canonical type:

```php
Nodeflow::register([\App\Nodeflow\Nodes\SendOrderReceipt::class]);
Nodeflow::nodes()->alias('old.type', 'current.type');
```

The facade call above invokes `NodeRegistry::alias('old.type', 'current.type')`, which is also the remediation form printed by the health check.

Aliases are one hop. Do not chain aliases, and keep the replacement registered until no live version references the old type.

## Optional boot-time scan

Set `nodeflow.check_node_types_on_boot` to `true` to run the same trigger-aware resolver once per PHP process. It defaults to `false` to avoid the database query cost on every process startup. The package schedules the scan through Laravel's `booted` callback, after all application providers have had an opportunity to register drivers, nodes, and sources.

Each issue is logged as an error beginning `Unresolvable nodeflow type:`. An exception while querying or resolving is logged as a warning beginning `Could not verify nodeflow node types at boot:`. Neither condition fails application boot. A long-lived process checks only once, so use the Artisan command—not a boot log—as the authoritative deploy gate.

## Use a deploy sequence

1. Apply the Nodeflow migrations needed by the released code.
2. Deploy code that registers every active trigger component and live executable type, including direct executable aliases for renamed types.
3. Run `php artisan nodeflow:install --check` and `php artisan nodeflow:check-node-types` from the release.
4. Run `php artisan workflow:v2:doctor --strict` to validate the durable engine's database, queue, cache, and codec capabilities.
5. Restart or roll workers, then inspect worker logs and an authorized run view.

The durable doctor is dependency-specific. It does not know Nodeflow's graph registrations, trigger activations, or frontend wiring.

## Next step

Keep completed records bounded with [Pruning and retention](pruning-and-retention.md).
