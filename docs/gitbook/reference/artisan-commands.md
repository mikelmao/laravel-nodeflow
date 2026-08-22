# Artisan command reference

These eight commands are registered when Laravel runs in the console. They have no alternate command aliases; `-f` is the only option shortcut.

## `nodeflow:install`

```text
nodeflow:install
    {--check : Verify everything and write nothing}
    {--publish-migrations : Also publish the package migrations into database/migrations}
    {--force-migrations : Re-publish over a published copy that has drifted}
```

**Outcome:** wires the host integration where it can prove a safe edit, then verifies every requirement. It exits `0` only when every requirement is wired or already present; it exits `1` for a requirement it cannot wire or that remains writable. `--check` is strictly verify-only and writes nothing. `--force-migrations` implies `--publish-migrations`.

Without `--check`, it can publish `config/nodeflow.php`, optionally publish package migrations into `database/migrations`, create the host Nodeflow provider, register that provider in `bootstrap/providers.php`, and add the recognised Tailwind, Vite alias/dedupe, TypeScript path, and Xyflow dependency wiring. It prints a manual snippet for a requirement it cannot safely edit. It also reports undefined Nodeflow authorization gates and the effective tenancy mode; those reports do not decide the exit code.

```bash
php artisan nodeflow:install
php artisan nodeflow:install --check
php artisan nodeflow:install --publish-migrations
```

Do not treat a normal successful install as a replacement for the `--check` verification step after hand edits. Continue with [Installation](../getting-started/installation.md) and [Frontend setup](../integration/frontend-setup.md).

## `nodeflow:make-node`

```text
nodeflow:make-node {name}
    {--type= : The stable type identifier, e.g. yaya.send_message}
    {--cardinality=subject : subject, audience, or both}
    {--outputs=default : Comma-separated output names}
    {--group=General : Palette group shown in the editor}
    {--test : Also generate a Pest test for the node}
    {--force|-f : Overwrite the node, and the generated test, if they already exist}
```

**Outcome:** generates `app/Nodeflow/Nodes/{Name}.php` by default, using a host `stubs/node*.stub` override when present. With `--test`, it writes `tests/Feature/Nodeflow/{Name}Test.php`. It then appends the class to `app/Providers/NodeflowServiceProvider.php` when its registration anchor can be proved; otherwise it prints the registration to add yourself. A manual-registration outcome still exits `0`; invalid input or a generator refusal (such as an existing class without `--force`) exits `1`.

`--cardinality` must be `subject`, `audience`, or `both`. `--type` defaults interactively through a prompt, or non-interactively to the snake-cased class basename with a warning; it must be lowercase segments separated by `.` or `_`, must not begin `core.`, and must not collide with another registered node type. `--outputs` defaults to `default`; each output must be lowercase letters/digits/underscores and names must be unique. `--group` is a label, defaulting to `General`.

```bash
php artisan nodeflow:make-node SendFloodAlert \
  --type=flood.send_alert --outputs=sent,failed --test
```

See [Writing nodes](../building-automations/writing-nodes.md).

## `nodeflow:make-trigger`

```text
nodeflow:make-trigger {name}
    {--event= : The host event class this trigger listens to}
    {--type= : The stable type identifier, e.g. shop.order_placed}
    {--force|-f : Overwrite the trigger if it already exists}
```

**Outcome:** generates `app/Nodeflow/Triggers/{Name}.php` by default, using a host `stubs/trigger.stub` override when present. It appends the trigger to the host Nodeflow provider when possible; otherwise it prints the exact registry call. A manual-registration outcome exits `0`; invalid input or generator refusal exits `1`.

`--event` is required in non-interactive use and is prompted for interactively. A class that does not yet exist only produces a warning: PHP can render `::class`, but a wrong event class creates a silent trigger. `--type` is prompted or derived from the class name when omitted, with the same lowercase identifier, reserved `core.` prefix, and collision rules as generated nodes. It is not an event alias.

```bash
php artisan nodeflow:make-trigger StartFloodAlert \
  --event='App\Events\FloodAlertRaised' --type=flood.alert_raised
```

See [Writing triggers](../building-automations/writing-triggers.md).

## `nodeflow:make-subject-attribute`

```text
nodeflow:make-subject-attribute
    {key : The attribute key a condition will reference, e.g. clicked_offer}
    {--label= : The label shown in the editor; derived from the key when omitted}
    {--type=boolean : boolean, text or number}
```

**Outcome:** writes no class. It appends a `SubjectAttribute::make(...)` entry, with a `fn ($subject) => null` resolver placeholder, to the host provider's `subjectAttributes()` anchor. If it cannot safely find that anchor, it prints the entry for manual registration and still exits `0`. An invalid key or type exits `1`.

Keys must be lowercase letters/digits joined with underscores. `--type` is exactly `boolean`, `text`, or `number`; `--label` defaults to the key with underscores replaced by spaces and an uppercase first character. Fill in the resolver before publishing a condition that uses the attribute.

```bash
php artisan nodeflow:make-subject-attribute clicked_offer \
  --label='Clicked offer' --type=boolean
```

See [Subject attributes](../building-automations/subject-attributes.md).

## `nodeflow:make-node-package`

```text
nodeflow:make-node-package
    {name : The Composer package name, e.g. acme/widgets}
    {--namespace= : PHP namespace for the package; default is derived from the name}
    {--path= : Path, relative to the host root, to scaffold into; default is packages/vendor/name}
    {--js : Also scaffold package.json, tsconfig.json, and resources/js/index.ts}
    {--force : Overwrite an occupied target directory that is not already this package}
```

**Outcome:** scaffolds `composer.json`, `README.md`, `src/{Provider}.php`, `src/Nodes/`, and `tests/ExampleTest.php`; `--js` also adds `package.json`, `tsconfig.json`, and `resources/js/index.ts`. It exits `0` after scaffolding or `1` for validation/scaffolding failure. It does not add the package to the host's Composer repositories or dependencies.

The package name must be valid Composer lowercase `vendor/name` syntax. The namespace defaults to the StudlyCase vendor and package segments; an explicit namespace must be valid PHP identifiers. The default target is `packages/vendor/name`; paths must be host-relative, inside the host, and not the host root. The host must require `atram/laravel-nodeflow` so its exact constraint can be copied.

A non-empty target is refused unless its `composer.json` names this same package or `--force` is supplied. `--force` can overwrite generated files in a foreign occupied directory; inspect it first. A matching package merges missing Composer defaults and provider registration while leaving existing non-Composer generated files in place. Unexpected filesystem failures after writing begins are not a transaction.

```bash
php artisan nodeflow:make-node-package acme/flood-nodes --js
```

See [Creating node packages](../node-packages/creating-packages.md).

## `nodeflow:extract-node`

```text
nodeflow:extract-node
    {class : Fully-qualified class name of the node to extract}
    {--package= : The Composer package name the class will move into, e.g. acme/widgets}
    {--namespace= : PHP namespace for the package; default is derived from --package}
    {--path= : Path, relative to the host root, to scaffold into; default is packages/vendor/name}
    {--force : Overwrite an occupied target directory that is not already this package}
```

**Outcome:** moves one eligible host node into a local Composer package, rewrites supported registrations, installs the path dependency, and verifies discovery from a fresh host boot. It exits `0` only after that verification. All user-facing refusals, move failures, verification failures, and rollback-cleanup warnings exit `1`.

`--package` is required. Before mutations, the command verifies the loadable host-owned class, node cardinality, statically provable literal type, source containment, reference safety, package/path constraints, Composer prerequisites, and package discovery. It can move the conventional `tests/Feature/Nodeflow/{ShortClass}Test.php` only when it references the node. It updates the package provider, host Nodeflow provider, and host `composer.json`; then it runs Composer without scripts or plugins and clears package-discovery cache before a fresh boot.

Every mutation before successful verification is journaled and is rolled back in reverse order if a later step fails. That journal cannot reverse arbitrary provider side effects during fresh-host boot, cannot guarantee recovery when its own restoration fails, and is not a rollback of a successful forced overwrite. `--force` has the same occupied-target overwrite risk as package scaffolding, plus the moved node and conventional test destinations.

```bash
php artisan nodeflow:extract-node 'App\Nodeflow\Nodes\SendFloodAlert' \
  --package=acme/flood-nodes
```

See [Extracting nodes](../node-packages/extracting-nodes.md).

## `nodeflow:check-node-types`

```text
nodeflow:check-node-types
```

**Outcome:** read-only check of every node type referenced by a flow version with live runs. It exits `0` when all types resolve and `1` after listing each unresolved type. Re-register the class or add a `NodeRegistry::alias()` for a renamed type.

```bash
php artisan nodeflow:check-node-types
```

See [Health checks](../operations/health-checks.md).

## `nodeflow:prune`

```text
nodeflow:prune {--days= : Retention window} {--dry-run}
```

**Outcome:** deletes terminal `completed`, `failed`, and `cancelled` runs older than the cutoff, plus matching run subjects and node executions. It exits `0` for both preview and deletion. It does not delete flows, flow versions, templates, or durable-engine records; it never selects `pending`, `running`, `waiting`, or `blocked` runs.

An omitted or empty `--days` uses `nodeflow.retention.runs_days` (default `90`). A supplied option overrides it for that invocation. `--dry-run` only reports the count. The command casts `--days` to `int` without validating it: nonnumeric input becomes `0`, and a negative value makes a future cutoff that can select far more data. Always preview the exact value before a live run. It processes selected runs in batches of 500 and is not one whole-command database transaction.

```bash
php artisan nodeflow:prune --days=90 --dry-run
php artisan nodeflow:prune --days=90
```

See [Pruning and retention](../operations/pruning-and-retention.md).
