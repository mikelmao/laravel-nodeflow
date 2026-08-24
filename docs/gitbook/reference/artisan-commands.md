# Artisan command reference

These ten commands are registered when Laravel runs in the console. They have no alternate command aliases; `-f` is the only option shortcut.

## `nodeflow:install`

```text
nodeflow:install
    {--check : Verify everything and write nothing}
    {--publish-migrations : Also publish the package migrations into database/migrations}
    {--force-migrations : Re-publish over a published copy that has drifted}
```

**Outcome:** wires the host integration where it can prove a safe edit, then verifies every requirement. It exits `0` only when every requirement is wired or already present; it exits `1` for a requirement it cannot wire or that remains writable. `--check` is strictly verify-only and writes nothing. `--force-migrations` implies `--publish-migrations`.

Without `--check`, it can create the host Nodeflow provider, register that provider in `bootstrap/providers.php`, add the recognised Tailwind source, and optionally publish package migrations into `database/migrations`. Configuration publication is optional: package defaults are merged when `config/nodeflow.php` is absent, the installer never writes or overwrites that file, and an application-owned copy can be published explicitly with `php artisan vendor:publish --tag=nodeflow-config`. Vite alias/dedupe, TypeScript paths, and the `@xyflow/react` dependency are verify-only; the command prints a manual snippet or command when it cannot verify them. It also reports undefined Nodeflow authorization gates and the effective tenancy mode; those reports do not decide the exit code.

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
    {--driver= : Registered trigger driver key}
    {--type= : Stable graph node type}
    {--force|-f : Overwrite the trigger node if it exists}
```

**Outcome:** generates a `TriggerNode` subclass in `app/Nodeflow/Triggers`, using `stubs/trigger-node.stub` when the host supplies it. The required driver key must use `[a-z][a-z0-9._-]*`, fit 191 bytes, and already be registered; `manual` and `subflow` are run origins, not registered trigger drivers. The graph type uses the same grammar with a 255-byte limit, defaults from the class basename when omitted, may not use the package-reserved `core.` prefix, and may not collide in the shared executable/trigger graph catalog. The command also refuses unsafe PHP names, path traversal, a loaded generated class, and an occupied output path without `--force`.

Safe provider editing appends the class to `$triggerNodes`. A missing or structurally ambiguous provider/anchor uses the manual registration fallback, leaves the provider unchanged, writes the verified class, and exits `0`. A real generation/provider write failure rolls back the class and provider bytes and exits `1`; generation is an atomic generation transaction rather than a best-effort pair of writes.

```bash
php artisan nodeflow:make-trigger PartnerWebhook \
  --driver=webhook --type=shop.trigger.partner_webhook
```

## `nodeflow:make-trigger-source`

```text
nodeflow:make-trigger-source {name}
    {--driver= : Registered trigger driver key}
    {--key= : Stable source key}
    {--model= : Allowlisted Eloquent model class for the model driver}
    {--event= : Allowlisted event class for the event driver}
    {--force|-f : Overwrite the source if it exists}
```

**Outcome:** generates `app/Nodeflow/TriggerSources/{Name}.php`, selects the specialized interface and typed payload guard for each built-in driver, and appends it to `$triggerSources` when safe. Driver and source keys use `[a-z][a-z0-9._-]*` with a 191-byte limit; the driver must already be registered, the source key may not use the package-reserved `core.` prefix, and `(driver, source)` must not collide in the source registry. `--model` is required for `model`; `--event` is required for `event`; each selector must name a compatible concrete class, and those options are rejected for nonmatching drivers. Unsafe PHP names, path traversal, loaded-class collisions, and occupied paths are refused before mutation.

Safe provider editing appends to `$triggerSources`. If the provider or its unique structural anchor is unavailable, the manual registration fallback prints the exact facade call, leaves the provider unchanged, writes the verified source, and exits `0`. A true filesystem/provider failure restores every changed file and exits `1`.

```bash
php artisan nodeflow:make-trigger-source FloodAlertSource \
  --driver=event --key=flood.alert \
  --event='App\Events\FloodAlertRaised'
```

## `nodeflow:make-trigger-driver`

```text
nodeflow:make-trigger-driver {name}
    {--key= : Stable trigger driver key}
    {--force|-f : Overwrite the complete extension kit}
```

**Outcome:** generates one kit: `app/Nodeflow/TriggerDrivers/{Name}.php`, `app/Nodeflow/Triggers/{Name}Trigger.php`, and `tests/Feature/Nodeflow/TriggerDrivers/{Name}Test.php`. The driver key uses `[a-z][a-z0-9._-]*` with a 191-byte limit. Built-in keys `webhook`, `model`, and `event`, run-origin keys `manual` and `subflow`, and the `core.` prefix are reserved. The derived reference graph type `{key}.trigger` must fit the 255-byte graph-type limit. A class, path, registry, or shared graph-catalog collision aborts before mutation.

All three artifacts and provider registration are one atomic generation transaction. Provider editing registers driver then node. When safe automatic editing cannot prove both homes, the manual registration fallback prints those two facade calls in the same order, leaves the provider unchanged, and still writes the complete kit. A real write or verification failure rolls back every kit artifact and provider change. `--force` applies to the complete kit, never one artifact in isolation.

```bash
php artisan nodeflow:make-trigger-driver QueueTriggerDriver --key=queue
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

The package name must be valid Composer lowercase `vendor/name` syntax. The namespace defaults to the StudlyCase vendor and package segments; an explicit namespace must be valid PHP identifiers. The default target is `packages/vendor/name`; paths must be host-relative, inside the host, and not the host root. The host must require `atram/laravel-nodeflow` so its exact constraint can be copied. With `--js`, the command prints missing Vite alias and TypeScript `paths` snippets but never writes either host configuration; add the shown snippets yourself.

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

**Outcome:** read-only check of executable types referenced by flow versions with live runs and trigger node/driver/source registrations referenced by active flow activations. It exits `0` with `All active trigger and live-run component registrations resolve.` or exits `1` after listing each missing registration and the relevant `Nodeflow::register...` remedy. Executable aliases can repair renamed executable node types; trigger components have no alias API.

```bash
php artisan nodeflow:check-node-types
```

See [Health checks](../operations/health-checks.md).

## `nodeflow:prune`

```text
nodeflow:prune {--days= : Retention window} {--dry-run}
```

**Outcome:** deletes terminal `completed`, `failed`, and `cancelled` runs older than the cutoff, plus matching run subjects and node executions. It exits `0` for both preview and deletion. It does not delete flows, flow versions, templates, or durable-engine records; it never selects `pending`, `running`, `waiting`, or `blocked` runs.

An omitted, empty, or literal `--days=0` uses `nodeflow.retention.runs_days` (default `90`) because the command uses PHP's falsey fallback. Any truthy supplied option overrides it for that invocation; a truthy nonnumeric value casts to `0`. `--dry-run` only reports the count. Negative values make a future cutoff that can select far more data. The criterion is exactly `Run.created_at < now()->subDays($days)`: it does not use `ended_at` or the time a run became terminal. Always preview the exact value before a live run. It processes selected runs in batches of 500 and is not one whole-command database transaction.

```bash
php artisan nodeflow:prune --days=90 --dry-run
php artisan nodeflow:prune --days=90
```

See [Pruning and retention](../operations/pruning-and-retention.md).
