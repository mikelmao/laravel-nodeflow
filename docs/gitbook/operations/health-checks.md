# Health checks

Use three separate checks: `nodeflow:install --check` verifies host integration, `nodeflow:check-node-types` protects live Nodeflow graphs at deploy time, and `workflow:v2:doctor` inspects the durable engine backend. None substitutes for the others.

## Verify installation wiring

Run this read-only command after installation and in a deployment check:

```bash
php artisan nodeflow:install --check
```

It checks Nodeflow configuration, the drift state of any published Nodeflow migration copies, provider wiring and registration anchors, Tailwind source scanning, Vite alias and React dedupe, TypeScript paths, and the `@xyflow/react` dependency. It does not report whether Laravel migrations have already run. It prints a requirement/status table and returns zero only when every requirement is wired or already present.

`NOT WIRED (would be written)` means a safe writable change was found but `--check` did not write it. `NOT WIRED` means a verify-only or non-automatable requirement is missing. Both are non-zero outcomes. Authorization gates and tenancy mode are reported for operators but do not determine this command's exit code.

## Protect live node types

Run this command against the released code before or while enabling workers:

```bash
php artisan nodeflow:check-node-types
```

It has no options. It scans every flow version across tenants, but reports only versions with at least one run in a live status: `pending`, `running`, `waiting`, or `blocked`. For each graph node, it checks whether its type resolves in `NodeRegistry`.

It prints `All node types referenced by live runs resolve.` and exits 0 when successful. Each missing entry is printed as `Unresolvable node type: version {id} node {nodeId} type {type}`, followed by alias recovery guidance, and the command exits 1. Completed-only versions do not fail the check.

When renaming a type, register the replacement and map every historical type directly to it:

```php
// Partial snippet: App\Providers\NodeflowServiceProvider::boot().

Nodeflow::register([\App\Nodeflow\Nodes\SendOrderReceipt::class]);
Nodeflow::nodes()->alias('shop.send_receipt', 'shop.send_order_receipt');
```

Aliases are one hop. Do not chain aliases, and do not remove the replacement class until no live version references it.

## Optional boot-time scan

Set `nodeflow.check_node_types_on_boot` to `true` to scan live versions during application boot. It defaults to `false` to avoid the query cost on every web request.

When enabled, unresolved live types produce one error log line per type beginning `Unresolvable nodeflow type:`. An exception while checking produces a warning beginning `Could not verify nodeflow node types at boot:`; the application does not fail boot in either case. The service provider has a static once-per-process guard, so a long-lived worker process checks only once after it starts.

This is a startup diagnostic, not a replacement for a deploy gate. A worker can remain alive across a code change, and a web process might not boot at the moment a bad release is introduced.

## Use a deploy sequence

1. Deploy migrations once before bringing up code that needs them.
2. Deploy the new application code with every live node type or a direct alias.
3. Run `php artisan nodeflow:install --check` and `php artisan nodeflow:check-node-types` from the release.
4. Run `php artisan workflow:v2:doctor --strict` to validate the durable engine's database, queue, cache, and codec capabilities.
5. Restart or roll workers, then inspect worker logs and an authorized run view.

The durable doctor is dependency-specific. It also reports matching-role and topology information, and `--strict` fails only on its required backend capabilities; it does not know Nodeflow's registered node types or frontend wiring.

## Next step

Keep completed records bounded with [Pruning and retention](pruning-and-retention.md).
