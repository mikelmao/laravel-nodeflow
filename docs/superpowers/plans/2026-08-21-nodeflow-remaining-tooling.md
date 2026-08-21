# Nodeflow Remaining Tooling (Plan 5) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `nodeflow:install`, `nodeflow:make-trigger` and `nodeflow:make-subject-attribute`; close defects F-1 and F-2, gaps G-2 and G-4, and drift R-2; and fix the demo application's cross-tenant write.

**Architecture:** `nodeflow:install` is a thin command driving nine independent step objects, each with a read-only `check()` and a writing `apply()`. Every `check()` runs before any `apply()`, so a failure cannot half-wire a host. Four steps write host files through anchor-asserted insertion; three verify host JS/TS config it cannot safely edit and print the exact snippet instead; two more publish config and audit any published migration copy. The two generators reuse `MakeNodeCommand`'s validation rules and a generalised `NodeRegistrationWriter` that takes an anchor and a presence needle.

**Tech Stack:** PHP 8.3, Laravel 12 (`illuminate/console`, `illuminate/filesystem`), Orchestra Testbench 10, Pest 4. No Pint and no PHPStan in this repository — do not add them. The demo application is a separate Laravel 12 + Inertia + React 19 app at `~/Sites/test-workflow`.

**Branch:** `plan-5-tooling`, in a git worktree created with `superpowers:using-git-worktrees` before Task 1. **A fresh worktree has no dependencies** — see Global Constraints. The demo application at `~/Sites/test-workflow` is *not* worktreed; it is edited on its own `main` in place (Task 16), because its `composer.json` path repository points at `~/Projects/laravel-nodeflow` and a second checkout would fight the symlink.

**Spec:** `docs/superpowers/specs/2026-08-21-remaining-tooling-design.md` — read it before Task 1. Decisions E19–E28 are binding. Where it disagrees with `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` §7.1 or §7.3, the Plan 5 spec wins and §1 of it explains why.

## Global Constraints

- **Baseline to start from.** Package `~/Projects/laravel-nodeflow` at `a047b44`: **358 Pest tests (5,832 assertions)**, **160 Vitest tests**, silent `npx tsc --noEmit`. Demo `~/Sites/test-workflow` at `bb0f7d8`: **49 Pest tests (191 assertions)**, silent `npx tsc --noEmit`, passing `npm run build`. Verify these before Task 1; if reality differs, report it rather than adapting.
- **`handle(): int` on every command.** Returning `false` from a Laravel `handle()` exits **0**. `MakeNodeCommand` maps `parent::handle() === false` to `self::FAILURE` for this reason; every new command does the same. `install`'s non-zero exit is a CI contract.
- **`Nodeflow\Console\NodeRegistrationWriter::ANCHOR` is `'protected array $nodes = ['`** and must appear **exactly once** in a generated provider. The writer refuses on zero and on more than one, leaving the file byte-identical.
- **Anchor-assert, write, then re-read and verify (E11).** No edit lands without its anchor proven present and unique first.
- **Exit non-zero iff any step ends `CannotWire` (E21).** The gate report and the tenancy report never affect the exit code.
- **Every test ships with its counterfactual executed.** Remove the guard, run the test, capture the failure output into the task's completion note, restore the guard. A counterfactual you cannot reproduce is worth less than no claim.
- **Exact test arithmetic.** Each task below states its expected **test** count. Record **measured** assertion counts after running; never predict them. Do not pad and do not trim to hit a number.
- **The package sets `noUncheckedIndexedAccess: true`** in `tsconfig.json`. Indexing a `Record<string, T>` yields `T | undefined`. (Only relevant if a task touches TypeScript; none in this plan does.)
- **PHP refuses a closure in a property default.** `class T { protected array $x = [fn () => 1]; }` fails with *Constant expression contains invalid operations*. This is why subject attributes live in a method.
- **Never `migrate:fresh` against the demo's dev SQLite.** It destroys the developer's own login account and passkeys. If seeding throws on a missing column, back the file up and rebuild only the `nodeflow_*` tables.
- **A fresh worktree has no dependencies.** `vendor/`, `node_modules/`, `.env` and `public/build/` are gitignored. The demo needs all four before any gate runs; 15 of its tests render Blade through `@vite` and fail with a Vite-manifest error without a build.
- **The demo's `composer.json` hardcodes a path repository at `~/Projects/laravel-nodeflow`.** Any `composer install` re-points `vendor/atram/laravel-nodeflow` at **main**, not your worktree. Re-point the symlink afterwards and assert `readlink -f` before trusting any demo gate.
- **`npm install` in a differently-named worktree rewrites `package-lock.json`'s `name` field.** Do not commit it.
- **The demo cannot start a run under `QUEUE_CONNECTION=sync`.** The durable engine throws `UnsupportedBackendCapabilitiesException`. Demo run tests set `config(['queue.default' => 'database'])` and drain with `Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1])` — see `driveRun()` in `tests/Feature/NodeflowRunViewTest.php`.

## File Structure

**Package — created**

| Path | Responsibility |
|---|---|
| `src/Console/InstallCommand.php` | Drives the steps, prints the table and the two reports, owns the exit code |
| `src/Console/Install/InstallOutcome.php` | `AlreadyPresent \| Wired \| CannotWire` |
| `src/Console/Install/InstallStep.php` | Interface: `describe()`, `check()`, `apply()`, `snippet()` |
| `src/Console/Install/PublishConfigStep.php` | Publishes `config/nodeflow.php` |
| `src/Console/Install/MigrationStep.php` | Audits any published migration copy (E19, spec §3.2.1) |
| `src/Console/Install/ProviderStep.php` | Creates the provider, or additively adds its three registration homes |
| `src/Console/Install/ProviderRegistrationStep.php` | Adds the provider to `bootstrap/providers.php` — the sixth wiring step |
| `src/Console/Install/TailwindSourceStep.php` | Writes the `@source` line with a **computed** relative path |
| `src/Console/Install/ViteAliasStep.php` | Verifies the `@nodeflow/editor` alias |
| `src/Console/Install/ViteDedupeStep.php` | Verifies `resolve.dedupe` (G-4) |
| `src/Console/Install/TsconfigPathsStep.php` | Verifies both `paths` mappings structurally |
| `src/Console/Install/XyflowDependencyStep.php` | Verifies `@xyflow/react` in the host manifest |
| `src/Console/SourceText.php` | Comment stripping for JS/TS/JSONC and for CSS (E22) |
| `src/Console/MakeTriggerCommand.php` | `nodeflow:make-trigger` |
| `src/Console/MakeSubjectAttributeCommand.php` | `nodeflow:make-subject-attribute` |
| `stubs/provider.stub` | The generated provider, with all three anchors |
| `stubs/trigger.stub` | The generated trigger |

**Package — modified**

| Path | Change |
|---|---|
| `src/Console/MakeNodeCommand.php` | F-1: docblock corrected; `str_replace` → `strtr` in `buildClass()` and `writeTest()` |
| `src/Console/NodeRegistrationWriter.php` | Generalised: `appendTo()` with anchor, presence needle and indent; `register()` and `ANCHOR` unchanged |
| `src/NodeflowServiceProvider.php` | Registers the three new commands |
| `src/Models/Concerns/BelongsToTenant.php` | G-2: the `updating` guard's comment records the query-builder bypass |
| `docs/02-integration.md` | Steps 1 and 3 rewritten; "Verifying the install" rewritten; `tenant_id` immutability added |
| `docs/04-writing-triggers.md` | `make-trigger` documented |
| `docs/08-editor-client.md` | What `install --check` reports per requirement |
| `docs/superpowers/open-issues.md` | F-1, F-2, G-2, G-4 closed; R-2 closed or corrected; G-3 reassigned |

**Demo — modified**

| Path | Change |
|---|---|
| `routes/web.php` | Route reshaped to carry `{run}`; `auth` middleware added |
| `app/Http/Controllers/NodeflowDemoController.php` | Subject reached through a scoped `Run`; `withoutTenancy()` deleted; `User` write scoped; `switchTenant` validated |
| `app/Nodeflow/SessionTenantResolver.php` | Docblock's "without logging in" claim removed |
| `resources/js/pages/nodeflow/demo.tsx` | Two URL strings carry the run id |
| `tests/Feature/NodeflowDemoSecurityTest.php` | Created — the four security tests |

---

## Task 1: F-1 — kill sequential substitution in both renderers

**Files:**
- Modify: `src/Console/MakeNodeCommand.php` — `paletteGroup()` docblock (~line 380), `buildClass()` (~line 205), `writeTest()` (~line 148)
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: nothing later tasks depend on. `MakeTriggerCommand` (Task 12) copies the `strtr` convention.

**Background the implementer needs.** `buildClass()` currently calls `str_replace` with array arguments, which is **sequential**: it substitutes `{{ group }}` at array index 2, then goes on to substitute `{{ outputs }}` — including inside the text it just wrote. So `--group='{{ outputs }}'` renders `->group(''default'')`, a parse error, and the command still reports success and exits 0. `strtr()` with an array never re-scans replaced text, so it fixes the whole class rather than the one ordering that exposed it. `writeTest()` has the same shape and the same exposure.

- [ ] **Step 1: Write the failing test**

Add to the end of `tests/Feature/MakeNodeCommandTest.php`:

```php
it('renders a group containing a placeholder literally instead of re-substituting it', function () {
    // F-1. The counterfactual: restore str_replace() in buildClass() and this
    // fails, because the sequential substitution turns --group='{{ outputs }}'
    // into ->group(''default'') — a parse error the command reports as success.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendPlaceholder',
        '--type' => 'yaya.send_placeholder',
        '--group' => '{{ outputs }}',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendPlaceholder.php';

    expect(file_get_contents($path))->toContain("->group('{{ outputs }}')");

    // php -l is the only thing that catches an unparseable render, and it is
    // what reported success on the broken version.
    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode(PHP_EOL, $output));
});

```

**One test, not two — deliberately.** `writeTest()` gets the same `strtr` treatment in Step 5, but it is **not** given a test, because it cannot currently be made to fail: every value that reaches it is pattern-validated before it arrives (`TYPE_PATTERN` and `OUTPUT_PATTERN` both forbid braces, and the class name is validated by `GeneratorCommand`). It is converted for symmetry and so that a future placeholder cannot silently reopen F-1 there. A test that cannot fail is worse than no test, so none is claimed — the corrected docblock carries the reasoning instead.

- [ ] **Step 2: Run the test and verify it fails**

```bash
./vendor/bin/pest tests/Feature/MakeNodeCommandTest.php --filter="placeholder"
```

Expected: FAILS — `->group('{{ outputs }}')` is not in the file (it rendered `->group(''default'')`) and `php -l` exits non-zero. Capture that output; it is the counterfactual for this task.

- [ ] **Step 3: Correct the docblock first**

In `src/Console/MakeNodeCommand.php`, replace `paletteGroup()`'s docblock entirely:

```php
    /**
     * Escaped rather than rejected, unlike the type and the output names: the group
     * is a human-facing palette label, and "Client's Tools" is a fair thing to call
     * one. It is rendered inside a single-quoted PHP string, so a backslash and a
     * single quote are escaped here.
     *
     * Escaping those two is NOT sufficient on its own, and a previous version of
     * this comment claimed it was. A value containing another stub placeholder —
     * `--group='{{ outputs }}'` — needed no quote to break the render, because the
     * renderer substituted this value and then kept substituting *inside it*.
     * buildClass() and writeTest() use strtr() rather than str_replace() for that
     * reason: strtr never re-scans what it has already written. Do not change
     * either back.
     */
```

- [ ] **Step 4: Replace `str_replace` with `strtr` in `buildClass()`**

```php
    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $outputs = $this->outputNames();

        // strtr, not str_replace: str_replace with array arguments is sequential
        // and re-substitutes inside its own output, so a --group value containing
        // a later placeholder rendered an unparseable file and exited 0 (F-1).
        return strtr($stub, [
            '{{ type }}' => $this->nodeType(),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
            '{{ group }}' => $this->paletteGroup(),
            '{{ outputs }}' => implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
            '{{ firstOutput }}' => $outputs[0],
        ]);
    }
```

- [ ] **Step 5: Replace `str_replace` with `strtr` in `writeTest()`**

Replace the `$this->files->put($path, str_replace([...], [...], ...))` call at the end of `writeTest()` with:

```php
        // strtr for the same reason as buildClass(): see F-1 in paletteGroup().
        $this->files->put($path, strtr(
            $this->files->get($this->resolveStubPath('/stubs/node.test.stub')),
            [
                '{{ namespacedClass }}' => $nodeClass,
                '{{ cardinalityImports }}' => $imports,
                '{{ cardinalityExpectations }}' => $expectations,
                '{{ class }}' => $class,
                '{{ type }}' => $this->nodeType(),
                '{{ outputs }}' => implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
            ],
        ));
```

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **359 tests pass** (358 baseline + 1). Record the measured assertion count.

- [ ] **Step 7: Execute the counterfactual and restore**

Temporarily change `strtr($stub, [...])` in `buildClass()` back to the sequential `str_replace` form, run `./vendor/bin/pest tests/Feature/MakeNodeCommandTest.php --filter="renders a group containing"`, capture the failure output into the task note, then restore `strtr`. Re-run to confirm green.

- [ ] **Step 8: Commit**

```bash
git add src/Console/MakeNodeCommand.php tests/Feature/MakeNodeCommandTest.php
git commit -m "fix: render node stubs with strtr so a placeholder in --group survives

F-1. str_replace with array arguments is sequential and re-substitutes
inside its own output, so --group='{{ outputs }}' rendered
->group(''default'') and the command exited 0. strtr never re-scans what
it has written, which closes the class rather than the one ordering that
exposed it. Applied to writeTest() as well, which had the same shape.

paletteGroup()'s docblock claimed a backslash and a single quote were the
only two characters that could end the string early. Corrected first: the
reproducing value contains neither."
```

---

## Task 2: F-2 — put `node.both.stub` under an executing test

**Files:**
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: `writeRegisteredNode()` is already in this file; you do not need it. The temp-app-root harness in `beforeEach` sets `$this->root` and `$this->app->setBasePath($this->root)`.
- Produces: nothing.

**Background the implementer needs.** The three node stubs are three independent copies of the same call chain. `tests/Feature/MakeNodeCommandTest.php` has `require`-and-execute tests for `node.stub` (`SendSms`) and `node.audience.stub` (`SendBlast`), but nothing executes `node.both.stub`. Renaming `->help(` to `->helpText(` in that one file leaves the whole suite green while the stub fatals in every host that generates from it. **The class name must be new** — `require`ing two generated classes that share an FQCN in one process fatals with "class already declared", which is why `SendSms` and `SendBlast` differ. Use `SendDigest`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/MakeNodeCommandTest.php`, directly after the `SendBlast` audience test:

```php
it('produces a both-cardinality class the registry accepts and both paths execute', function () {
    // F-2. Nothing but `php -l` watched node.both.stub, and `php -l` resolves no
    // symbols: renaming ->help( to ->helpText( in that file alone left every test
    // green while the stub fataled in every host that generated from it.
    //
    // A fourth distinct class name is mandatory. SendSms and SendBlast are already
    // required into this process by the tests above, and `require`ing two
    // generated classes that share an FQCN fatals with "class already declared".
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendDigest',
        '--type' => 'yaya.send_digest',
        '--cardinality' => 'both',
        '--outputs' => 'sent, failed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendDigest.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendDigest');

    $node = app(NodeRegistry::class)->resolve('yaya.send_digest');

    expect($node)->toBeInstanceOf(HandlesSubject::class)
        ->toBeInstanceOf(HandlesAudience::class);

    // definition() executes the whole NodeDefinition::make()->group()
    // ->description()->outputs()->fields([Field::text()->label()->help()
    // ->required()]) chain as a side effect of being called at all. This is the
    // assertion that fails on an API rename confined to this stub.
    expect($node->definition()->outputNames())->toBe(['sent', 'failed']);
    expect($node->validate([]))->toHaveKey('example');

    // Both bodies, because a both-cardinality node whose two paths disagree is
    // invisible until scale changes which one the runtime picks. Asserting the
    // routing rather than merely that nothing threw: a body returning
    // NodeResult::empty() would satisfy the weaker check.
    $subject = $node->forSubject(new SubjectContext(
        new Run(['is_test' => true]), 'n1', [], '42', null,
    ));

    expect($subject->outputs())->toBe(['sent' => ['42']]);

    $audience = $node->forAudience(new AudienceContext(
        new Run(['is_test' => true]), 'n1', [], 'user', ['7', '8'],
    ));

    expect($audience->outputs())->toBe(['sent' => ['7', '8']]);
});
```

- [ ] **Step 2: Run it and verify it passes**

```bash
./vendor/bin/pest tests/Feature/MakeNodeCommandTest.php --filter="both-cardinality"
```

Expected: PASS. This test adds coverage for already-correct code, so a green first run is correct — which is exactly why Step 3 is not optional.

- [ ] **Step 3: Execute the counterfactual — this is the whole point of the task**

```bash
sed -i '' 's/->help(/->helpText(/' stubs/node.both.stub
./vendor/bin/pest tests/Feature/MakeNodeCommandTest.php --filter="both-cardinality"
```

Expected: FAIL with a fatal — `Call to undefined method Nodeflow\Schema\Field::helpText()`. Capture the output.

Then prove the gap this closes was real, by confirming the rest of the suite does **not** notice:

```bash
./vendor/bin/pest --filter="produces a subject class"
./vendor/bin/pest --filter="produces an audience class"
```

Expected: both PASS while the stub is broken. Capture that too — it is the evidence F-2 describes.

Restore:

```bash
git checkout stubs/node.both.stub
./vendor/bin/pest tests/Feature/MakeNodeCommandTest.php --filter="both-cardinality"
```

- [ ] **Step 4: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **360 tests pass** (359 after Task 1, + 1). Record the measured assertion count.

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/MakeNodeCommandTest.php
git commit -m "test: execute node.both.stub, the third stub nothing watched

F-2. Renaming ->help( to ->helpText( in node.both.stub alone left the
whole suite green while that stub fataled in every host. Measured again
here: with the rename applied, the SendSms and SendBlast execute tests
both still pass and only this new one fails.

Fourth class name (SendDigest) because requiring two generated classes
that share an FQCN in one process fatals."
```

---

## Task 3: Generalise `NodeRegistrationWriter` (E23)

**Files:**
- Modify: `src/Console/NodeRegistrationWriter.php`
- Test: `tests/Unit/NodeRegistrationWriterTest.php` (append; **do not edit existing cases**)

**Interfaces:**
- Consumes: `NodeRegistrationOutcome` (existing enum: `Appended`, `AlreadyPresent`, `ProviderMissing`, `AnchorMissing`, `AnchorAmbiguous`).
- Produces, relied on by Tasks 5, 6, 12 and 13:
  - `NodeRegistrationWriter::ANCHOR` — `'protected array $nodes = ['` (unchanged)
  - `NodeRegistrationWriter::TRIGGER_ANCHOR` — `'protected array $triggers = ['`
  - `NodeRegistrationWriter::ATTRIBUTE_ANCHOR` — `'protected function subjectAttributes(): array'`
  - `NodeRegistrationWriter::register(string $providerPath, string $nodeClass): NodeRegistrationOutcome` (unchanged signature and behaviour)
  - `NodeRegistrationWriter::appendTo(string $providerPath, string $anchor, string $presenceNeedle, string $entry, string $indent = '        '): NodeRegistrationOutcome`

**Background the implementer needs.** Three things now get appended into the provider, not one: node classes, trigger classes, and `SubjectAttribute::make()` calls. They differ in three ways, so all three become parameters:

1. **The anchor.** `$nodes` and `$triggers` anchors end in `[`, so the insertion point is the end of the anchor itself. The attribute anchor is a *method signature*, so the `return [` following it has to be located.
2. **The presence needle.** `Foo::class` for the two class lists; `SubjectAttribute::make('key'` for attributes, keyed on the attribute key alone because `SubjectAttributeRegistry::register()` keys by `$attribute->key` and a second entry under the same key silently replaces the first.
3. **The indent.** Entries in a property array sit at 8 spaces; entries inside `subjectAttributes()`'s `return [` sit at 12.

`register()` keeps its exact existing behaviour and becomes a call through `appendTo()`. **`tests/Unit/NodeRegistrationWriterTest.php`'s existing cases must pass untouched** — that untouched file is the evidence the refactor changed nothing.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/NodeRegistrationWriterTest.php`:

```php
/**
 * A provider with all three registration homes, as `nodeflow:install` generates
 * it. Returns the *path*, matching this file's existing providerWithAnchor().
 */
function providerWithThreeHomes(string $body = null): string
{
    return writeProviderFixture($body ?? threeHomesSource());
}

function threeHomesSource(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;
    use Nodeflow\Schema\SubjectAttributeRegistry;
    use Nodeflow\Triggers\TriggerRegistry;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
        ];

        protected array $triggers = [
        ];

        public function boot(): void
        {
            Nodeflow::register($this->nodes);

            app(TriggerRegistry::class)->register(...$this->triggers);

            app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
        }

        /** @return \Nodeflow\Schema\SubjectAttribute[] */
        protected function subjectAttributes(): array
        {
            return [
            ];
        }
    }
    PHP;
}

it('appends a trigger class through the trigger anchor', function () {
    // Counterfactual: point TRIGGER_ANCHOR at the $nodes anchor and this fails,
    // because the trigger lands in the node array — where NodeRegistry::register()
    // would reject it for implementing neither cardinality interface.
    $path = providerWithThreeHomes();

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    // In the $triggers array, and provably not in the $nodes one.
    expect($contents)->toContain("protected array \$triggers = [\n        \\App\Nodeflow\Triggers\OrderPlaced::class,");
    expect($contents)->toContain("protected array \$nodes = [\n    ];");
});

it('appends a subject attribute inside the method body, at the method indent', function () {
    // Counterfactual: return the end of the anchor as the insertion point (the
    // rule the two array anchors use) and this fails — the entry lands in the
    // method signature line rather than inside its return array, which does not
    // parse.
    $path = providerWithThreeHomes();

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);

    $contents = file_get_contents($path);

    expect($contents)->toContain("        return [\n            \\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null),");

    // The whole point of appending into a method: the result must still parse.
    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);
    expect($status)->toBe(0, implode(PHP_EOL, $output));
});

it('recognises an attribute already present by its key alone', function () {
    // Counterfactual: make the presence needle the whole entry and this fails —
    // a re-run with a different label appends a second entry under the same key,
    // and SubjectAttributeRegistry::register() keys by $attribute->key, so the
    // second silently replaces the first.
    $path = providerWithThreeHomes(str_replace(
        "        return [\n",
        "        return [\n            \\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Old label', 'boolean', fn (\$subject) => null),\n",
        threeHomesSource(),
    ));

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'New label', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses an attribute method whose body is not a bare return array', function () {
    // The bounded-window rule. Counterfactual: search the whole remainder of the
    // file for `return [` and this fails — the entry lands in some unrelated
    // later method's return array, silently, in host code.
    $path = providerWithThreeHomes(str_replace(
        "    protected function subjectAttributes(): array\n    {\n        return [\n        ];\n    }",
        "    protected function subjectAttributes(): array\n    {\n        \$extra = \$this->somethingElse();\n\n        \$more = \$this->andAnother();\n\n        \$yetMore = \$this->stillGoing();\n\n        return [\n        ];\n    }",
        threeHomesSource(),
    ));

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses a duplicated trigger anchor and writes nothing', function () {
    // Counterfactual: drop the >1 check and this fails — two $triggers arrays
    // means the writer cannot know which one the boot() call spreads.
    $path = providerWithThreeHomes(threeHomesSource().PHP_EOL.'// protected array $triggers = [');

    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))->appendTo(
        $path,
        NodeRegistrationWriter::TRIGGER_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    );

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});
```

These reuse the file's existing conventions rather than inventing new ones: `writeProviderFixture()` returns a *path* under a per-process temp directory that the file's `afterEach` already cleans up, and the writer is constructed inline as `new NodeRegistrationWriter(new Filesystem)` — there is no `$this->writer` and no `$this->root` in this file. Read the top 70 lines before you start.

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Unit/NodeRegistrationWriterTest.php
```

Expected: the five new cases FAIL with `Call to undefined method Nodeflow\Console\NodeRegistrationWriter::appendTo()`; every pre-existing case still passes. Capture.

- [ ] **Step 3: Generalise the writer**

Replace the body of `src/Console/NodeRegistrationWriter.php`'s class with:

```php
class NodeRegistrationWriter
{
    public const ANCHOR = 'protected array $nodes = [';

    public const TRIGGER_ANCHOR = 'protected array $triggers = [';

    /**
     * A method signature, not an array opening, because a SubjectAttribute carries
     * a Closure and PHP refuses a closure in a property default: `protected array
     * $x = [fn () => 1];` is "Constant expression contains invalid operations".
     */
    public const ATTRIBUTE_ANCHOR = 'protected function subjectAttributes(): array';

    /**
     * How far past a method-signature anchor the writer will look for the
     * `return [` it appends into. The generated method puts it 20-odd characters
     * away; anything further means the body is not the bare return array this
     * writer knows how to edit, and refusing beats appending into whatever other
     * array it found next.
     */
    private const METHOD_BODY_WINDOW = 120;

    public function __construct(private Filesystem $files) {}

    public function register(string $providerPath, string $nodeClass): NodeRegistrationOutcome
    {
        $entry = '\\'.ltrim($nodeClass, '\\').'::class';

        return $this->appendTo($providerPath, self::ANCHOR, ltrim($entry, '\\'), $entry);
    }

    /**
     * @param  string  $presenceNeedle  What "already registered" looks like. Not the
     *                                  whole entry: a node is matched on `Foo::class`
     *                                  so an entry written without the leading
     *                                  backslash still counts, and an attribute is
     *                                  matched on its key alone so a re-run with a
     *                                  different label does not append a second entry
     *                                  under one key — SubjectAttributeRegistry keys
     *                                  by attribute key and the second would silently
     *                                  replace the first.
     * @param  string  $indent  Entries in a property array sit at 8 spaces; entries
     *                          inside subjectAttributes()'s return array sit at 12.
     */
    public function appendTo(
        string $providerPath,
        string $anchor,
        string $presenceNeedle,
        string $entry,
        string $indent = '        ',
    ): NodeRegistrationOutcome {
        if (! $this->files->exists($providerPath)) {
            return NodeRegistrationOutcome::ProviderMissing;
        }

        $contents = $this->files->get($providerPath);

        if (str_contains($contents, $presenceNeedle)) {
            return NodeRegistrationOutcome::AlreadyPresent;
        }

        $occurrences = substr_count($contents, $anchor);

        if ($occurrences === 0) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        if ($occurrences > 1) {
            return NodeRegistrationOutcome::AnchorAmbiguous;
        }

        $position = $this->insertionPoint($contents, $anchor);

        if ($position === null) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        $this->files->put($providerPath, substr_replace(
            $contents,
            PHP_EOL.$indent.$entry.',',
            $position,
            0,
        ));

        return NodeRegistrationOutcome::Appended;
    }

    /**
     * An anchor ending in `[` *is* the array opening, so the insertion point is
     * its end. A method-signature anchor is not, so the `return [` that follows
     * has to be found — and bounded, because an unbounded search would append
     * into whatever unrelated array appeared next in the file.
     */
    private function insertionPoint(string $contents, string $anchor): ?int
    {
        $anchorEnd = strpos($contents, $anchor) + strlen($anchor);

        if (str_ends_with($anchor, '[')) {
            return $anchorEnd;
        }

        $window = substr($contents, $anchorEnd, self::METHOD_BODY_WINDOW);
        $offset = strpos($window, 'return [');

        return $offset === false ? null : $anchorEnd + $offset + strlen('return [');
    }
}
```

Keep the existing file-level docblock and the long explanatory comment about why the presence check omits the leading backslash — move it onto `register()` or into `appendTo()`'s `$presenceNeedle` param docs, whichever reads better. Do not delete it.

- [ ] **Step 4: Run the writer tests**

```bash
./vendor/bin/pest tests/Unit/NodeRegistrationWriterTest.php
```

Expected: all pass, and **`git diff --stat tests/Unit/NodeRegistrationWriterTest.php` shows only additions** — no pre-existing case edited. That is the evidence the refactor changed no behaviour.

- [ ] **Step 5: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **365 tests pass** (360 after Task 2, + 5). Record the measured assertion count.

- [ ] **Step 6: Execute the counterfactuals and restore**

For each of the five new tests, apply the production change its comment names, run that one test, capture the failure, restore. The bounded-window one (`refuses an attribute method whose body is not a bare return array`) matters most: raise `METHOD_BODY_WINDOW` to `100000` and confirm it fails by appending into the wrong array.

- [ ] **Step 7: Commit**

```bash
git add src/Console/NodeRegistrationWriter.php tests/Unit/NodeRegistrationWriterTest.php
git commit -m "refactor: generalise the provider writer to three anchors

E23. Nodes, triggers and subject attributes all get appended into the
generated provider, and they differ in anchor, presence needle and
indent, so all three become parameters of a new appendTo(). register()
keeps its exact behaviour and calls through it; ANCHOR is unchanged.

Attributes need a method rather than a property because PHP refuses a
closure in a property default, so the attribute anchor is a signature and
the return [ after it is located within a bounded window. Unbounded, the
search would append into whatever array came next in host code.

Every pre-existing writer test passes untouched, which is the evidence
this changed nothing."
```

---

## Task 4: The step contract, and the provider `install` creates

**Files:**
- Create: `src/Console/Install/InstallOutcome.php`, `src/Console/Install/InstallStep.php`, `src/Console/Install/ProviderStep.php`, `stubs/provider.stub`
- Test: `tests/Feature/Install/ProviderStepTest.php`

**Interfaces:**
- Consumes: `NodeRegistrationWriter::ANCHOR`, `::TRIGGER_ANCHOR`, `::ATTRIBUTE_ANCHOR` (Task 3).
- Produces, relied on by Tasks 5–11:
  - `enum InstallOutcome { case AlreadyPresent; case Writable; case Wired; case CannotWire; }`
  - `interface InstallStep { public function describe(): string; public function check(): InstallOutcome; public function apply(): InstallOutcome; public function snippet(): ?string; }`
  - `final class ProviderStep implements InstallStep` with `public const PATH = 'app/Providers/NodeflowServiceProvider.php';` and constructor `__construct(Filesystem $files, string $basePath, string $rootNamespace, NodeRegistrationWriter $writer)`

**The four outcomes, and how they map to the exit code.** `check()` returns `AlreadyPresent` (nothing to do), `Writable` (missing, and `apply()` can fix it), or `CannotWire` (needs the host; a snippet is printed). `apply()` returns `Wired` or `CannotWire`. `Writable` never survives into a normal run's report — the command replaces it with the `apply()` result. Under `--check` it does survive, and means "would be written". So:

- normal run: **non-zero iff any final outcome is `CannotWire`**
- `--check`: **non-zero iff any outcome is `CannotWire` or `Writable`**

That is E21 plus spec §10's "`--check` with anything unwired → non-zero, nothing written".

**Scope of this task:** the create path only. A provider that exists is reported `AlreadyPresent` here; Task 5 refines that to inspect its anchors.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/ProviderStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\NodeRegistrationWriter;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-provider-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/app/Providers', 0777, true);

    $this->step = new ProviderStep(
        new Filesystem,
        $this->root,
        'App\\',
        new NodeRegistrationWriter(new Filesystem),
    );

    $this->path = $this->root.'/'.ProviderStep::PATH;
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

it('reports the provider as writable when it does not exist', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('creates a provider whose three anchors each appear exactly once', function () {
    // Counterfactual: put `protected array $nodes = [];` on one line twice in the
    // stub, or omit it, and this fails — NodeRegistrationWriter refuses on zero
    // matches and on more than one, so nodeflow:make-node could never register
    // into the file install just created.
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::ATTRIBUTE_ANCHOR))->toBe(1);
});

it('creates a provider in the host root namespace that parses', function () {
    $this->step->apply();

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('namespace App\Providers;')
        ->toContain('class NodeflowServiceProvider extends ServiceProvider');

    exec('php -l '.escapeshellarg($this->path).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode(PHP_EOL, $output));
});

it('creates a provider the three generators can each append into', function () {
    // The composition test. Counterfactual: change the stub's empty arrays to
    // `= [];` on one line and the node/trigger appends still work but render
    // valid-and-ugly; change the attribute method's body shape and the attribute
    // append returns AnchorMissing. Either way this test names which one broke.
    $this->step->apply();

    $writer = new NodeRegistrationWriter(new Filesystem);

    expect($writer->register($this->path, 'App\Nodeflow\Nodes\SendSms'))
        ->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::TRIGGER_ANCHOR,
        'App\Nodeflow\Triggers\OrderPlaced::class',
        '\App\Nodeflow\Triggers\OrderPlaced::class',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    expect($writer->appendTo(
        $this->path,
        NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        "SubjectAttribute::make('clicked'",
        "\Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn (\$subject) => null)",
        '            ',
    ))->toBe(\Nodeflow\Console\NodeRegistrationOutcome::Appended);

    // All three appended, and the result still parses.
    exec('php -l '.escapeshellarg($this->path).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode(PHP_EOL, $output));
});

it('reports already present when the provider exists', function () {
    $this->step->apply();

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('does not rewrite a provider that already exists', function () {
    // Idempotency, asserted byte-for-byte rather than by outcome alone.
    $this->step->apply();

    $before = file_get_contents($this->path);

    $this->step->apply();

    expect(file_get_contents($this->path))->toBe($before);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderStepTest.php
```

Expected: FAIL — `Class "Nodeflow\Console\Install\InstallOutcome" not found`. Capture.

- [ ] **Step 3: Create the outcome enum**

`src/Console/Install/InstallOutcome.php`:

```php
<?php

namespace Nodeflow\Console\Install;

/**
 * What one install step found, or did.
 *
 * check() returns AlreadyPresent, Writable or CannotWire. apply() returns Wired
 * or CannotWire. Writable is check()-only and means "missing, and apply() can fix
 * it" — in a normal run the command replaces it with the apply() result, and under
 * --check it survives to mean "would be written".
 *
 * The exit rule, which is a CI contract: non-zero iff any final outcome is
 * CannotWire — or, under --check, CannotWire or Writable. A report is never an
 * outcome: an undefined authorization gate is the correct state immediately after
 * install and must not make the first run red.
 */
enum InstallOutcome
{
    case AlreadyPresent;
    case Writable;
    case Wired;
    case CannotWire;
}
```

- [ ] **Step 4: Create the step interface**

`src/Console/Install/InstallStep.php`:

```php
<?php

namespace Nodeflow\Console\Install;

/**
 * One host-wiring requirement.
 *
 * Every check() in the command runs before any apply(), which is what stops a
 * step failing halfway through from leaving a half-wired host. So check() must
 * be strictly read-only.
 */
interface InstallStep
{
    /** The name shown in the report table. */
    public function describe(): string;

    /** Read-only. Never writes. */
    public function check(): InstallOutcome;

    /** Only called when check() returned Writable. */
    public function apply(): InstallOutcome;

    /** The exact text the host must add, when this step cannot write it. */
    public function snippet(): ?string;
}
```

- [ ] **Step 5: Create the provider stub**

`stubs/provider.stub`:

```php
<?php

namespace {{ namespace }};

use Illuminate\Support\ServiceProvider;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    /**
     * Your nodes — the things that do work.
     *
     * `php artisan nodeflow:make-node` appends to this array. Leave the opening
     * line exactly as it is: the generator matches it literally and writes
     * nothing at all if it appears zero times or more than once.
     *
     * @var class-string[]
     */
    protected array $nodes = [
    ];

    /**
     * Your triggers — which of your events start journeys.
     *
     * `php artisan nodeflow:make-trigger` appends to this array. Registration is
     * what attaches the event listener, at the moment it happens, so a trigger
     * that is never registered never fires.
     *
     * @var class-string[]
     */
    protected array $triggers = [
    ];

    public function register(): void
    {
        // The two contracts Nodeflow needs from you — docs/02-integration.md,
        // Step 2. Uncomment and point them at your own classes.
        //
        // Bind unconditionally, here in register(): never in middleware and never
        // per request. `nodeflow.tenancy = auto` decides what a null tenant means
        // by asking which resolver is bound *right now*, so a binding that is
        // sometimes absent makes a queue worker or a console command read across
        // every tenant instead of throwing.
        //
        // $this->app->bind(\Nodeflow\Contracts\TenantResolver::class, \App\Nodeflow\YourTenantResolver::class);
        // $this->app->bind(\Nodeflow\Contracts\SubjectResolver::class, \App\Nodeflow\YourSubjectResolver::class);
    }

    public function boot(): void
    {
        Nodeflow::register($this->nodes);

        app(TriggerRegistry::class)->register(...$this->triggers);

        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());

        // Nodeflow makes no authorization decisions, and its policies deny every
        // ability whose gate you have not defined — so a fresh install refuses
        // everything until you say otherwise. Define all four.
        //
        // \Illuminate\Support\Facades\Gate::define('nodeflow.viewAny', fn ($user, $flow = null) => true);
        // \Illuminate\Support\Facades\Gate::define('nodeflow.update', fn ($user, $flow) => $user->organization_id === $flow->tenant_id);
        // \Illuminate\Support\Facades\Gate::define('nodeflow.publish', fn ($user, $flow) => true);
        // \Illuminate\Support\Facades\Gate::define('nodeflow.runManually', fn ($user, $flow) => true);
    }

    /**
     * What a non-technical author may build a `core.condition` on.
     *
     * `php artisan nodeflow:make-subject-attribute` appends into the array below.
     * A method rather than a property because these carry closures, and PHP
     * refuses a closure in a property default.
     *
     * @return \Nodeflow\Schema\SubjectAttribute[]
     */
    protected function subjectAttributes(): array
    {
        return [
        ];
    }
}
```

- [ ] **Step 6: Create `ProviderStep`**

`src/Console/Install/ProviderStep.php`:

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationWriter;

/**
 * The registration home every generator writes into.
 *
 * `nodeflow:make-node` has always looked for this exact file with this exact
 * anchor, and until this command existed nothing created it — so every host took
 * the generator's fallback path and pasted a `Nodeflow::register([...])` line
 * instead. That is the story this step ends.
 */
final class ProviderStep implements InstallStep
{
    public const PATH = 'app/Providers/NodeflowServiceProvider.php';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $rootNamespace,
        private NodeRegistrationWriter $writer,
    ) {}

    public function describe(): string
    {
        return 'Provider ('.self::PATH.')';
    }

    public function check(): InstallOutcome
    {
        return $this->files->exists($this->path())
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::Writable;
    }

    public function apply(): InstallOutcome
    {
        if ($this->files->exists($this->path())) {
            return InstallOutcome::AlreadyPresent;
        }

        $this->files->ensureDirectoryExists(dirname($this->path()));

        $this->files->put($this->path(), strtr($this->stub(), [
            '{{ namespace }}' => rtrim($this->rootNamespace, '\\').'\\Providers',
        ]));

        // E11: re-read and prove the anchors are there. A stub edited past
        // recognition would otherwise ship a provider no generator can write to,
        // and nothing would say so until a host ran make-node and got a paste
        // instruction it could not explain.
        $written = $this->files->get($this->path());

        foreach ([
            NodeRegistrationWriter::ANCHOR,
            NodeRegistrationWriter::TRIGGER_ANCHOR,
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
        ] as $anchor) {
            if (substr_count($written, $anchor) !== 1) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::Wired;
    }

    public function snippet(): ?string
    {
        return null;
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }

    /** Host stub overrides, the same convention MakeNodeCommand::resolveStubPath() follows. */
    private function stub(): string
    {
        $custom = $this->basePath.'/stubs/provider.stub';

        return $this->files->get(
            $this->files->exists($custom) ? $custom : __DIR__.'/../../../stubs/provider.stub'
        );
    }
}
```

- [ ] **Step 7: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderStepTest.php
```

Expected: all 6 pass.

- [ ] **Step 8: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **371 tests pass** (365 after Task 3, + 6). Record the measured assertion count.

- [ ] **Step 9: Execute the counterfactuals and restore**

Two matter most. Change `stubs/provider.stub`'s `protected array $nodes = [` + newline + `];` to a single-line `protected array $nodes = [];`, run `--filter="three anchors"` and `--filter="can each append into"`, capture. Then delete the `subjectAttributes()` method from the stub entirely and confirm the anchor-count test fails with `CannotWire`. Restore.

- [ ] **Step 10: Commit**

```bash
git add src/Console/Install stubs/provider.stub tests/Feature/Install/ProviderStepTest.php
git commit -m "feat: create the provider nodeflow:install generates

Adds the step contract (InstallStep, InstallOutcome) and the first step.
The generated provider carries all three registration homes: \$nodes,
\$triggers, and a subjectAttributes() method — a method because attributes
carry closures and PHP refuses a closure in a property default.

apply() re-reads the file and asserts each anchor appears exactly once
(E11). Without that, a stub edited past recognition would ship a provider
no generator can write to, and the first sign would be a host running
make-node and getting a paste instruction it could not explain.

check() reports an existing provider AlreadyPresent; inspecting its
anchors is the next task."
```

---

## Task 5: Additively wire an existing provider (E25)

**Files:**
- Modify: `src/Console/Install/ProviderStep.php`
- Test: `tests/Feature/Install/ProviderStepTest.php` (append)

**Interfaces:**
- Consumes: everything from Task 4, plus `NodeRegistrationWriter::appendTo()` (Task 3).
- Produces: `ProviderStep::snippet()` now returns the three-home snippet when it cannot write. No new public names.

**Why this exists.** `docs/02-integration.md` has always taught `Nodeflow::register([...])` in *any* provider's `boot()`, which is not what the writer looks for. The demo — the only installed host — did exactly that, so `nodeflow:make-node` returns `AnchorMissing` on it today. This step is where those two stories become one. It inserts **only what is missing** and leaves any existing `Nodeflow::register([...])` call alone: both mechanisms registering is harmless, because `register()` is idempotent by type and every registry is a container singleton.

Anchors: `class NodeflowServiceProvider` for a property insertion, `public function boot(): void` for a registration call, each asserted present and unique. **No `boot()` at all is `CannotWire`** — this step does not synthesise a method.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Install/ProviderStepTest.php`:

```php
/** A provider shaped the way docs/02-integration.md taught, which is what the demo has. */
function handWrittenProvider(): string
{
    return <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        public function boot(): void
        {
            Nodeflow::register([
                \App\Nodeflow\Nodes\SendMessage::class,
            ]);
        }
    }
    PHP;
}

it('reports a provider without the anchors as writable', function () {
    // Counterfactual: keep Task 4's `exists() ? AlreadyPresent : Writable` and
    // this fails — the host who followed the docs is told everything is fine
    // while make-node still cannot register into their file.
    file_put_contents($this->path, handWrittenProvider());

    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('adds all three homes to a hand-written provider without touching its register call', function () {
    file_put_contents($this->path, handWrittenProvider());

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::ATTRIBUTE_ANCHOR))->toBe(1);

    // The host's own registration survives verbatim. Counterfactual: rewrite the
    // existing list into $nodes instead of leaving it, and this fails.
    expect($contents)->toContain('\App\Nodeflow\Nodes\SendMessage::class,');

    expect($contents)->toContain('Nodeflow::register($this->nodes);')
        ->toContain('app(TriggerRegistry::class)->register(...$this->triggers);')
        ->toContain('app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());');

    exec('php -l '.escapeshellarg($this->path).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode(PHP_EOL, $output));
});

it('adds only the missing home when one is already there', function () {
    // Counterfactual: insert unconditionally rather than per-home, and this fails
    // with two $nodes arrays — which is exactly the AnchorAmbiguous state that
    // makes the writer refuse every future make-node.
    file_put_contents($this->path, str_replace(
        "    public function boot(): void",
        "    protected array \$nodes = [\n    ];\n\n    public function boot(): void",
        handWrittenProvider(),
    ));

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect(substr_count($contents, NodeRegistrationWriter::ANCHOR))->toBe(1);
    expect(substr_count($contents, NodeRegistrationWriter::TRIGGER_ANCHOR))->toBe(1);
});

it('is idempotent on a provider it already wired', function () {
    file_put_contents($this->path, handWrittenProvider());

    $this->step->apply();
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);
});

it('refuses a provider with no boot method and offers the snippet', function () {
    // Counterfactual: synthesise a boot() method and this fails — writing a new
    // method into someone else's class is the one edit this step will not make,
    // because there is no anchor that proves where it belongs.
    file_put_contents($this->path, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
    }
    PHP);

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('protected array $nodes = [');
    expect(file_get_contents($this->path))->toBe($before);
});

it('refuses a provider with two boot methods and writes nothing', function () {
    // A duplicated anchor means the step cannot know which boot() the host runs.
    file_put_contents($this->path, str_replace(
        '    public function boot(): void',
        "    public function boot(): void\n    {\n    }\n\n    public function boot(): void",
        handWrittenProvider(),
    ));

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->path))->toBe($before);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderStepTest.php
```

Expected: the six new cases FAIL; Task 4's six still pass. Capture.

- [ ] **Step 3: Replace `check()` and `apply()` in `ProviderStep`**

```php
    private const CLASS_ANCHOR = 'class NodeflowServiceProvider';

    private const BOOT_ANCHOR = 'public function boot(): void';

    /**
     * The three homes, each with the anchor it is inserted after, the text
     * inserted, and how its presence is recognised.
     *
     * @return array<int, array{anchor: string, needle: string, insert: string}>
     */
    private function homes(): array
    {
        return [
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::TRIGGER_ANCHOR,
                'insert' => PHP_EOL.'    /** @var class-string[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::TRIGGER_ANCHOR
                    .PHP_EOL.'    ];'.PHP_EOL,
            ],
            [
                'anchor' => self::CLASS_ANCHOR,
                'needle' => NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
                'insert' => PHP_EOL.'    /** @return \Nodeflow\Schema\SubjectAttribute[] */'
                    .PHP_EOL.'    '.NodeRegistrationWriter::ATTRIBUTE_ANCHOR
                    .PHP_EOL.'    {'
                    .PHP_EOL.'        return ['
                    .PHP_EOL.'        ];'
                    .PHP_EOL.'    }'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => 'Nodeflow::register($this->nodes);',
                'insert' => PHP_EOL.'        \Nodeflow\Nodeflow::register($this->nodes);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => '->register(...$this->triggers);',
                'insert' => PHP_EOL.'        app(\Nodeflow\Triggers\TriggerRegistry::class)->register(...$this->triggers);'.PHP_EOL,
            ],
            [
                'anchor' => self::BOOT_ANCHOR,
                'needle' => '->register(...$this->subjectAttributes());',
                'insert' => PHP_EOL.'        app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());'.PHP_EOL,
            ],
        ];
    }

    public function check(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return InstallOutcome::Writable;
        }

        $contents = $this->files->get($this->path());

        $missing = array_filter(
            $this->homes(),
            fn (array $home) => ! str_contains($contents, $home['needle']),
        );

        if ($missing === []) {
            return InstallOutcome::AlreadyPresent;
        }

        // Every anchor a missing home needs must be present exactly once, or this
        // step cannot prove where the insertion belongs. Refusing beats guessing:
        // an edit that applies cleanly and matches nothing has cost this project
        // time twice already.
        foreach ($missing as $home) {
            if (substr_count($contents, $home['anchor']) !== 1) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::Writable;
    }

    public function apply(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return $this->create();
        }

        if ($this->check() !== InstallOutcome::Writable) {
            return $this->check();
        }

        // Re-read between insertions rather than batching: each insertion shifts
        // every later offset, and each one asserts its own anchor against the file
        // as it now stands.
        foreach ($this->homes() as $home) {
            $contents = $this->files->get($this->path());

            if (str_contains($contents, $home['needle'])) {
                continue;
            }

            if (substr_count($contents, $home['anchor']) !== 1) {
                return InstallOutcome::CannotWire;
            }

            $position = strpos($contents, $home['anchor']) + strlen($home['anchor']);

            // Past the anchor line's own opening brace, so the insertion lands
            // inside the class or the method rather than on its signature line.
            $position = strpos($contents, '{', $position) + 1;

            $this->files->put($this->path(), substr_replace($contents, $home['insert'], $position, 0));
        }

        return $this->check() === InstallOutcome::AlreadyPresent
            ? InstallOutcome::Wired
            : InstallOutcome::CannotWire;
    }
```

Move Task 4's original body of `apply()` — the stub render and the anchor re-read — into a new `private function create(): InstallOutcome`.

- [ ] **Step 4: Give `snippet()` real content**

```php
    public function snippet(): ?string
    {
        if ($this->check() !== InstallOutcome::CannotWire) {
            return null;
        }

        return <<<'PHP'
        // Add these three registration homes to your NodeflowServiceProvider, and
        // the three calls in boot(). The generators match the property and method
        // lines literally, so keep them exactly as written.

            /** @var class-string[] */
            protected array $nodes = [
            ];

            /** @var class-string[] */
            protected array $triggers = [
            ];

            public function boot(): void
            {
                \Nodeflow\Nodeflow::register($this->nodes);
                app(\Nodeflow\Triggers\TriggerRegistry::class)->register(...$this->triggers);
                app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
            }

            /** @return \Nodeflow\Schema\SubjectAttribute[] */
            protected function subjectAttributes(): array
            {
                return [
                ];
            }
        PHP;
    }
```

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderStepTest.php
```

Expected: all 12 pass.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **377 tests pass** (371 after Task 4, + 6). Record the measured assertion count.

- [ ] **Step 7: Execute the counterfactuals and restore**

The two that matter: make `apply()` insert unconditionally instead of skipping a present home, and confirm `adds only the missing home` fails with two `$nodes` arrays. Then make `check()` synthesise a `boot()` when absent, and confirm `refuses a provider with no boot method` fails. Capture both, restore.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Install/ProviderStep.php tests/Feature/Install/ProviderStepTest.php
git commit -m "feat: additively wire a provider that predates nodeflow:install

E25, and 7.1's constraint 2 discharged. docs/02-integration.md has always
taught Nodeflow::register([...]) in any provider's boot(), which is not
what the writer looks for, so the one installed host gets AnchorMissing
from make-node today.

Inserts only the homes that are absent, and leaves an existing
Nodeflow::register([...]) call verbatim: both mechanisms registering is
harmless because register() is idempotent by type and the registries are
container singletons. Re-reads between insertions, because each one
shifts every later offset.

No boot() method is CannotWire with a snippet. Writing a new method into
someone else's class is the one edit this step will not make, because no
anchor proves where it belongs."
```

---

## Task 6: `bootstrap/providers.php` — the sixth wiring step

**Files:**
- Create: `src/Console/Install/ProviderRegistrationStep.php`
- Test: `tests/Feature/Install/ProviderRegistrationStepTest.php`

**Interfaces:**
- Consumes: `InstallStep`, `InstallOutcome` (Task 4); `NodeRegistrationWriter::appendTo()` (Task 3).
- Produces: `final class ProviderRegistrationStep implements InstallStep`, constructor `__construct(Filesystem $files, string $basePath, string $rootNamespace, NodeRegistrationWriter $writer)`.

**Why this is not in the spec's list of five.** Laravel 12 discovers application providers from `bootstrap/providers.php` alone. A generated `app/Providers/NodeflowServiceProvider.php` that nobody lists there **does nothing at all** — no nodes register, no triggers fire, no attributes exist, and the editor's palette is empty with no error anywhere. It is not a client requirement, which is why §5.6 never listed it; it is a server one, and it fails as quietly as the worst of the five.

`bootstrap/providers.php` is a plain PHP array literal, so `NodeRegistrationWriter::appendTo()` handles it directly with `return [` as the anchor.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/ProviderRegistrationStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ProviderRegistrationStep;
use Nodeflow\Console\NodeRegistrationWriter;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-bootstrap-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/bootstrap', 0777, true);

    $this->path = $this->root.'/bootstrap/providers.php';

    file_put_contents($this->path, <<<'PHP'
    <?php

    return [
        App\Providers\AppServiceProvider::class,
    ];
    PHP);

    $this->step = new ProviderRegistrationStep(
        new Filesystem,
        $this->root,
        'App\\',
        new NodeRegistrationWriter(new Filesystem),
    );
});

afterEach(function () {
    foreach (glob($this->root.'/bootstrap/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/bootstrap');
    @rmdir($this->root);
});

it('reports writable when the provider is not listed', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
});

it('lists the provider and leaves the file parseable', function () {
    // Counterfactual: skip this step entirely and everything else still passes
    // while the host's nodes silently never register. This test is the only thing
    // that says the sixth wiring requirement exists.
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $contents = file_get_contents($this->path);

    expect($contents)->toContain('App\Providers\NodeflowServiceProvider::class,')
        ->toContain('App\Providers\AppServiceProvider::class,');

    exec('php -l '.escapeshellarg($this->path).' 2>&1', $output, $status);
    expect($status)->toBe(0, implode(PHP_EOL, $output));

    // The array must still be an array of class strings the framework can use.
    expect(require $this->path)->toContain('App\Providers\NodeflowServiceProvider');
});

it('is idempotent and never lists the provider twice', function () {
    $this->step->apply();
    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->path))->toBe($before);

    expect(substr_count($before, 'NodeflowServiceProvider::class'))->toBe(1);
});

it('cannot wire a missing bootstrap file and offers the snippet', function () {
    unlink($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('NodeflowServiceProvider::class');
});

it('cannot wire a bootstrap file with two return arrays', function () {
    file_put_contents($this->path, "<?php\n\nreturn [\n];\n\n// return [\n");

    $before = file_get_contents($this->path);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->path))->toBe($before);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderRegistrationStepTest.php
```

Expected: FAIL — class not found. Capture.

- [ ] **Step 3: Create the step**

`src/Console/Install/ProviderRegistrationStep.php`:

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;

/**
 * The sixth wiring requirement, and the one the editor spec's list of five never
 * had — because it is not a client requirement.
 *
 * Laravel 12 discovers application providers from bootstrap/providers.php alone.
 * A NodeflowServiceProvider that nobody lists there does nothing at all: no nodes
 * register, no triggers fire, no attributes exist, and the palette is empty with
 * no error raised anywhere. It fails as quietly as the worst of the five.
 */
final class ProviderRegistrationStep implements InstallStep
{
    public const PATH = 'bootstrap/providers.php';

    private const ANCHOR = 'return [';

    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private string $rootNamespace,
        private NodeRegistrationWriter $writer,
    ) {}

    public function describe(): string
    {
        return 'Provider registration ('.self::PATH.')';
    }

    public function check(): InstallOutcome
    {
        if (! $this->files->exists($this->path())) {
            return InstallOutcome::CannotWire;
        }

        $contents = $this->files->get($this->path());

        if (str_contains($contents, $this->needle())) {
            return InstallOutcome::AlreadyPresent;
        }

        return substr_count($contents, self::ANCHOR) === 1
            ? InstallOutcome::Writable
            : InstallOutcome::CannotWire;
    }

    public function apply(): InstallOutcome
    {
        // Indent 4, not the writer's default 8: bootstrap/providers.php is a
        // top-level array literal, not a class property.
        $outcome = $this->writer->appendTo(
            $this->path(),
            self::ANCHOR,
            $this->needle(),
            $this->providerClass().'::class',
            '    ',
        );

        return match ($outcome) {
            NodeRegistrationOutcome::Appended => InstallOutcome::Wired,
            NodeRegistrationOutcome::AlreadyPresent => InstallOutcome::AlreadyPresent,
            default => InstallOutcome::CannotWire,
        };
    }

    public function snippet(): ?string
    {
        if ($this->check() !== InstallOutcome::CannotWire) {
            return null;
        }

        return 'Add '.$this->providerClass().'::class to the array in '.self::PATH.'.'
            .' Laravel discovers application providers from that file alone, so'
            .' without this the provider never boots and no node registers.';
    }

    private function needle(): string
    {
        return $this->providerClass().'::class';
    }

    private function providerClass(): string
    {
        return rtrim($this->rootNamespace, '\\').'\\Providers\\NodeflowServiceProvider';
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }
}
```

- [ ] **Step 4: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/ProviderRegistrationStepTest.php
```

Expected: all 5 pass.

- [ ] **Step 5: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **382 tests pass** (377 after Task 5, + 5). Record the measured assertion count.

- [ ] **Step 6: Execute the counterfactuals and restore**

Make `apply()` pass the writer's default `'        '` indent and confirm the file still parses (it will) — then note in the task record that indentation is cosmetic here and the *real* counterfactual is dropping the `substr_count(...) === 1` guard, which makes `cannot wire a bootstrap file with two return arrays` fail. Capture that one.

- [ ] **Step 7: Commit**

```bash
git add src/Console/Install/ProviderRegistrationStep.php tests/Feature/Install/ProviderRegistrationStepTest.php
git commit -m "feat: list the generated provider in bootstrap/providers.php

The sixth wiring requirement. Editor spec 5.6 lists five client
requirements; this is a server one and was never on the list, but it
fails as quietly as the worst of them: Laravel 12 discovers application
providers from bootstrap/providers.php alone, so a NodeflowServiceProvider
nobody lists there does nothing — no nodes register, no triggers fire, and
the palette is empty with no error anywhere.

Reuses the generalised writer with 'return [' as the anchor, at indent 4
because this is a top-level array literal rather than a class property."
```

---

## Task 7: Config publish, and the migration audit (E19)

**Files:**
- Create: `src/Console/Install/PublishConfigStep.php`, `src/Console/Install/MigrationStep.php`
- Test: `tests/Feature/Install/PublishConfigStepTest.php`, `tests/Feature/Install/MigrationStepTest.php`

**Interfaces:**
- Consumes: `InstallStep`, `InstallOutcome` (Task 4).
- Produces:
  - `final class PublishConfigStep implements InstallStep`, `__construct(Filesystem $files, string $basePath)`
  - `final class MigrationStep implements InstallStep`, `__construct(Filesystem $files, string $basePath, bool $publish = false, bool $force = false)`

**Why the migration step audits rather than publishes.** `BaseCommand::getMigrationPaths()` returns `array_merge($this->migrator->paths(), [$this->getMigrationPath()])` — package paths first, the host's `database/migrations` **last** — and `Migrator::getMigrationFiles()` reduces with `->keyBy(getMigrationName(...))`, which overwrites on collision. So a published copy **silently shadows the package's own file for every `migrate` run, permanently**. Plan 4's in-place edit diverging from the demo's published copy is what that mechanism does every time. E19's answer: don't publish by default, and make an existing copy's drift a non-zero exit.

The four states are spec §3.2.1. `--force-migrations` implies `--publish-migrations`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/PublishConfigStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\PublishConfigStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-config-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/config', 0777, true);

    $this->step = new PublishConfigStep(new Filesystem, $this->root);
    $this->path = $this->root.'/config/nodeflow.php';
});

afterEach(function () {
    foreach (glob($this->root.'/config/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/config');
    @rmdir($this->root);
});

it('publishes the config when it is absent', function () {
    expect($this->step->check())->toBe(InstallOutcome::Writable);
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    // The published file must be usable as config, not merely present.
    expect(require $this->path)->toHaveKey('tenancy');
});

it('never overwrites a config the host has edited', function () {
    // Counterfactual: publish unconditionally in apply() and this fails, having
    // destroyed the host's own tenancy setting.
    file_put_contents($this->path, "<?php return ['tenancy' => 'resolver'];");

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(require $this->path)->toBe(['tenancy' => 'resolver']);
});
```

Create `tests/Feature/Install/MigrationStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\MigrationStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-migrations-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/database/migrations', 0777, true);

    $this->packageMigration = realpath(__DIR__.'/../../../database/migrations/2026_08_18_000001_create_nodeflow_tables.php');
    $this->hostCopy = $this->root.'/database/migrations/2026_08_18_000001_create_nodeflow_tables.php';
});

afterEach(function () {
    foreach (glob($this->root.'/database/migrations/*.php') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root.'/database/migrations');
    @rmdir($this->root.'/database');
    @rmdir($this->root);
});

it('reports already present when the host published nothing', function () {
    // E19's intended state. Counterfactual: report Writable here and every fresh
    // install publishes a copy that then shadows the package's own file forever.
    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect(glob($this->root.'/database/migrations/*.php'))->toBe([]);
});

it('publishes on request and reports what publishing means', function () {
    $step = new MigrationStep(new Filesystem, $this->root, publish: true);

    expect($step->check())->toBe(InstallOutcome::Writable);
    expect($step->apply())->toBe(InstallOutcome::Wired);

    expect($this->hostCopy)->toBeFile();
    expect(sha1_file($this->hostCopy))->toBe(sha1_file($this->packageMigration));
});

it('reports already present when a published copy matches', function () {
    copy($this->packageMigration, $this->hostCopy);

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('cannot wire a published copy that has drifted, and names both paths', function () {
    // The Plan 4 failure, reproduced. The package's copy gained a fourth index
    // column while the demo's published copy silently kept three, and no test
    // anywhere could see it: the index assertion lives in the package's suite
    // while the demo's tests run against the demo's copy.
    //
    // Counterfactual: compare file existence instead of content hash and this
    // fails, reporting a drifted host as correctly installed.
    copy($this->packageMigration, $this->hostCopy);
    file_put_contents($this->hostCopy, file_get_contents($this->hostCopy)."\n// host edit\n");

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::CannotWire);
    expect($step->snippet())->toContain('2026_08_18_000001_create_nodeflow_tables.php')
        ->toContain('--force-migrations');
});

it('re-publishes over a drifted copy under --force-migrations', function () {
    copy($this->packageMigration, $this->hostCopy);
    file_put_contents($this->hostCopy, file_get_contents($this->hostCopy)."\n// host edit\n");

    $step = new MigrationStep(new Filesystem, $this->root, publish: true, force: true);

    expect($step->check())->toBe(InstallOutcome::Writable);
    expect($step->apply())->toBe(InstallOutcome::Wired);
    expect(sha1_file($this->hostCopy))->toBe(sha1_file($this->packageMigration));
});

it('ignores host migrations that are not ours', function () {
    // Counterfactual: compare by directory contents rather than by matching
    // basename against the package's own files, and this fails — the host's
    // unrelated migration reads as drift.
    file_put_contents(
        $this->root.'/database/migrations/2026_01_01_000000_create_users_table.php',
        '<?php // the host\'s own',
    );

    $step = new MigrationStep(new Filesystem, $this->root);

    expect($step->check())->toBe(InstallOutcome::AlreadyPresent);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php tests/Feature/Install/MigrationStepTest.php
```

Expected: FAIL — classes not found. Capture.

- [ ] **Step 3: Create `PublishConfigStep`**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Publishes config/nodeflow.php, and never overwrites one that exists.
 *
 * Deliberately not `vendor:publish --tag=nodeflow-config`: this step has to
 * report AlreadyPresent distinctly from Wired, and vendor:publish exits 0 either
 * way. The file it copies is the same one that tag publishes.
 */
final class PublishConfigStep implements InstallStep
{
    public const PATH = 'config/nodeflow.php';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Config ('.self::PATH.')';
    }

    public function check(): InstallOutcome
    {
        return $this->files->exists($this->path())
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::Writable;
    }

    public function apply(): InstallOutcome
    {
        if ($this->files->exists($this->path())) {
            return InstallOutcome::AlreadyPresent;
        }

        $this->files->ensureDirectoryExists(dirname($this->path()));
        $this->files->copy(__DIR__.'/../../../config/nodeflow.php', $this->path());

        return $this->files->exists($this->path())
            ? InstallOutcome::Wired
            : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        return null;
    }

    private function path(): string
    {
        return $this->basePath.'/'.self::PATH;
    }
}
```

- [ ] **Step 4: Create `MigrationStep`**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Audits any published copy of the package's migrations. Publishes only on request.
 *
 * WHY THIS IS AN AUDIT AND NOT A PUBLISH (E19). Laravel's
 * BaseCommand::getMigrationPaths() returns array_merge(registered paths, [the
 * host's database/migrations]) — the host's path last — and
 * Migrator::getMigrationFiles() reduces that list with keyBy(migration name),
 * which overwrites on collision. So a published copy of one of our migrations
 * silently shadows the package's own file for every `migrate` run, permanently,
 * with no warning. An in-place edit to the package's copy then diverges from the
 * host's, and no test on either side can see it: the package's assertions run
 * against the package's file, the host's tests against the host's.
 *
 * That happened once already, between Plan 4 and the demo application. This step
 * exists so the next one is a non-zero exit instead of a silent divergence.
 */
final class MigrationStep implements InstallStep
{
    public function __construct(
        private Filesystem $files,
        private string $basePath,
        private bool $publish = false,
        private bool $force = false,
    ) {}

    public function describe(): string
    {
        return 'Migrations (database/migrations)';
    }

    public function check(): InstallOutcome
    {
        $drifted = $this->drifted();

        if ($drifted !== []) {
            return $this->force ? InstallOutcome::Writable : InstallOutcome::CannotWire;
        }

        if ($this->publish && $this->unpublished() !== []) {
            return InstallOutcome::Writable;
        }

        // No published copy and no --publish-migrations is the state E19 wants a
        // host to be in, so it must read as fine rather than as work outstanding.
        return InstallOutcome::AlreadyPresent;
    }

    public function apply(): InstallOutcome
    {
        if (! $this->publish) {
            return $this->check();
        }

        $this->files->ensureDirectoryExists($this->hostDirectory());

        foreach (array_merge($this->unpublished(), $this->force ? $this->drifted() : []) as $source) {
            $this->files->copy($source, $this->hostDirectory().'/'.basename($source));
        }

        return $this->drifted() === [] ? InstallOutcome::Wired : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        $drifted = $this->drifted();

        if ($drifted === [] || $this->force) {
            return null;
        }

        return 'These published migrations differ from the package\'s own copies: '
            .implode(', ', array_map('basename', $drifted)).'. '
            .'A published copy shadows the package\'s file for every `migrate` run, so the '
            .'difference is what your database will be built from. Re-publish with '
            .'`--force-migrations`, or delete your copies and let the package\'s own '
            .'migrations load — that is the default and the recommended state.';
    }

    /** Package migrations with no host copy of the same name. */
    private function unpublished(): array
    {
        return array_values(array_filter(
            $this->packageMigrations(),
            fn (string $source) => ! $this->files->exists($this->hostDirectory().'/'.basename($source)),
        ));
    }

    /**
     * Package migrations whose host copy differs.
     *
     * Matched by basename against the package's own files, never by scanning the
     * host's directory: the host has migrations of its own and none of them are
     * this step's business.
     */
    private function drifted(): array
    {
        return array_values(array_filter($this->packageMigrations(), function (string $source) {
            $copy = $this->hostDirectory().'/'.basename($source);

            return $this->files->exists($copy) && sha1_file($copy) !== sha1_file($source);
        }));
    }

    private function packageMigrations(): array
    {
        return $this->files->glob(__DIR__.'/../../../database/migrations/*.php') ?: [];
    }

    private function hostDirectory(): string
    {
        return $this->basePath.'/database/migrations';
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php tests/Feature/Install/MigrationStepTest.php
```

Expected: all 8 pass.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **390 tests pass** (382 after Task 6, + 8). Record the measured assertion count.

- [ ] **Step 7: Execute the counterfactuals and restore**

Three: (a) make `drifted()` compare `file_exists` rather than `sha1_file` and watch `cannot wire a published copy that has drifted` fail; (b) make `check()` return `Writable` when nothing is published and watch `reports already present when the host published nothing` fail; (c) make `drifted()` scan `hostDirectory()` instead of matching basenames against `packageMigrations()` and watch `ignores host migrations that are not ours` fail. Capture all three, restore.

- [ ] **Step 8: Commit**

```bash
git add src/Console/Install tests/Feature/Install
git commit -m "feat: publish config, and audit any published migration copy

E19. A published copy of one of our migrations silently shadows the
package's own file for every migrate run: getMigrationPaths() puts the
host's directory last and getMigrationFiles() keys by migration name,
which overwrites. So an in-place edit to the package's copy diverges from
the host's and no test on either side can see it — the package's
assertions run against the package's file, the host's against the host's.

That happened once already between Plan 4 and the demo. Publishing is now
opt-in, an existing copy is hashed against ours, and drift is a non-zero
exit with --force-migrations as the fix. 'Nothing published' reports
AlreadyPresent, because it is the state we want hosts in."
```

---

## Task 8: Comment stripping, and the two Vite checks (G-4)

**Files:**
- Create: `src/Console/SourceText.php`, `src/Console/Install/ViteAliasStep.php`, `src/Console/Install/ViteDedupeStep.php`
- Test: `tests/Unit/SourceTextTest.php`, `tests/Feature/Install/ViteStepsTest.php`

**Interfaces:**
- Consumes: `InstallStep`, `InstallOutcome` (Task 4).
- Produces:
  - `final class SourceText` with `public static function withoutJsComments(string $source): string` and `public static function withoutCssComments(string $source): string`
  - `final class ViteAliasStep implements InstallStep`, `__construct(Filesystem $files, string $basePath)`
  - `final class ViteDedupeStep implements InstallStep`, same constructor
  - `ViteAliasStep::CONFIG_CANDIDATES` — `['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs']` (Task 9 does not reuse this; Task 10 does not either)

**Why comment-stripped (E22).** `tests/Support/RequestContextScanner.php:134` already established this in the package: it runs `token_get_all()` and drops `T_COMMENT`/`T_DOC_COMMENT` before matching, precisely so a commented-out line cannot read as present. JS, TS and JSONC share PHP's comment syntax, so the same idea applies — but there is no PHP tokeniser for TypeScript, so `SourceText` scans characters and copies string and template literals whole.

**State the limit rather than imply a guarantee.** A text check cannot prove the alias sits in the config actually exported, rather than in a dead branch or a second `defineConfig`. F-1's lesson is that a wrong comment outlives the bug it describes, so the docblocks say this.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/SourceTextTest.php`:

```php
<?php

use Nodeflow\Console\SourceText;

it('strips line and block comments', function () {
    $source = <<<'TS'
    // resolve: { alias: { '@nodeflow/editor': 'x' } }
    const a = 1
    /* resolve: { dedupe: ['react'] } */
    const b = 2
    TS;

    $stripped = SourceText::withoutJsComments($source);

    expect($stripped)->toContain('const a = 1')
        ->toContain('const b = 2')
        ->not->toContain('@nodeflow/editor')
        ->not->toContain('dedupe');
});

it('preserves a double slash inside a string', function () {
    // Counterfactual: strip with a regex on // and this fails, truncating every
    // config line that mentions a URL and reporting a wired host as unwired.
    expect(SourceText::withoutJsComments("const u = 'https://example.test/a'"))
        ->toContain("'https://example.test/a'");
});

it('preserves a comment opener inside a template literal', function () {
    expect(SourceText::withoutJsComments('const t = `a /* b */ c`'))
        ->toContain('`a /* b */ c`');
});

it('preserves an escaped quote without ending the string early', function () {
    // Counterfactual: drop the backslash handling and the scanner treats the rest
    // of the file as string content, so every real check silently passes.
    $source = "const s = 'it\\'s here' // gone\nconst t = 2";

    $stripped = SourceText::withoutJsComments($source);

    expect($stripped)->toContain("'it\\'s here'")
        ->toContain('const t = 2')
        ->not->toContain('gone');
});

it('strips css block comments and leaves quoted urls alone', function () {
    $css = "/* @source 'x'; */\n@source '../../vendor/a/b/resources/js';\n";

    $stripped = SourceText::withoutCssComments($css);

    expect($stripped)->toContain("@source '../../vendor/a/b/resources/js';")
        ->not->toContain("@source 'x'");
});
```

Create `tests/Feature/Install/ViteStepsTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\ViteAliasStep;
use Nodeflow\Console\Install\ViteDedupeStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-vite-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = function (string $contents) {
        file_put_contents($this->root.'/vite.config.ts', $contents);
    };

    $this->alias = new ViteAliasStep(new Filesystem, $this->root);
    $this->dedupe = new ViteDedupeStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

/** The accepted host's config, reduced to the two settings under test. */
function wiredViteConfig(): string
{
    return <<<'TS'
    import path from 'node:path'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
            },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    })
    TS;
}

it('accepts the accepted host\'s configuration', function () {
    ($this->write)(wiredViteConfig());

    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->dedupe->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('rejects a commented-out alias', function () {
    // The test that distinguishes E22 from naive text matching. Counterfactual:
    // drop SourceText::withoutJsComments() from the step and this fails, because
    // the raw text contains the alias — so a host who commented it out while
    // debugging is told they are wired.
    ($this->write)("// '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),\nexport default defineConfig({})");

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
    expect($this->alias->snippet())->toContain('@nodeflow/editor');
});

it('rejects a commented-out dedupe', function () {
    ($this->write)("/* dedupe: ['react', 'react-dom', '@xyflow/react'], */\nexport default defineConfig({})");

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('rejects a dedupe list missing one of the three packages', function () {
    // G-4 is specifically all three. Counterfactual: check only that `dedupe`
    // appears and this fails — a list with react alone still mounts two copies of
    // @xyflow/react, which is an invalid hook call that looks like a React bug.
    ($this->write)(str_replace(
        "dedupe: ['react', 'react-dom', '@xyflow/react'],",
        "dedupe: ['react', 'react-dom'],",
        wiredViteConfig(),
    ));

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('does not accept the three package names from outside the dedupe list', function () {
    // Counterfactual: search the whole file for the three names rather than the
    // dedupe array's own text, and this fails — every Vite config that imports
    // @vitejs/plugin-react and lists react in optimizeDeps reads as wired.
    ($this->write)(<<<'TS'
    import react from '@vitejs/plugin-react'
    export default defineConfig({
        optimizeDeps: { include: ['react', 'react-dom', '@xyflow/react'] },
        resolve: { dedupe: ['lodash'] },
    })
    TS);

    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('cannot wire when there is no vite config at all', function () {
    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
    expect($this->dedupe->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the vite config', function () {
    // These two steps verify only (E20). Counterfactual: give either an apply()
    // that edits the file and this fails — and a regex insertion into an
    // arbitrary vite.config.ts is exactly the edit E11 forbids, because a
    // passing re-read would not prove it landed in the exported config.
    ($this->write)(wiredViteConfig());

    $before = file_get_contents($this->root.'/vite.config.ts');

    $this->alias->apply();
    $this->dedupe->apply();

    expect(file_get_contents($this->root.'/vite.config.ts'))->toBe($before);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Unit/SourceTextTest.php tests/Feature/Install/ViteStepsTest.php
```

Expected: FAIL — classes not found. Capture.

- [ ] **Step 3: Create `SourceText`**

```php
<?php

namespace Nodeflow\Console;

/**
 * Comment stripping for host configuration files.
 *
 * WHY. `nodeflow:install` verifies three host settings it cannot safely edit, and
 * it verifies them by matching text. Matching raw text reports a host who
 * commented a setting out while debugging as correctly wired. The package already
 * settled this question once: tests/Support/RequestContextScanner.php runs
 * token_get_all() and drops T_COMMENT/T_DOC_COMMENT before matching, for exactly
 * this reason. There is no PHP tokeniser for TypeScript, so this scans characters
 * instead and copies string and template literals whole — a `//` inside a URL and
 * a `/*` inside a message must both survive.
 *
 * KNOWN LIMIT: a regular-expression literal containing `//` or the start of a
 * block comment is treated as a comment and truncated. Vite and tsconfig files do
 * not normally contain one, and the failure direction is safe — a truncated file
 * reports a wired host as unwired, which is a message rather than a silent pass.
 * Do not "fix" this by loosening the string handling.
 */
final class SourceText
{
    public static function withoutJsComments(string $source): string
    {
        $out = '';
        $length = strlen($source);
        $i = 0;

        while ($i < $length) {
            $char = $source[$i];
            $next = $source[$i + 1] ?? '';

            if ($char === '"' || $char === "'" || $char === '`') {
                $out .= $char;
                $i++;

                while ($i < $length) {
                    $out .= $source[$i];

                    // An escaped character can be the quote itself, so consume both
                    // or the scanner ends the string early and treats the rest of
                    // the file as string content — under which every check passes.
                    if ($source[$i] === '\\') {
                        $out .= $source[$i + 1] ?? '';
                        $i += 2;

                        continue;
                    }

                    if ($source[$i] === $char) {
                        $i++;

                        break;
                    }

                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '/') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            $out .= $char;
            $i++;
        }

        return $out;
    }

    /**
     * CSS has block comments only, so a scanner is unnecessary — and would be
     * wrong: an unquoted url(https://…) contains a `//` that is not a comment.
     */
    public static function withoutCssComments(string $source): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $source);
    }
}
```

- [ ] **Step 4: Create `ViteAliasStep`**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Verifies the Vite alias mapping @nodeflow/editor into the package source.
 *
 * Verify-only, never written (E20). Editing an arbitrary vite.config.ts needs a
 * TypeScript AST, which PHP does not have, and E11 permits only an edit whose
 * success can be re-verified.
 *
 * KNOWN LIMIT, stated rather than implied away: this proves the alias appears in
 * uncommented source. It does NOT prove the alias is in the configuration object
 * actually exported — a second defineConfig, or a dead conditional branch, would
 * satisfy this check. The failure it exists to catch is the setting being absent
 * or commented out, which is the one that happens.
 */
final class ViteAliasStep implements InstallStep
{
    public const CONFIG_CANDIDATES = ['vite.config.ts', 'vite.config.js', 'vite.config.mts', 'vite.config.mjs'];

    private const PACKAGE_SOURCE = 'atram/laravel-nodeflow/resources/js';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Vite alias (@nodeflow/editor)';
    }

    public function check(): InstallOutcome
    {
        $source = $this->configSource();

        if ($source === null) {
            return InstallOutcome::CannotWire;
        }

        return str_contains($source, '@nodeflow/editor') && str_contains($source, self::PACKAGE_SOURCE)
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::CannotWire;
    }

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        return <<<'TS'
        // vite.config.ts
        import path from 'node:path'

        export default defineConfig({
            resolve: {
                alias: {
                    '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js'),
                },
            },
        })
        TS;
    }

    /** Comment-stripped source of the host's Vite config, or null if there isn't one. */
    private function configSource(): ?string
    {
        foreach (self::CONFIG_CANDIDATES as $candidate) {
            $path = $this->basePath.'/'.$candidate;

            if ($this->files->exists($path)) {
                return SourceText::withoutJsComments($this->files->get($path));
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Create `ViteDedupeStep`**

Same shape, with this `check()` and `describe()`:

```php
    public function describe(): string
    {
        return 'Vite resolve.dedupe (react, react-dom, @xyflow/react)';
    }

    /**
     * Matched inside the dedupe array's own text, not across the whole file.
     *
     * Every Vite config in a React application mentions react somewhere — an
     * import of @vitejs/plugin-react, an optimizeDeps.include list — so a
     * whole-file search reports essentially every host as wired. Bounding the
     * match to the array is what makes this check mean anything.
     */
    public function check(): InstallOutcome
    {
        $source = $this->configSource();

        if ($source === null) {
            return InstallOutcome::CannotWire;
        }

        $offset = strpos($source, 'dedupe');

        if ($offset === false) {
            return InstallOutcome::CannotWire;
        }

        $end = strpos($source, ']', $offset);

        if ($end === false) {
            return InstallOutcome::CannotWire;
        }

        $list = substr($source, $offset, $end - $offset);

        foreach (['react', 'react-dom', '@xyflow/react'] as $package) {
            if (! str_contains($list, "'{$package}'") && ! str_contains($list, "\"{$package}\"")) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::AlreadyPresent;
    }
```

and this snippet:

```php
        return <<<'TS'
        // vite.config.ts — required when the package is symlinked for local
        // development. Vite resolves the symlink to its real path, so a bare
        // `react` import inside the package source can resolve from the package's
        // own node_modules (which exists for Vitest and tsc) instead of yours.
        // Two React copies on one page is "Invalid hook call", which reads as a
        // React bug rather than a configuration error.
        export default defineConfig({
            resolve: {
                dedupe: ['react', 'react-dom', '@xyflow/react'],
            },
        })
        TS;
```

Extract the shared `configSource()`, `CONFIG_CANDIDATES` and constructor into an abstract `ViteStep` or a trait if duplicating them across the two classes reads badly — your call, but do not let the two copies drift.

- [ ] **Step 6: Run the tests**

```bash
./vendor/bin/pest tests/Unit/SourceTextTest.php tests/Feature/Install/ViteStepsTest.php
```

Expected: all 12 pass.

- [ ] **Step 7: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **402 tests pass** (390 after Task 7, + 12). Record the measured assertion count.

- [ ] **Step 8: Execute the counterfactuals and restore**

The four named in the test comments, in this order, because two of them are the reason this task is not a one-liner: (a) remove `SourceText::withoutJsComments()` from `ViteAliasStep::configSource()` → `rejects a commented-out alias` fails; (b) search the whole file rather than the dedupe array → `does not accept the three package names from outside the dedupe list` fails; (c) check only that `dedupe` appears → `rejects a dedupe list missing one of the three packages` fails; (d) drop the backslash branch from `SourceText` → `preserves an escaped quote` fails. Capture all four, restore.

- [ ] **Step 9: Commit**

```bash
git add src/Console/SourceText.php src/Console/Install tests/Unit/SourceTextTest.php tests/Feature/Install/ViteStepsTest.php
git commit -m "feat: verify the two Vite settings, comment-stripped

E22 and G-4. Matching raw text reports a host who commented a setting out
while debugging as wired, which is why RequestContextScanner already
strips comments before matching. There is no PHP tokeniser for
TypeScript, so SourceText scans characters and copies string and template
literals whole — a // inside a URL must survive.

The dedupe check is bounded to the dedupe array's own text. Every React
app's Vite config mentions react somewhere, so a whole-file search would
report essentially every host as wired.

Both steps verify only. Editing an arbitrary vite.config.ts needs an AST,
and E11 permits only an edit whose success can be re-verified. The
docblocks state what the text check cannot prove rather than implying a
guarantee that is not there."
```

---

## Task 9: The tsconfig and `@xyflow/react` checks

**Files:**
- Create: `src/Console/Install/TsconfigPathsStep.php`, `src/Console/Install/XyflowDependencyStep.php`
- Test: `tests/Feature/Install/TsconfigPathsStepTest.php`, `tests/Feature/Install/XyflowDependencyStepTest.php`

**Interfaces:**
- Consumes: `InstallStep`, `InstallOutcome` (Task 4), `SourceText::withoutJsComments()` (Task 8).
- Produces: `final class TsconfigPathsStep implements InstallStep` and `final class XyflowDependencyStep implements InstallStep`, both `__construct(Filesystem $files, string $basePath)`.

**Why the tsconfig check is structural, not textual.** Measured on the accepted host: `json_decode` on `~/Sites/test-workflow/tsconfig.json` returns `null` with *Syntax error*, because it is the Laravel React starter kit's JSONC — around ninety lines of `//`-commented option documentation. So the file must be comment-stripped and trailing-comma-tolerant before it parses. And once it parses, assert **structurally**: the accepted host's value is `./vendor/atram/laravel-nodeflow/resources/js/index.ts` while `docs/08-editor-client.md` prints `./vendor/atram/laravel-nodeflow/resources/js`. Both are correct. A byte-match would call the accepted host broken.

**Why `@xyflow/react` is verified and not written.** `package.json` parses as strict JSON, so an edit is technically possible — but writing a dependency without running `npm install` leaves the manifest, the lockfile and `node_modules` disagreeing, which is a worse state than the one before the edit.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/TsconfigPathsStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\TsconfigPathsStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-tsconfig-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = fn (string $contents) => file_put_contents($this->root.'/tsconfig.json', $contents);
    $this->step = new TsconfigPathsStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

it('accepts the accepted host\'s jsonc tsconfig, comments and all', function () {
    // Measured: json_decode on the demo's real tsconfig.json returns null with
    // "Syntax error". Counterfactual: json_decode the raw file and this fails,
    // reporting the one installed host as unwired.
    ($this->write)(<<<'JSONC'
    {
        "compilerOptions": {
            /* Modules */
            // "rootDir": "./",
            "baseUrl": ".",
            "paths": {
                "@/*": ["./resources/js/*"],
                "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js/index.ts"],
                "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            },
        }
    }
    JSONC);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the directory form the docs print', function () {
    // docs/08-editor-client.md prints .../resources/js; the accepted host has
    // .../resources/js/index.ts. Both are correct. Counterfactual: compare to one
    // byte string and one of the two real-world forms is called broken.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
        '@nodeflow/editor/*' => ['./vendor/atram/laravel-nodeflow/resources/js/*'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('rejects a mapping that points somewhere else', function () {
    // Counterfactual: assert only that the two keys exist and this fails — a
    // mapping to the host's own resources/js type-checks against the wrong files
    // and silently resolves the wrong module.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./resources/js/nodeflow'],
        '@nodeflow/editor/*' => ['./resources/js/nodeflow/*'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('rejects a tsconfig with only the base mapping', function () {
    // Both mappings are required: docs/08-editor-client.md says so, and without
    // the wildcard a subpath import fails tsc while Vite still builds.
    ($this->write)(json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
    ]]]));

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('@nodeflow/editor/*');
});

it('rejects a commented-out mapping', function () {
    ($this->write)(<<<'JSONC'
    {
        "compilerOptions": {
            "paths": {
                // "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
                // "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            }
        }
    }
    JSONC);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('cannot wire a missing or unparseable tsconfig', function () {
    expect($this->step->check())->toBe(InstallOutcome::CannotWire);

    ($this->write)('{ this is not json at all ');

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the tsconfig', function () {
    // E20: a JSON round-trip destroys the starter kit's ninety-line comment
    // block, which is documentation the host owns.
    ($this->write)(<<<'JSONC'
    {
        // keep me
        "compilerOptions": { "paths": {} }
    }
    JSONC);

    $before = file_get_contents($this->root.'/tsconfig.json');

    $this->step->apply();

    expect(file_get_contents($this->root.'/tsconfig.json'))->toBe($before);
});
```

Create `tests/Feature/Install/XyflowDependencyStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\XyflowDependencyStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-xyflow-'.bin2hex(random_bytes(6));
    mkdir($this->root, 0777, true);

    $this->write = fn (array $manifest) => file_put_contents(
        $this->root.'/package.json',
        json_encode($manifest, JSON_PRETTY_PRINT),
    );

    $this->step = new XyflowDependencyStep(new Filesystem, $this->root);
});

afterEach(function () {
    foreach (glob($this->root.'/*') ?: [] as $file) {
        unlink($file);
    }
    @rmdir($this->root);
});

it('accepts the dependency in dependencies', function () {
    ($this->write)(['dependencies' => ['@xyflow/react' => '^12.11.3']]);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('accepts the dependency in devDependencies', function () {
    ($this->write)(['devDependencies' => ['@xyflow/react' => '^12.0.0']]);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
});

it('cannot wire a manifest without it, and says to npm install', function () {
    // Counterfactual: write the dependency into package.json here and this test
    // would pass while leaving the manifest, the lockfile and node_modules
    // disagreeing — a worse state than the one before the edit.
    ($this->write)(['dependencies' => ['react' => '^19.0.0']]);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('npm install @xyflow/react');
});

it('cannot wire a missing manifest', function () {
    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('never writes to the manifest', function () {
    ($this->write)(['dependencies' => ['react' => '^19.0.0']]);

    $before = file_get_contents($this->root.'/package.json');

    $this->step->apply();

    expect(file_get_contents($this->root.'/package.json'))->toBe($before);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/TsconfigPathsStepTest.php tests/Feature/Install/XyflowDependencyStepTest.php
```

Expected: FAIL — classes not found. Capture.

- [ ] **Step 3: Create `TsconfigPathsStep`**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Verifies both @nodeflow/editor path mappings in the host's tsconfig.
 *
 * WHY COMMENT-STRIPPED AND TRAILING-COMMA-TOLERANT. Measured on the only
 * installed host: json_decode on its tsconfig.json returns null with "Syntax
 * error", because the Laravel React starter kit ships roughly ninety lines of
 * //-commented option documentation in that file. A parser that cannot read it
 * would report the accepted host as unwired.
 *
 * WHY STRUCTURAL AND NOT TEXTUAL. That host maps @nodeflow/editor to
 * ".../resources/js/index.ts"; docs/08-editor-client.md prints
 * ".../resources/js". Both are correct, so the check resolves the value and asks
 * whether it lands inside the package's resources/js — not whether it equals a
 * string we chose.
 *
 * VERIFY-ONLY (E20). A JSON round-trip would write the file back without those
 * ninety lines of comments, which are documentation the host owns.
 *
 * KNOWN LIMIT: baseUrl is honoured only as a literal prefix. A tsconfig using
 * "extends" to inherit its paths from another file reads as unwired here, because
 * this does not follow the chain. The failure direction is safe — a message, not
 * a silent pass.
 */
final class TsconfigPathsStep implements InstallStep
{
    public const PATH = 'tsconfig.json';

    private const PACKAGE_SOURCE = 'vendor/atram/laravel-nodeflow/resources/js';

    private const MAPPINGS = ['@nodeflow/editor', '@nodeflow/editor/*'];

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'tsconfig paths (@nodeflow/editor)';
    }

    public function check(): InstallOutcome
    {
        $config = $this->decoded();

        if ($config === null) {
            return InstallOutcome::CannotWire;
        }

        $baseUrl = trim((string) ($config['compilerOptions']['baseUrl'] ?? '.'), './');
        $paths = $config['compilerOptions']['paths'] ?? [];

        if (! is_array($paths)) {
            return InstallOutcome::CannotWire;
        }

        foreach (self::MAPPINGS as $mapping) {
            $targets = $paths[$mapping] ?? null;

            if (! is_array($targets) || $targets === []) {
                return InstallOutcome::CannotWire;
            }

            $resolved = ltrim(trim((string) $targets[0]), './');

            if ($baseUrl !== '') {
                $resolved = $baseUrl.'/'.$resolved;
            }

            if (! str_starts_with($resolved, self::PACKAGE_SOURCE)) {
                return InstallOutcome::CannotWire;
            }
        }

        return InstallOutcome::AlreadyPresent;
    }

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        return <<<'JSONC'
        // tsconfig.json — compilerOptions.paths. Both mappings are needed: without
        // the wildcard, a subpath import fails the host's tsc while Vite still
        // builds, so the failure is quiet.
        {
          "compilerOptions": {
            "paths": {
              "@nodeflow/editor": ["./vendor/atram/laravel-nodeflow/resources/js"],
              "@nodeflow/editor/*": ["./vendor/atram/laravel-nodeflow/resources/js/*"]
            }
          }
        }
        JSONC;
    }

    /** JSONC in, array out, or null if there is nothing parseable here. */
    private function decoded(): ?array
    {
        $path = $this->basePath.'/'.self::PATH;

        if (! $this->files->exists($path)) {
            return null;
        }

        $json = SourceText::withoutJsComments($this->files->get($path));

        // Trailing commas are legal in JSONC and fatal to json_decode. The demo's
        // real tsconfig has one after its "paths" block.
        $json = (string) preg_replace('/,(\s*[}\]])/', '$1', $json);

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
```

- [ ] **Step 4: Create `XyflowDependencyStep`**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;

/**
 * Verifies @xyflow/react is in the host's manifest.
 *
 * The host's Vite compiles the package source, so Composer and the alias install
 * no npm dependencies on the package's behalf and an alias into vendor/ does not
 * pull that package's own dependencies.
 *
 * VERIFY-ONLY, and this one is a choice rather than a limitation: package.json
 * does parse as strict JSON, so writing the dependency is technically easy. It is
 * not done because writing it without running npm install leaves the manifest,
 * the lockfile and node_modules disagreeing — a worse state than the one before
 * the edit, and one whose symptom appears at build time in someone else's terminal.
 */
final class XyflowDependencyStep implements InstallStep
{
    public const PATH = 'package.json';

    private const PACKAGE = '@xyflow/react';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Host dependency ('.self::PACKAGE.')';
    }

    public function check(): InstallOutcome
    {
        $path = $this->basePath.'/'.self::PATH;

        if (! $this->files->exists($path)) {
            return InstallOutcome::CannotWire;
        }

        $manifest = json_decode($this->files->get($path), true);

        if (! is_array($manifest)) {
            return InstallOutcome::CannotWire;
        }

        $declared = array_merge(
            $manifest['dependencies'] ?? [],
            $manifest['devDependencies'] ?? [],
        );

        return array_key_exists(self::PACKAGE, $declared)
            ? InstallOutcome::AlreadyPresent
            : InstallOutcome::CannotWire;
    }

    /** Verify-only: check() never returns Writable, so this is unreachable. */
    public function apply(): InstallOutcome
    {
        return $this->check();
    }

    public function snippet(): ?string
    {
        return $this->check() === InstallOutcome::AlreadyPresent
            ? null
            : 'Run `npm install '.self::PACKAGE.'` in the application root. Nodeflow '
                .'does not add it for you: your Vite compiles our source, so an alias '
                .'into vendor/ pulls no npm dependencies, and writing the manifest '
                .'without running the installer would leave your lockfile disagreeing '
                .'with it.';
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/TsconfigPathsStepTest.php tests/Feature/Install/XyflowDependencyStepTest.php
```

Expected: all 12 pass.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **414 tests pass** (402 after Task 8, + 12). Record the measured assertion count.

- [ ] **Step 7: Prove the accepted host passes, not just the fixtures**

The fixtures approximate the real host. Prove it against the real one:

```bash
php -r '
require "vendor/autoload.php";
$step = new Nodeflow\Console\Install\TsconfigPathsStep(
    new Illuminate\Filesystem\Filesystem,
    getenv("HOME")."/Sites/test-workflow",
);
var_dump($step->check());
'
```

Expected: `AlreadyPresent`. If it is `CannotWire`, the structural check is wrong about a real file and that is a finding, not a reason to loosen the check until it passes — read the real tsconfig and work out which assumption broke.

- [ ] **Step 8: Execute the counterfactuals and restore**

(a) `json_decode` the raw file without stripping → `accepts the accepted host's jsonc tsconfig` fails; (b) compare to one literal byte string → one of the two directory-form tests fails; (c) assert only key existence → `rejects a mapping that points somewhere else` fails; (d) drop the trailing-comma regex → the JSONC test fails. Capture, restore.

- [ ] **Step 9: Commit**

```bash
git add src/Console/Install tests/Feature/Install
git commit -m "feat: verify tsconfig paths structurally, and the xyflow dependency

The accepted host's tsconfig.json does not parse as JSON — json_decode
returns null with 'Syntax error', because the Laravel React starter kit
ships ninety lines of //-commented option docs in it. So the file is
comment-stripped and trailing-comma-tolerant before parsing, and then
asserted structurally: that host maps @nodeflow/editor to
.../resources/js/index.ts while the docs print .../resources/js, and both
are correct. A byte-match would call the accepted host broken.

@xyflow/react is verified rather than written. package.json does parse, so
the edit is easy; it is refused because writing a dependency without
running npm install leaves the lockfile disagreeing with the manifest."
```

---

## Task 10: The Tailwind `@source` line — the one quiet failure `install` fixes

**Files:**
- Create: `src/Console/Install/TailwindSourceStep.php`
- Test: `tests/Feature/Install/TailwindSourceStepTest.php`

**Interfaces:**
- Consumes: `InstallStep`, `InstallOutcome` (Task 4), `SourceText::withoutCssComments()` (Task 8).
- Produces: `final class TailwindSourceStep implements InstallStep`, `__construct(Filesystem $files, string $basePath)`.

**Why this one is written when three siblings are not.** `docs/08-editor-client.md` calls it *"quiet, and the worst of the five"*: Tailwind v4's automatic source detection skips gitignored paths and applications gitignore `vendor/`, so without the line the build succeeds, the editor renders, and every utility used only by the package source is missing — with utilities the host happens to use elsewhere masking part of the damage. CSS is line-oriented and the insertion point is provable, so this is the one client requirement `install` can honestly fix.

**The path is computed, not literal.** `'../../vendor/atram/laravel-nodeflow/resources/js'` is correct only for an entry at `resources/css/app.css`. The relative prefix is derived from where the entry actually is.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Install/TailwindSourceStepTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\TailwindSourceStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-tailwind-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/resources/css', 0777, true);

    $this->step = new TailwindSourceStep(new Filesystem, $this->root);
    $this->entry = $this->root.'/resources/css/app.css';
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

it('writes the source line after the tailwind import', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n\n@source '../views';\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);
    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    $css = file_get_contents($this->entry);

    expect($css)->toContain("@source '../../vendor/atram/laravel-nodeflow/resources/js';")
        ->toContain("@source '../views';");

    // Order matters to Tailwind only in that the import must come first.
    expect(strpos($css, "@import 'tailwindcss';"))
        ->toBeLessThan(strpos($css, 'atram/laravel-nodeflow'));
});

it('computes the relative path from wherever the entry actually is', function () {
    // Counterfactual: hardcode '../../' and this fails — the emitted @source
    // points outside the project and Tailwind silently matches nothing, which is
    // the exact failure mode this step exists to prevent.
    mkdir($this->root.'/resources/assets/styles/main', 0777, true);
    unlink($this->entry);
    rmdir($this->root.'/resources/css');

    $deep = $this->root.'/resources/assets/styles/main/entry.css';
    file_put_contents($deep, "@import 'tailwindcss';\n");

    expect($this->step->apply())->toBe(InstallOutcome::Wired);

    expect(file_get_contents($deep))
        ->toContain("@source '../../../../vendor/atram/laravel-nodeflow/resources/js';");
});

it('is idempotent and never writes the line twice', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n");

    $this->step->apply();
    $before = file_get_contents($this->entry);

    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect(file_get_contents($this->entry))->toBe($before);
    expect(substr_count($before, 'atram/laravel-nodeflow/resources/js'))->toBe(1);
});

it('treats a commented-out source line as absent', function () {
    // Counterfactual: check raw text and this fails, leaving a host who commented
    // the line out with no utilities and a green install.
    file_put_contents($this->entry, "@import 'tailwindcss';\n/* @source '../../vendor/atram/laravel-nodeflow/resources/js'; */\n");

    expect($this->step->check())->toBe(InstallOutcome::Writable);

    $this->step->apply();

    // The commented line is left alone; a live one is added.
    $css = SourceText::withoutCssComments(file_get_contents($this->entry));
    expect(substr_count($css, 'atram/laravel-nodeflow/resources/js'))->toBe(1);
})->skip('Enable once SourceText is imported in this file; see Step 2.');

it('cannot wire when no css entry contains the tailwind import', function () {
    file_put_contents($this->entry, "body { color: red }\n");

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect($this->step->snippet())->toContain('@source');
});

it('cannot wire when two css files both look like the entry', function () {
    // Counterfactual: pick the first match and this fails — install would write
    // into whichever file globbed first, which is not a decision it can make.
    file_put_contents($this->entry, "@import 'tailwindcss';\n");
    file_put_contents($this->root.'/resources/css/admin.css', "@import 'tailwindcss';\n");

    // resources/css/app.css is the convention, so it wins outright rather than
    // being ambiguous. Renaming it is what creates the ambiguity.
    expect($this->step->check())->toBe(InstallOutcome::Writable);

    unlink($this->entry);

    file_put_contents($this->root.'/resources/css/site.css', "@import 'tailwindcss';\n");

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
});

it('refuses a file whose tailwind import appears twice', function () {
    file_put_contents($this->entry, "@import 'tailwindcss';\n@import 'tailwindcss';\n");

    $before = file_get_contents($this->entry);

    expect($this->step->check())->toBe(InstallOutcome::CannotWire);
    expect(file_get_contents($this->entry))->toBe($before);
});
```

- [ ] **Step 2: Add the missing import and un-skip**

Add `use Nodeflow\Console\SourceText;` to the top of the test file and delete the `->skip(...)` from `treats a commented-out source line as absent`. The skip is in the plan so the test is written before the import is remembered, not as a permanent state — a skipped test that ships is a test that does not exist.

- [ ] **Step 3: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/Install/TailwindSourceStepTest.php
```

Expected: FAIL — class not found. Capture.

- [ ] **Step 4: Create the step**

```php
<?php

namespace Nodeflow\Console\Install;

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\SourceText;

/**
 * Writes the Tailwind @source line for the package's TypeScript source.
 *
 * The one client requirement install fixes rather than reports, for two reasons.
 * It is the worst of the five: Tailwind v4's automatic source detection skips
 * gitignored paths and applications gitignore vendor/, so without this line the
 * build succeeds, the editor renders, and every utility used only by the package
 * source is missing — with utilities the host happens to use elsewhere masking
 * part of the damage. And CSS is line-oriented, so unlike vite.config.ts the
 * insertion point can be proven and the result re-read.
 *
 * The relative path is COMPUTED. '../../vendor/…' is correct only for an entry at
 * resources/css/app.css; from anywhere else that string points outside the
 * project and Tailwind silently matches nothing, which is the same failure this
 * step exists to prevent.
 */
final class TailwindSourceStep implements InstallStep
{
    private const PACKAGE_SOURCE = 'vendor/atram/laravel-nodeflow/resources/js';

    private const CONVENTIONAL_ENTRY = 'resources/css/app.css';

    private const IMPORT_PATTERN = '/^[ \t]*@import\s+[\'"]tailwindcss[\'"].*$/m';

    public function __construct(private Filesystem $files, private string $basePath) {}

    public function describe(): string
    {
        return 'Tailwind @source (package source)';
    }

    public function check(): InstallOutcome
    {
        $entry = $this->entry();

        if ($entry === null) {
            return InstallOutcome::CannotWire;
        }

        $raw = $this->files->get($entry);

        // Comment-stripped, so a host who commented the line out while debugging
        // is told the truth rather than told they are wired.
        if (str_contains(SourceText::withoutCssComments($raw), self::PACKAGE_SOURCE)) {
            return InstallOutcome::AlreadyPresent;
        }

        // The anchor must be unique in the raw file too, not only in the stripped
        // one: the insertion offset is computed against the raw bytes, so a second
        // occurrence inside a comment would make the two disagree.
        return preg_match_all(self::IMPORT_PATTERN, $raw) === 1
            ? InstallOutcome::Writable
            : InstallOutcome::CannotWire;
    }

    public function apply(): InstallOutcome
    {
        if ($this->check() !== InstallOutcome::Writable) {
            return $this->check();
        }

        $entry = (string) $this->entry();
        $raw = $this->files->get($entry);

        preg_match(self::IMPORT_PATTERN, $raw, $matches, PREG_OFFSET_CAPTURE);

        $insertAt = $matches[0][1] + strlen($matches[0][0]);

        $this->files->put($entry, substr_replace(
            $raw,
            PHP_EOL."@source '".$this->relativePath($entry)."';",
            $insertAt,
            0,
        ));

        // E11: re-read and prove it.
        return str_contains(
            SourceText::withoutCssComments($this->files->get($entry)),
            self::PACKAGE_SOURCE,
        ) ? InstallOutcome::Wired : InstallOutcome::CannotWire;
    }

    public function snippet(): ?string
    {
        if ($this->check() === InstallOutcome::AlreadyPresent) {
            return null;
        }

        $entry = $this->entry();

        $path = $entry === null
            ? '../../'.self::PACKAGE_SOURCE
            : $this->relativePath($entry);

        return "Add this to your Tailwind CSS entry, after `@import 'tailwindcss';`:"
            .PHP_EOL.PHP_EOL."    @source '".$path."';".PHP_EOL.PHP_EOL
            .'Tailwind v4 skips gitignored paths when detecting sources, and vendor/ '
            .'is gitignored — so without it the build succeeds, the editor renders, '
            .'and every utility used only by our source is missing.';
    }

    /**
     * The host's Tailwind entry, or null.
     *
     * The conventional path wins outright when it is a Tailwind entry, so a host
     * with several stylesheets is not ambiguous. Ambiguity only arises when the
     * convention is absent and more than one candidate imports Tailwind — and then
     * this refuses, because which file is the entry is not install's decision.
     */
    private function entry(): ?string
    {
        $conventional = $this->basePath.'/'.self::CONVENTIONAL_ENTRY;

        if ($this->files->exists($conventional) && $this->importsTailwind($conventional)) {
            return $conventional;
        }

        $candidates = array_values(array_filter(
            $this->files->glob($this->basePath.'/resources/css/*.css') ?: [],
            fn (string $path) => $this->importsTailwind($path),
        ));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function importsTailwind(string $path): bool
    {
        return preg_match(
            self::IMPORT_PATTERN,
            SourceText::withoutCssComments($this->files->get($path)),
        ) === 1;
    }

    /** How many `../` it takes to get from the entry's directory back to the project root. */
    private function relativePath(string $entry): string
    {
        $directory = trim(str_replace($this->basePath, '', dirname($entry)), '/');

        $depth = $directory === '' ? 0 : count(explode('/', $directory));

        return str_repeat('../', $depth).self::PACKAGE_SOURCE;
    }
}
```

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/Install/TailwindSourceStepTest.php
```

Expected: all 7 pass, none skipped.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **421 tests pass** (414 after Task 9, + 7). Record the measured assertion count.

- [ ] **Step 7: Prove it against the accepted host, read-only**

```bash
php -r '
require "vendor/autoload.php";
$step = new Nodeflow\Console\Install\TailwindSourceStep(
    new Illuminate\Filesystem\Filesystem,
    getenv("HOME")."/Sites/test-workflow",
);
var_dump($step->check());
'
```

Expected: `AlreadyPresent` — the demo has the line at `resources/css/app.css:6`. **Do not call `apply()` against the real host from a probe.**

- [ ] **Step 8: Execute the counterfactuals and restore**

(a) hardcode `'../../'` in `relativePath()` → `computes the relative path from wherever the entry actually is` fails; (b) check raw text instead of comment-stripped → `treats a commented-out source line as absent` fails; (c) take `$candidates[0]` instead of requiring exactly one → `cannot wire when two css files both look like the entry` fails; (d) drop the `preg_match_all(...) === 1` guard → `refuses a file whose tailwind import appears twice` fails. Capture, restore.

- [ ] **Step 9: Commit**

```bash
git add src/Console/Install/TailwindSourceStep.php tests/Feature/Install/TailwindSourceStepTest.php
git commit -m "feat: write the Tailwind @source line, with a computed path

The one client requirement install fixes rather than reports. It is the
worst of the five — Tailwind v4 skips gitignored paths and vendor/ is
gitignored, so without the line the build succeeds, the editor renders,
and every utility used only by our source is missing, with the host's own
utilities masking part of the damage. And CSS is line-oriented, so the
insertion point is provable and the result can be re-read.

The relative prefix is computed from where the entry actually is.
'../../vendor/…' is right only for resources/css/app.css; from anywhere
else it points outside the project and Tailwind silently matches nothing,
which is the same failure again.

The conventional entry wins outright; ambiguity between two candidates is
CannotWire, because which file is the entry is not install's decision."
```

---

## Task 11: `nodeflow:install` — the command

**Files:**
- Create: `src/Console/InstallCommand.php`
- Modify: `src/NodeflowServiceProvider.php` (register the command)
- Test: `tests/Feature/InstallCommandTest.php`

**Interfaces:**
- Consumes: all nine steps and `InstallOutcome` (Tasks 4–10), `NodeRegistrationWriter` (Task 3).
- Produces: `nodeflow:install` with `--check`, `--publish-migrations`, `--force-migrations`. `handle(): int`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/InstallCommandTest.php`:

```php
<?php

use Illuminate\Support\Facades\Gate;
use Nodeflow\Console\Install\ProviderStep;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-install-cmd-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app/Providers', 0777, true);
    mkdir($this->root.'/bootstrap', 0777, true);
    mkdir($this->root.'/config', 0777, true);
    mkdir($this->root.'/resources/css', 0777, true);
    mkdir($this->root.'/database/migrations', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    file_put_contents($this->root.'/bootstrap/providers.php', "<?php\n\nreturn [\n];\n");
    file_put_contents($this->root.'/resources/css/app.css', "@import 'tailwindcss';\n");

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

/** Write the three client settings install cannot write, so only the writable ones are missing. */
function writeClientWiring(string $root): void
{
    file_put_contents($root.'/vite.config.ts', <<<'TS'
    export default defineConfig({
        resolve: {
            alias: { '@nodeflow/editor': path.resolve(__dirname, 'vendor/atram/laravel-nodeflow/resources/js') },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    })
    TS);

    file_put_contents($root.'/tsconfig.json', json_encode(['compilerOptions' => ['paths' => [
        '@nodeflow/editor' => ['./vendor/atram/laravel-nodeflow/resources/js'],
        '@nodeflow/editor/*' => ['./vendor/atram/laravel-nodeflow/resources/js/*'],
    ]]]));

    file_put_contents($root.'/package.json', json_encode(['dependencies' => ['@xyflow/react' => '^12.0.0']]));
}

it('exits non-zero when it cannot wire the client requirements', function () {
    // The whole reason this command exists. Counterfactual: return SUCCESS
    // unconditionally — or return `false` from handle() instead of an int, which
    // Laravel casts to exit code 0 — and this fails. Three of the five client
    // requirements fail quietly, so a half-installed host that exits 0 is
    // indistinguishable from a working one in CI.
    $this->artisan('nodeflow:install')->assertExitCode(1);
});

it('exits zero on a host it could fully wire', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect($this->root.'/'.ProviderStep::PATH)->toBeFile();
    expect($this->root.'/config/nodeflow.php')->toBeFile();
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))
        ->toContain('NodeflowServiceProvider::class');
    expect(file_get_contents($this->root.'/resources/css/app.css'))
        ->toContain('atram/laravel-nodeflow/resources/js');
});

it('is idempotent: a second run writes nothing and still exits zero', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    $before = [
        'provider' => file_get_contents($this->root.'/'.ProviderStep::PATH),
        'bootstrap' => file_get_contents($this->root.'/bootstrap/providers.php'),
        'css' => file_get_contents($this->root.'/resources/css/app.css'),
        'config' => file_get_contents($this->root.'/config/nodeflow.php'),
    ];

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect(file_get_contents($this->root.'/'.ProviderStep::PATH))->toBe($before['provider']);
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))->toBe($before['bootstrap']);
    expect(file_get_contents($this->root.'/resources/css/app.css'))->toBe($before['css']);
    expect(file_get_contents($this->root.'/config/nodeflow.php'))->toBe($before['config']);
});

it('writes nothing under --check and exits non-zero when anything is unwired', function () {
    // Counterfactual: let --check fall through to apply() and this fails, having
    // modified four host files during what the host asked to be a read.
    $this->artisan('nodeflow:install', ['--check' => true])->assertExitCode(1);

    expect($this->root.'/'.ProviderStep::PATH)->not->toBeFile();
    expect($this->root.'/config/nodeflow.php')->not->toBeFile();
    expect(file_get_contents($this->root.'/bootstrap/providers.php'))
        ->not->toContain('NodeflowServiceProvider');
    expect(file_get_contents($this->root.'/resources/css/app.css'))
        ->not->toContain('atram/laravel-nodeflow');
});

it('exits zero under --check on a fully wired host', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);
    $this->artisan('nodeflow:install', ['--check' => true])->assertExitCode(0);
});

it('does not publish migrations by default', function () {
    // E19. Counterfactual: publish by default and every fresh install lays down a
    // copy that shadows the package's own file for every migrate run, forever.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')->assertExitCode(0);

    expect(glob($this->root.'/database/migrations/*.php'))->toBe([]);
});

it('publishes migrations on request', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install', ['--publish-migrations' => true])->assertExitCode(0);

    expect(glob($this->root.'/database/migrations/*.php'))->not->toBe([]);
});

it('exits non-zero when a published migration has drifted', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install', ['--publish-migrations' => true])->assertExitCode(0);

    $copy = glob($this->root.'/database/migrations/*.php')[0];
    file_put_contents($copy, file_get_contents($copy)."\n// host edit\n");

    $this->artisan('nodeflow:install')->assertExitCode(1);
    $this->artisan('nodeflow:install', ['--force-migrations' => true])->assertExitCode(0);
});

it('reports undefined gates without failing on them', function () {
    // A report, never an outcome. Counterfactual: fold the gate report into the
    // exit code and this fails — an undefined gate is the correct state
    // immediately after install, so the first run would always be red and every
    // host would learn to ignore the exit code that Task 11's whole point is.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('nodeflow.viewAny')
        ->assertExitCode(0);
});

it('reports all four gates as defined when they are', function () {
    writeClientWiring($this->root);

    foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
        Gate::define('nodeflow.'.$ability, fn () => true);
    }

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('All four authorization gates are defined')
        ->assertExitCode(0);
});

it('reports the resolved tenancy mode and which resolver auto is reading', function () {
    // Counterfactual: print config('nodeflow.tenancy') alone and this fails — the
    // string 'auto' does not tell a host what a null tenant will do, and which
    // resolver is bound is exactly what decides it.
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('no TenantResolver bound')
        ->assertExitCode(0);
});

it('prints the exact snippet for each requirement it cannot wire', function () {
    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('@nodeflow/editor')
        ->expectsOutputToContain('dedupe')
        ->expectsOutputToContain('npm install @xyflow/react')
        ->assertExitCode(1);
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/InstallCommandTest.php
```

Expected: FAIL — `The command "nodeflow:install" does not exist`. Capture.

- [ ] **Step 3: Create the command**

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Console\Install\InstallOutcome;
use Nodeflow\Console\Install\InstallStep;
use Nodeflow\Console\Install\MigrationStep;
use Nodeflow\Console\Install\ProviderRegistrationStep;
use Nodeflow\Console\Install\ProviderStep;
use Nodeflow\Console\Install\PublishConfigStep;
use Nodeflow\Console\Install\TailwindSourceStep;
use Nodeflow\Console\Install\TsconfigPathsStep;
use Nodeflow\Console\Install\ViteAliasStep;
use Nodeflow\Console\Install\ViteDedupeStep;
use Nodeflow\Console\Install\XyflowDependencyStep;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Tenancy\NoTenancyResolver;

/**
 * Installs Nodeflow into a host application, and then verifies it did.
 *
 * The verification is the point, not a nicety. Of the five client-side wiring
 * requirements, three fail quietly — the tsconfig paths (Vite still builds, the
 * host's tsc does not), the Tailwind @source line (the build succeeds and every
 * utility used only by our source is missing), and resolve.dedupe (two React
 * copies, reported as "Invalid hook call", which reads as a React bug). A sixth,
 * listing the provider in bootstrap/providers.php, fails just as quietly.
 *
 * So the exit code is a contract. handle() is declared int deliberately:
 * returning false from a Laravel command's handle() is cast with (int) and exits
 * 0, which would silently defeat this command's own reason for existing.
 */
class InstallCommand extends Command
{
    protected $signature = 'nodeflow:install
        {--check : Verify everything and write nothing}
        {--publish-migrations : Also publish the package migrations into database/migrations}
        {--force-migrations : Re-publish over a published copy that has drifted}';

    protected $description = 'Install Nodeflow into this application, and verify the wiring.';

    private const GATES = [
        'nodeflow.viewAny',
        'nodeflow.update',
        'nodeflow.publish',
        'nodeflow.runManually',
    ];

    public function handle(): int
    {
        $steps = $this->steps();

        // Every check() before any apply(). A step that fails halfway through must
        // not be able to leave a host half-wired, and check() is contractually
        // read-only so this ordering costs nothing.
        $outcomes = array_map(fn (InstallStep $step) => $step->check(), $steps);

        if (! $this->option('check')) {
            foreach ($steps as $index => $step) {
                if ($outcomes[$index] === InstallOutcome::Writable) {
                    $outcomes[$index] = $step->apply();
                }
            }
        }

        $this->table(
            ['Requirement', 'Status'],
            array_map(
                fn (InstallStep $step, InstallOutcome $outcome) => [$step->describe(), $this->label($outcome)],
                $steps,
                $outcomes,
            ),
        );

        foreach ($steps as $index => $step) {
            if ($outcomes[$index] === InstallOutcome::CannotWire && $step->snippet() !== null) {
                $this->newLine();
                $this->components->warn($step->describe().' — add this yourself:');
                $this->newLine();
                $this->line($step->snippet());
            }
        }

        $this->newLine();
        $this->reportGates();
        $this->reportTenancy();

        return $this->exitCode($outcomes);
    }

    /** @return InstallStep[] */
    private function steps(): array
    {
        $files = $this->laravel->make(Filesystem::class);
        $base = $this->laravel->basePath();
        $namespace = $this->laravel->getNamespace();
        $writer = $this->laravel->make(NodeRegistrationWriter::class);

        $force = (bool) $this->option('force-migrations');

        return [
            new PublishConfigStep($files, $base),
            // --force-migrations implies --publish-migrations: re-publishing over a
            // drifted copy is publishing.
            new MigrationStep(
                $files,
                $base,
                publish: $force || (bool) $this->option('publish-migrations'),
                force: $force,
            ),
            new ProviderStep($files, $base, $namespace, $writer),
            new ProviderRegistrationStep($files, $base, $namespace, $writer),
            new TailwindSourceStep($files, $base),
            new ViteAliasStep($files, $base),
            new ViteDedupeStep($files, $base),
            new TsconfigPathsStep($files, $base),
            new XyflowDependencyStep($files, $base),
        ];
    }

    /**
     * Non-zero iff something is not wired.
     *
     * Under --check, Writable counts as unwired: the host asked whether this
     * application is installed, and "it would be if you let me write" is a no.
     */
    private function exitCode(array $outcomes): int
    {
        $failing = $this->option('check')
            ? [InstallOutcome::CannotWire, InstallOutcome::Writable]
            : [InstallOutcome::CannotWire];

        foreach ($outcomes as $outcome) {
            if (in_array($outcome, $failing, true)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function label(InstallOutcome $outcome): string
    {
        return match ($outcome) {
            InstallOutcome::AlreadyPresent => 'already wired',
            InstallOutcome::Wired => 'wired',
            InstallOutcome::Writable => 'NOT WIRED (would be written)',
            InstallOutcome::CannotWire => 'NOT WIRED',
        };
    }

    /**
     * A report, never an outcome.
     *
     * An undefined gate is the correct state immediately after installing: the
     * host has not written its authorization rules yet. Folding this into the exit
     * code would make the very first run red, and a command whose first run is
     * always red teaches its users to ignore its exit code — which is the one
     * thing this command cannot afford.
     */
    private function reportGates(): void
    {
        $undefined = array_values(array_filter(self::GATES, fn (string $gate) => ! Gate::has($gate)));

        if ($undefined === []) {
            $this->components->info('All four authorization gates are defined.');

            return;
        }

        $this->components->warn(
            'Undefined authorization gates: '.implode(', ', $undefined).'. Nodeflow '
            .'denies every ability whose gate is undefined, so those actions return 403 '
            .'until you define them — see docs/02-integration.md, "Authorization: four gates".'
        );
    }

    /**
     * Reports what a null tenant will actually do, not just the config string.
     *
     * 'auto' alone tells a host nothing, because auto's answer depends on which
     * TenantResolver is in the container — which is exactly the thing a host is
     * least likely to have thought about.
     */
    private function reportTenancy(): void
    {
        $mode = config('nodeflow.tenancy');
        $resolver = $this->laravel->make(TenantResolver::class);

        $this->components->info('nodeflow.tenancy: '.match ($mode) {
            'auto' => $resolver instanceof NoTenancyResolver
                ? 'auto — no TenantResolver bound, so a null tenant means "this application has '
                    .'no tenancy" and scoped reads are unscoped'
                : 'auto — '.$resolver::class.' is bound, so a null tenant throws '
                    .'TenancyUnresolvedException rather than reading every tenant\'s rows. Bind it '
                    .'unconditionally in register(), never in middleware.',
            'disabled' => 'disabled — a null tenant always reads unscoped. Only correct if this '
                .'application genuinely has no tenancy.',
            'resolver' => 'resolver — a null tenant always throws.',
            default => 'UNRECOGNISED value '.var_export($mode, true).' — every scoped read will '
                .'throw InvalidArgumentException. Valid values are auto, disabled and resolver, '
                .'matched exactly. Run `php artisan config:clear` if a cached config predates the key.',
        });
    }
}
```

- [ ] **Step 4: Register the command**

In `src/NodeflowServiceProvider.php`, add to the `$this->commands([...])` array inside `if ($this->app->runningInConsole())`:

```php
                \Nodeflow\Console\InstallCommand::class,
```

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/InstallCommandTest.php
```

Expected: all 12 pass. If `expectsOutputToContain` fails on a snippet, the table may be truncating output — assert on the snippet body rather than the table cell.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **433 tests pass** (421 after Task 10, + 12). Record the measured assertion count.

- [ ] **Step 7: Run `--check` against the accepted host, read-only**

```bash
cd ~/Sites/test-workflow && php artisan nodeflow:install --check; echo "exit=$?"
```

Expected: exit **1**, because the demo's provider predates the three registration homes (Task 5's case) — everything else already wired. That is the correct answer and it is the evidence Task 5 exists. **Do not run without `--check` against the demo yet**; that happens in Task 16 deliberately.

- [ ] **Step 8: Execute the counterfactuals and restore**

(a) change `handle(): int` to `handle()` returning `false` on failure and confirm `exits non-zero when it cannot wire` fails with exit 0 — this is the §7.1 constraint-3 trap, executed; (b) let `--check` fall through to `apply()` and confirm `writes nothing under --check` fails; (c) fold the gate report into `exitCode()` and confirm `reports undefined gates without failing on them` fails; (d) print `config('nodeflow.tenancy')` alone and confirm the tenancy test fails. Capture all four, restore.

- [ ] **Step 9: Commit**

```bash
git add src/Console/InstallCommand.php src/NodeflowServiceProvider.php tests/Feature/InstallCommandTest.php
git commit -m "feat: nodeflow:install, with its exit code as the contract

E21. Every check() runs before any apply(), so a step failing halfway
cannot leave a host half-wired. Non-zero iff any requirement ends
CannotWire — or, under --check, CannotWire or Writable, because 'it would
be wired if you let me write' is a no when the host asked a question.

handle() is declared int on purpose: returning false from a Laravel
command's handle() is cast with (int) and exits 0, which would silently
defeat this command's entire reason for existing. There is a test that
executes that trap.

The gate report and the tenancy report never touch the exit code. An
undefined gate is the correct state right after installing, and a command
whose first run is always red teaches hosts to ignore its exit code."
```

---

## Task 12: `nodeflow:make-trigger`

**Files:**
- Create: `src/Console/MakeTriggerCommand.php`, `stubs/trigger.stub`
- Modify: `src/NodeflowServiceProvider.php` (register the command)
- Test: `tests/Feature/MakeTriggerCommandTest.php`

**Interfaces:**
- Consumes: `NodeRegistrationWriter::TRIGGER_ANCHOR` and `appendTo()` (Task 3); the provider shape from Tasks 4–5.
- Produces: `nodeflow:make-trigger {name} {--event=} {--type=} {--force|-f}`.

**Why it earns its place (§7.3).** `event()` returning a host event class is the most confusable part of the trigger contract. And per **E24** this command *registers* what it generates, extending §7.3 — because `docs/02-integration.md` already warns that `TriggerRegistry::register()` attaches the listener at the moment it happens, so a trigger nobody registers never fires. A generator whose default output is a trigger that never fires ships the documented failure.

**`--event` is warned about, not rejected, when the class does not exist.** Generating the trigger before writing the event is a normal order of work, and `::class` needs no loaded class to render. Omitted entirely and non-interactive is an error, because there is no sane default for an event class.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MakeTriggerCommandTest.php`:

```php
<?php

use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerRegistry;

/** A stand-in host event, so --event can name a class that genuinely exists. */
class MakeTriggerTestEvent
{
    public function __construct(public string $tenantId = 't1', public array $userIds = ['7']) {}
}

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-trigger-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

it('generates a trigger at the conventional path naming the event class', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FloodAlertFires',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'rada.flood_alert',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Triggers/FloodAlertFires.php';

    expect($path)->toBeFile();

    expect(file_get_contents($path))
        ->toContain('namespace App\Nodeflow\Triggers;')
        ->toContain('class FloodAlertFires extends Trigger')
        ->toContain("return 'rada.flood_alert';")
        ->toContain('return \MakeTriggerTestEvent::class;');
});

it('produces a trigger the registry accepts and whose methods execute', function () {
    // The require-and-execute test, for the same reason node.stub has one: php -l
    // resolves no symbols, so a rename of TriggerDefinition::make(),
    // ::description() or TriggerMatch::make() would leave this suite green while
    // the stub fataled in every host that generated from it.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'OrderPlacedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.order_placed',
    ])->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Triggers/OrderPlacedTrigger.php';

    $class = 'App\Nodeflow\Triggers\OrderPlacedTrigger';

    app(TriggerRegistry::class)->register($class);

    expect($class::type())->toBe('shop.order_placed');
    expect($class::event())->toBe(MakeTriggerTestEvent::class);

    $trigger = new $class;

    expect($trigger)->toBeInstanceOf(Trigger::class);

    // definition() executes the whole TriggerDefinition chain as a side effect.
    expect($trigger->definition())->toBeInstanceOf(TriggerDefinition::class);
    expect($trigger->definition()->toArray())->toHaveKey('label');

    // resolve() must return a real TriggerMatch, not null: the scaffolded body is
    // a safe no-op, and "no tenants" is a legitimate answer, but the type is not
    // optional and a stub returning nothing would fatal on the first event.
    $match = $trigger->resolve(new MakeTriggerTestEvent);

    expect($match)->toBeInstanceOf(TriggerMatch::class);
    expect($match->tenants())->toBe([]);

    // The two commented overrides must stay commented, and the inherited defaults
    // must therefore still answer.
    expect($trigger->idempotencyKey(new MakeTriggerTestEvent))->toBeNull();
    expect($trigger->matchesConfig(new MakeTriggerTestEvent, []))->toBeTrue();
});

it('registers the trigger in the provider through the trigger anchor', function () {
    // E24. Counterfactual: skip registration and this fails — and a host whose
    // generated trigger is never registered gets a listener that was never
    // attached, so the trigger silently never fires.
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $triggers = [
        ];
    }
    PHP);

    $this->artisan('nodeflow:make-trigger', [
        'name' => 'RegisteredTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.registered',
    ])->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\App\Nodeflow\Triggers\RegisteredTrigger::class,');
});

it('prints the registration line when there is no provider, and still exits zero', function () {
    // Same contract as make-node: never guess, always explain, and generating the
    // file is still a success.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'UnregisteredTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.unregistered',
    ])
        ->expectsOutputToContain('TriggerRegistry')
        ->assertExitCode(0);
});

it('warns but still generates when the event class does not exist', function () {
    // Generating the trigger before writing the event is a normal order of work,
    // and ::class renders without a loaded class. Counterfactual: reject the
    // missing class and this fails, blocking that order of work.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'FutureTrigger',
        '--event' => 'App\Events\NotWrittenYet',
        '--type' => 'shop.future',
    ])
        ->expectsOutputToContain('App\Events\NotWrittenYet')
        ->assertExitCode(0);

    expect($this->root.'/app/Nodeflow/Triggers/FutureTrigger.php')->toBeFile();
});

it('fails without --event when it cannot prompt', function () {
    // There is no sane default for an event class, and a trigger whose event() is
    // wrong never fires. Counterfactual: derive one from the class name and this
    // fails — the derived value would be a class that does not exist, silently.
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'NoEventTrigger',
        '--type' => 'shop.no_event',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/NoEventTrigger.php')->not->toBeFile();
});

it('rejects a reserved core type', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'ReservedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'core.something',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/ReservedTrigger.php')->not->toBeFile();
});

it('rejects a malformed type', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'BadTypeTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'Shop Order',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Triggers/BadTypeTrigger.php')->not->toBeFile();
});

it('generates a file that passes php -l', function () {
    $this->artisan('nodeflow:make-trigger', [
        'name' => 'LintedTrigger',
        '--event' => MakeTriggerTestEvent::class,
        '--type' => 'shop.linted',
    ])->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Triggers/LintedTrigger.php';

    exec('php -l '.escapeshellarg($path).' 2>&1', $output, $status);

    expect($status)->toBe(0, implode(PHP_EOL, $output));
});
```

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php
```

Expected: FAIL — command does not exist. Capture.

- [ ] **Step 3: Create the stub**

`stubs/trigger.stub`:

```php
<?php

namespace {{ namespace }};

use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;

class {{ class }} extends Trigger
{
    /**
     * The stable identifier for this trigger type.
     *
     * A flow's trigger_type stores this string, so changing it orphans every flow
     * that references it. Never derive it from the class name.
     */
    public static function type(): string
    {
        return '{{ type }}';
    }

    /**
     * The host event class this trigger listens to.
     *
     * This is the confusable part of the contract, so read it once: registering
     * the trigger calls Event::listen() for this class, at the moment of
     * registration. Name the wrong class and nothing errors — the listener is
     * attached to an event that never fires, and the trigger is simply silent.
     */
    public static function event(): string
    {
        return \{{ event }}::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('{{ label }}')
            ->description('TODO: describe when this trigger fires, for the person authoring a flow.');
    }

    /**
     * Which subjects this event should start, grouped by tenant.
     *
     * Returning an empty match is legitimate and means "this event starts
     * nobody" — so the scaffolded body below is a safe no-op rather than a
     * placeholder that throws.
     */
    public function resolve(object $event): TriggerMatch
    {
        // TODO: return the subjects this event should start. For example:
        //
        // return TriggerMatch::make()->forTenant(
        //     (string) $event->tenantId,
        //     'user',
        //     $event->userIds,
        // );

        return TriggerMatch::make();
    }

    // Uncomment when the event carries a natural identity for one firing. Without
    // it, two deliveries of the same event start two runs.
    //
    // public function idempotencyKey(object $event): ?string
    // {
    //     return 'alert-'.$event->id;
    // }

    // Uncomment to let a flow's own trigger config narrow which firings it wants.
    // The default accepts every firing.
    //
    // public function matchesConfig(object $event, array $config): bool
    // {
    //     return ($config['severity'] ?? null) === $event->severity;
    // }
}
```

- [ ] **Step 4: Create the command**

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

use function Laravel\Prompts\text;

/**
 * Scaffolds a Trigger.
 *
 * It earns its place on one method: event() returns a host event class, and
 * naming the wrong one produces a trigger that fails silently rather than
 * loudly — the listener attaches to an event that never fires.
 */
class MakeTriggerCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-trigger';

    protected $description = 'Create a Nodeflow trigger class.';

    protected $type = 'Trigger';

    private ?string $resolvedType = null;

    private ?string $resolvedEvent = null;

    public function handle(): int
    {
        // Both resolved before parent::handle() writes anything, so a usage error
        // never leaves a half-generated file behind.
        try {
            $this->eventClass();
            $this->triggerType();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        // GeneratorCommand::handle() returns false when it refused to write.
        // Laravel casts the return with (int), turning false into 0 — a refusal
        // would look like success to any caller. Mapped explicitly, the same way
        // MakeNodeCommand does and for the same reason.
        if (parent::handle() === false) {
            return self::FAILURE;
        }

        $this->registerTrigger($this->qualifyClass($this->getNameInput()));

        return self::SUCCESS;
    }

    /**
     * Registration attaches the event listener, at the moment it happens — so a
     * generated trigger nobody registers is a trigger that never fires. That is
     * why this command registers rather than only scaffolding (E24).
     */
    private function registerTrigger(string $triggerClass): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::TRIGGER_ANCHOR,
            ltrim('\\'.ltrim($triggerClass, '\\').'::class', '\\'),
            '\\'.ltrim($triggerClass, '\\').'::class',
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                'Registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                'Already registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manualRegistration($triggerClass,
                'No app/Providers/NodeflowServiceProvider.php found. Run `php artisan nodeflow:install`.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manualRegistration($triggerClass,
                'app/Providers/NodeflowServiceProvider.php has no `'.NodeRegistrationWriter::TRIGGER_ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manualRegistration($triggerClass,
                'app/Providers/NodeflowServiceProvider.php has more than one `'.NodeRegistrationWriter::TRIGGER_ANCHOR.'` line.'
            ),
        };
    }

    private function manualRegistration(string $triggerClass, string $because): void
    {
        $this->components->warn($because.' Register the trigger yourself:');
        $this->newLine();
        $this->line('    app(TriggerRegistry::class)->register(');
        $this->line('        \\'.$triggerClass.'::class,');
        $this->line('    );');
        $this->newLine();
        $this->components->warn(
            'Until it is registered no listener is attached, so this trigger will never fire.'
        );
    }

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/trigger.stub');
    }

    /** Laravel's own stub-override convention, as MakeNodeCommand follows it. */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\Triggers';
    }

    protected function buildClass($name): string
    {
        // strtr, not str_replace: see F-1 in MakeNodeCommand::paletteGroup().
        return strtr(parent::buildClass($name), [
            '{{ type }}' => $this->triggerType(),
            '{{ event }}' => ltrim($this->eventClass(), '\\'),
            '{{ label }}' => Str::headline(class_basename($this->getNameInput())),
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function eventClass(): string
    {
        if ($this->resolvedEvent !== null) {
            return $this->resolvedEvent;
        }

        $event = trim((string) $this->option('event'));

        // Guarded on isInteractive() rather than on --no-interaction: a Testbench
        // PendingCommand does not necessarily pass that flag, and an unguarded
        // prompt hangs a test suite rather than failing it.
        if ($event === '' && $this->input->isInteractive()) {
            $event = trim(text(
                label: 'Which of your event classes does this trigger listen to?',
                placeholder: 'App\Events\OrderPlaced',
                hint: 'Registering the trigger attaches a listener to this class. Name the wrong one and nothing errors — the trigger is simply never fired.',
            ));
        }

        if ($event === '') {
            throw new \InvalidArgumentException(
                'No --event given. Unlike --type there is no safe default: a trigger whose '
                .'event() names the wrong class attaches its listener to an event that never '
                .'fires, and nothing reports it. Pass --event with your event class.'
            );
        }

        // A warning, not a refusal. Generating the trigger before writing the
        // event is a normal order of work, and ::class needs no loaded class.
        if (! class_exists($event) && ! interface_exists($event)) {
            $this->components->warn(
                "Event class [{$event}] could not be found. The trigger has still been "
                .'generated — ::class does not require the class to exist. If that name is '
                .'wrong, the listener attaches to an event that never fires and nothing will '
                .'tell you.'
            );
        }

        return $this->resolvedEvent = $event;
    }

    /**
     * Identical rules to MakeNodeCommand::nodeType(), deliberately: the same
     * pattern, the same reserved prefix, and the same visible warning when the
     * value is derived rather than given.
     *
     * @throws \InvalidArgumentException
     */
    private function triggerType(): string
    {
        if ($this->resolvedType !== null) {
            return $this->resolvedType;
        }

        $suggested = Str::snake(class_basename($this->getNameInput()));

        $type = trim((string) $this->option('type'));

        if ($type === '' && $this->input->isInteractive()) {
            $type = trim(text(
                label: 'Stable type identifier for this trigger',
                placeholder: 'shop.order_placed',
                default: $suggested,
                hint: "A flow's trigger_type stores this string. Prefix it with your domain.",
            ));
        } elseif ($type === '') {
            $type = $suggested;

            $this->components->warn(
                "No --type given; derived [{$type}] from the class name. Flows store this "
                .'string, so pass --type explicitly with your domain prefix.'
            );
        }

        if (preg_match('/^[a-z0-9]+(?:[._][a-z0-9]+)*$/', $type) !== 1) {
            throw new \InvalidArgumentException(
                "[{$type}] is not a valid trigger type. Use lowercase letters, digits, dots "
                .'and underscores, e.g. shop.order_placed.'
            );
        }

        if (str_starts_with($type, 'core.')) {
            throw new \InvalidArgumentException(
                "[{$type}] uses the reserved [core.] prefix, which belongs to the package "
                .'itself. Prefix your own types with your domain instead.'
            );
        }

        return $this->resolvedType = $type;
    }

    protected function getOptions(): array
    {
        return [
            ['event', null, InputOption::VALUE_OPTIONAL, 'The host event class this trigger listens to'],
            ['type', null, InputOption::VALUE_OPTIONAL, 'The stable type identifier, e.g. shop.order_placed'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the trigger if it already exists'],
        ];
    }
}
```

- [ ] **Step 5: Register the command**

Add `\Nodeflow\Console\MakeTriggerCommand::class,` to the `$this->commands([...])` array in `src/NodeflowServiceProvider.php`.

- [ ] **Step 6: Run the tests**

```bash
./vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php
```

Expected: all 9 pass.

- [ ] **Step 7: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **442 tests pass** (433 after Task 11, + 9). Record the measured assertion count.

- [ ] **Step 8: Execute the counterfactuals and restore**

(a) `sed -i '' 's/->description(/->descriptionText(/' stubs/trigger.stub` → `produces a trigger the registry accepts and whose methods execute` fails with a fatal, and nothing else in the suite notices; (b) delete the `registerTrigger()` call → `registers the trigger in the provider` fails; (c) derive an event class from the class name instead of throwing → `fails without --event` fails; (d) reject a missing event class → `warns but still generates` fails. Capture all four, restore.

- [ ] **Step 9: Commit**

```bash
git add src/Console/MakeTriggerCommand.php stubs/trigger.stub src/NodeflowServiceProvider.php tests/Feature/MakeTriggerCommandTest.php
git commit -m "feat: nodeflow:make-trigger

Scaffolds a Trigger with the four abstract methods and the two overrides
commented, and registers it in the provider's \$triggers array.

Registration extends 7.3 (E24) and is not optional in spirit:
TriggerRegistry::register() attaches the event listener at the moment it
happens, so a generated trigger nobody registers never fires. A generator
whose default output silently does nothing ships the documented failure.

--event warns rather than refuses when the class does not exist, because
generating a trigger before writing its event is a normal order of work
and ::class renders without a loaded class. Omitting it entirely is an
error: unlike --type there is no safe default, and a wrong event() attaches
a listener to something that never fires, with nothing to report it."
```

---

## Task 13: `nodeflow:make-subject-attribute`

**Files:**
- Create: `src/Console/MakeSubjectAttributeCommand.php`
- Modify: `src/NodeflowServiceProvider.php` (register the command)
- Test: `tests/Feature/MakeSubjectAttributeCommandTest.php`

**Interfaces:**
- Consumes: `NodeRegistrationWriter::ATTRIBUTE_ANCHOR` and `appendTo()` (Task 3).
- Produces: `nodeflow:make-subject-attribute {key} {--label=} {--type=boolean}`. Writes **no file**.

**Why it earns its place (§7.3).** Conditions are the non-technical author's main tool under D13, a `core.condition` can only reference attributes the host registered, and the attribute registry is the least discoverable part of the package — it has no docs page of its own.

**The entry is rendered fully qualified**, `\Nodeflow\Schema\SubjectAttribute::make(...)`, exactly as node entries are. That is what lets it be appended into a provider whose `use` block this command never touches.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/MakeSubjectAttributeCommandTest.php`:

```php
<?php

use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-attr-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app/Providers', 0777, true);

    file_put_contents($this->root.'/composer.json', json_encode([
        'autoload' => ['psr-4' => ['App\\' => 'app/']],
    ]));

    $this->providerPath = $this->root.'/app/Providers/NodeflowServiceProvider.php';

    file_put_contents($this->providerPath, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        /** @return \Nodeflow\Schema\SubjectAttribute[] */
        protected function subjectAttributes(): array
        {
            return [
            ];
        }
    }
    PHP);

    $this->app->setBasePath($this->root);
});

afterEach(function () {
    $delete = function (string $dir) use (&$delete) {
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $delete($path) : unlink($path);
        }
        rmdir($dir);
    };

    if (is_dir($this->root)) {
        $delete($this->root);
    }
});

it('appends a fully qualified attribute the provider can use without an import', function () {
    // Counterfactual: render the short name `SubjectAttribute::make(...)` and this
    // fails in every provider whose use block lacks the import — which this
    // command never edits, because a use-block insertion has no anchor it can
    // prove.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'clicked_offer',
        '--label' => 'Has clicked the offer',
        '--type' => 'boolean',
    ])->assertExitCode(0);

    expect(file_get_contents($this->providerPath))
        ->toContain("\Nodeflow\Schema\SubjectAttribute::make('clicked_offer', 'Has clicked the offer', 'boolean',");
});

it('appends an entry the provider parses and that produces a usable attribute', function () {
    // The require-and-execute half: the rendered line must actually construct a
    // SubjectAttribute the registry accepts, not merely look right.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'plan',
        '--label' => 'Plan',
        '--type' => 'text',
    ])->assertExitCode(0);

    exec('php -l '.escapeshellarg($this->providerPath).' 2>&1', $output, $status);
    expect($status)->toBe(0, implode(PHP_EOL, $output));

    // Evaluate the rendered expression rather than trusting the string. This is
    // what catches a rename of SubjectAttribute::make().
    $attribute = eval('return '.$this->renderedEntry().';');

    expect($attribute)->toBeInstanceOf(SubjectAttribute::class);
    expect($attribute->key)->toBe('plan');
    expect($attribute->label)->toBe('Plan');
    expect($attribute->type)->toBe('text');

    // The registry must accept it, and options() must show the label — that is
    // what a flow author actually sees in the condition sidebar.
    $registry = new SubjectAttributeRegistry;
    $registry->register($attribute);

    expect($registry->has('plan'))->toBeTrue();
    expect($registry->options())->toBe(['plan' => 'Plan']);
});

it('defaults the label from the key', function () {
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'confirmed_interest'])
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toContain("'Confirmed interest'");
});

it('recognises an existing key and appends nothing', function () {
    // Counterfactual: match on the whole rendered line and this fails — a re-run
    // with a different label appends a second entry under one key, and
    // SubjectAttributeRegistry keys by attribute key, so the second silently
    // replaces the first.
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan', '--label' => 'Plan'])
        ->assertExitCode(0);

    $before = file_get_contents($this->providerPath);

    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan', '--label' => 'Different'])
        ->expectsOutputToContain('Already')
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toBe($before);
});

it('rejects a type the registry cannot compare', function () {
    // Counterfactual: accept any --type and this fails. A core.condition coerces
    // comparisons by this string, so a fourth value produces an attribute whose
    // comparisons behave arbitrarily at runtime, in a published graph.
    $this->artisan('nodeflow:make-subject-attribute', [
        'key' => 'joined_at',
        '--type' => 'datetime',
    ])->assertExitCode(1);

    expect(file_get_contents($this->providerPath))->not->toContain('joined_at');
});

it('rejects a key a published graph could not resolve', function () {
    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'Clicked Offer'])
        ->assertExitCode(1);

    expect(file_get_contents($this->providerPath))->not->toContain('Clicked Offer');
});

it('prints the line and exits zero when there is no provider', function () {
    unlink($this->providerPath);

    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan'])
        ->expectsOutputToContain('SubjectAttributeRegistry')
        ->assertExitCode(0);
});

it('prints the line when the method anchor is missing', function () {
    file_put_contents($this->providerPath, <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
    }
    PHP);

    $before = file_get_contents($this->providerPath);

    $this->artisan('nodeflow:make-subject-attribute', ['key' => 'plan'])
        ->expectsOutputToContain('subjectAttributes')
        ->assertExitCode(0);

    expect(file_get_contents($this->providerPath))->toBe($before);
});
```

Add this helper to the same file, above the tests:

```php
/**
 * The rendered SubjectAttribute::make(...) expression, lifted back out of the
 * provider so it can be evaluated rather than merely string-matched.
 *
 * Greedy `.*` with the `s` modifier, not `.*?`: the rendered entry spans three
 * lines (the make() call, a // TODO, then the closure), and a non-greedy match
 * would stop at the first `)` — which is the closure's own parameter list — and
 * hand back an expression that does not parse.
 */
function renderedEntryFrom(string $providerPath): string
{
    preg_match(
        '/(\\\\Nodeflow\\\\Schema\\\\SubjectAttribute::make\(.*\))\s*,/s',
        file_get_contents($providerPath),
        $matches,
    );

    return $matches[1] ?? '';
}
```

and in the second test replace `$this->renderedEntry()` with `renderedEntryFrom($this->providerPath)`.

- [ ] **Step 2: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/MakeSubjectAttributeCommandTest.php
```

Expected: FAIL — command does not exist. Capture.

- [ ] **Step 3: Create the command**

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

/**
 * Appends one SubjectAttribute to the host provider's subjectAttributes().
 *
 * Thin on purpose, and it still earns its place: a core.condition can only
 * reference attributes the host registered, so conditions — the non-technical
 * author's main tool — are bounded entirely by this registry, and the registry
 * has no documentation page of its own. It is the least discoverable part of the
 * package.
 *
 * Writes no file. The entry is rendered fully qualified, exactly as node entries
 * are, so it can be appended into a provider whose use block this command never
 * touches — a use-block insertion has no anchor that could be proven.
 */
class MakeSubjectAttributeCommand extends Command
{
    protected $signature = 'nodeflow:make-subject-attribute
        {key : The attribute key a condition will reference, e.g. clicked_offer}
        {--label= : The label shown in the editor; derived from the key when omitted}
        {--type=boolean : boolean, text or number}';

    protected $description = 'Register a Nodeflow subject attribute in your provider.';

    /**
     * The three the registry's comparisons coerce. A fourth value produces an
     * attribute whose comparisons behave arbitrarily inside an already-published
     * graph, which is not a failure a host can see coming.
     */
    private const TYPES = ['boolean', 'text', 'number'];

    /** As tight as an output name: this key is stored inside published graphs. */
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    public function handle(): int
    {
        $key = trim((string) $this->argument('key'));
        $type = strtolower(trim((string) $this->option('type')));
        $label = trim((string) $this->option('label')) ?: Str::ucfirst(str_replace('_', ' ', $key));

        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            $this->components->error(
                "[{$key}] is not a valid attribute key. Use lowercase letters, digits and "
                .'underscores, e.g. clicked_offer. A published graph stores this key and '
                .'resolves through it, so it stays conservative.'
            );

            return self::FAILURE;
        }

        if (! in_array($type, self::TYPES, true)) {
            $this->components->error(
                "[{$type}] is not a supported attribute type. Use ".implode(', ', self::TYPES)
                .'. The type drives how a condition coerces its comparison, so a value '
                .'outside this set produces comparisons that behave arbitrarily at runtime.'
            );

            return self::FAILURE;
        }

        $entry = sprintf(
            "\\Nodeflow\\Schema\\SubjectAttribute::make('%s', '%s', '%s',\n"
            ."                // TODO: return this attribute's value for one subject.\n"
            .'                fn ($subject) => null)',
            $key,
            addcslashes($label, '\\\''),
            $type,
        );

        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->appendTo(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            NodeRegistrationWriter::ATTRIBUTE_ANCHOR,
            "SubjectAttribute::make('{$key}'",
            $entry,
            '            ',
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                "Registered [{$key}] in app/Providers/NodeflowServiceProvider.php. "
                .'Fill in the resolver closure before a condition uses it.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                "Already registered: [{$key}] is in app/Providers/NodeflowServiceProvider.php."
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manual($entry,
                'No app/Providers/NodeflowServiceProvider.php found. Run `php artisan nodeflow:install`.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manual($entry,
                'app/Providers/NodeflowServiceProvider.php has no `'
                .NodeRegistrationWriter::ATTRIBUTE_ANCHOR.'` method with a bare `return [` body.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manual($entry,
                'app/Providers/NodeflowServiceProvider.php has more than one `'
                .NodeRegistrationWriter::ATTRIBUTE_ANCHOR.'` line.'
            ),
        };

        // Generating nothing is still success: the command's contract is "register
        // it if I can prove where, otherwise tell you exactly what to paste". Only
        // a usage error is a failure.
        return self::SUCCESS;
    }

    private function manual(string $entry, string $because): void
    {
        $this->components->warn($because.' Register the attribute yourself:');
        $this->newLine();
        $this->line('    app(SubjectAttributeRegistry::class)->register(');
        $this->line('        '.$entry.',');
        $this->line('    );');
        $this->newLine();
    }
}
```

**No `getOptions()` here, unlike `MakeTriggerCommand`.** This command declares its options in `$signature`, and a `Command` using `$signature` must not also override `getOptions()` — the two definitions would conflict. Drop the `use Symfony\Component\Console\Input\InputOption;` import too; nothing in this file needs it.

- [ ] **Step 4: Register the command**

Add `\Nodeflow\Console\MakeSubjectAttributeCommand::class,` to the `$this->commands([...])` array in `src/NodeflowServiceProvider.php`.

- [ ] **Step 5: Run the tests**

```bash
./vendor/bin/pest tests/Feature/MakeSubjectAttributeCommandTest.php
```

Expected: all 8 pass.

- [ ] **Step 6: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **450 tests pass** (442 after Task 12, + 8). Record the measured assertion count.

- [ ] **Step 7: Execute the counterfactuals and restore**

(a) render the short `SubjectAttribute::make(` instead of the fully qualified name → `appends a fully qualified attribute` fails; (b) pass the whole `$entry` as the presence needle → `recognises an existing key` fails with two entries under one key; (c) drop the `in_array($type, self::TYPES)` check → `rejects a type the registry cannot compare` fails; (d) drop the `KEY_PATTERN` check → `rejects a key a published graph could not resolve` fails. Capture, restore.

- [ ] **Step 8: Commit**

```bash
git add src/Console/MakeSubjectAttributeCommand.php src/NodeflowServiceProvider.php tests/Feature/MakeSubjectAttributeCommandTest.php
git commit -m "feat: nodeflow:make-subject-attribute

Appends one SubjectAttribute::make() into the provider's
subjectAttributes() method. Writes no file. Thin, and it still earns its
place: a core.condition can only reference attributes the host
registered, so conditions are bounded entirely by this registry, and the
registry has no documentation page of its own.

The entry is rendered fully qualified, as node entries are, so it can be
appended into a provider whose use block this command never edits — a
use-block insertion has no anchor it could prove.

Presence is matched on the key alone. SubjectAttributeRegistry keys by
attribute key, so a re-run with a different label must not append a second
entry that silently replaces the first.

--type is restricted to boolean, text and number: the type drives how a
condition coerces its comparison, so a fourth value produces comparisons
that behave arbitrarily inside an already-published graph."
```

---

## Task 14: G-2 — `tenant_id` immutability, and the honest limit of the guard

**Files:**
- Modify: `src/Models/Concerns/BelongsToTenant.php` (the `updating` guard's comment, ~line 52–72)
- Test: `tests/Feature/TenantIdImmutabilityTest.php` (create)

**Interfaces:**
- Consumes: nothing. Produces: nothing. Pure hardening plus one pinned limitation.

**What G-2 actually is.** Two halves. `tenant_id` is immutable on update for `Flow`, `FlowVersion`, `Run` and `Template`, and **`CrossTenantWriteException` appears in no user-facing document** — verified by grep across `docs/`. That half is Task 15's documentation. This task is the other half: the guard is an `updating` model hook, so `Flow::withoutTenancy()->where(...)->update([...])` fires no model events and bypasses it entirely. That is inherent to the approach, and the codebase already uses query-builder updates for status writes in `CompleteRunActivity` — so it is a pattern a future author may copy without realising what it skips.

**The comment gets a test.** Per the spec's §11 rule, a comment asserting a fact about system behaviour is tested for truthfulness. This one asserts the bypass exists, so the test asserts the bypass exists — and fails the day someone changes the guard's mechanism without updating the comment or the docs.

- [ ] **Step 1: Write the tests**

Create `tests/Feature/TenantIdImmutabilityTest.php`:

```php
<?php

use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;

it('refuses a tenant_id change through the model', function () {
    // The guard doing its job. Counterfactual: delete the static::updating hook in
    // BelongsToTenant and this fails — an update($request->all()) carrying a
    // tenant_id would reassign the row, taking with it every child reachable
    // through it, including RunSubject and NodeExecution, which carry no
    // tenant_id of their own.
    $flow = Flow::create(['name' => 'Welcome', 'trigger_type' => 'app.x', 'tenant_id' => 'acme']);

    expect(fn () => $flow->update(['tenant_id' => 'globex']))
        ->toThrow(CrossTenantWriteException::class);

    expect(Flow::withoutTenancy()->find($flow->id)->tenant_id)->toBe('acme');
});

it('allows an update that re-sends the row\'s existing tenant_id', function () {
    // isDirty() means a same-value write is not a change. Counterfactual: compare
    // to the ambient tenant instead of to isDirty() and this fails — a plain
    // save() echoing the row's own tenant_id would start throwing.
    $flow = Flow::create(['name' => 'Welcome', 'trigger_type' => 'app.x', 'tenant_id' => 'acme']);

    $flow->update(['tenant_id' => 'acme', 'name' => 'Renamed']);

    expect($flow->fresh()->name)->toBe('Renamed');
});

it('does NOT catch a tenant_id change made through the query builder', function () {
    // This test pins a documented limitation rather than a guarantee, which is
    // deliberate: the guard is an `updating` model hook, so a query-builder update
    // fires no model events and bypasses it entirely.
    //
    // Counterfactual, and the reason this test exists: change the guard's
    // mechanism so it *does* catch this — and this test fails, forcing whoever did
    // it to update BelongsToTenant's comment and docs/02-integration.md in the
    // same commit. Without it, the comment and the docs could quietly become
    // false while the suite stayed green.
    $flow = Flow::create(['name' => 'Welcome', 'trigger_type' => 'app.x', 'tenant_id' => 'acme']);

    Flow::withoutTenancy()->where('id', $flow->id)->update(['tenant_id' => 'globex']);

    expect(Flow::withoutTenancy()->find($flow->id)->tenant_id)->toBe('globex');
});
```

If `Flow::create()` needs more required columns in this suite, copy the attribute set from an existing test — `tests/Feature/TenancyTest.php` has working examples. Do not invent columns.

- [ ] **Step 2: Run and verify the first two pass and the third passes**

```bash
./vendor/bin/pest tests/Feature/TenantIdImmutabilityTest.php
```

Expected: all 3 PASS immediately — this task adds coverage for existing behaviour and pins an existing limitation. Step 4 is what makes them load-bearing.

- [ ] **Step 3: Extend the guard's comment**

In `src/Models/Concerns/BelongsToTenant.php`, append to the comment block above `static::updating(...)`:

```php
        // KNOWN LIMIT, and the reason it is stated here rather than only in the
        // docs: this is an `updating` model hook, so it sees only writes that go
        // through a model instance. Flow::withoutTenancy()->where(...)->update([...])
        // fires no model events and bypasses this guard completely. That is
        // inherent to the approach and is not a bug to be fixed here — but it is a
        // trap, because this codebase already uses query-builder updates for
        // status writes (CompleteRunActivity), so it is a pattern a reader may
        // copy without noticing what it skips.
        //
        // tests/Feature/TenantIdImmutabilityTest.php pins that bypass with a test
        // that asserts the query-builder write SUCCEEDS. If you change the
        // mechanism so it is caught, that test fails on purpose: update this
        // comment and docs/02-integration.md in the same commit.
```

- [ ] **Step 4: Execute the counterfactuals and restore**

Three, and the third is the point of the task:

```bash
# (a) The guard is load-bearing.
# Comment out the whole static::updating(...) block, then:
./vendor/bin/pest tests/Feature/TenantIdImmutabilityTest.php --filter="refuses a tenant_id change"
# Expected: FAIL. Restore.

# (b) isDirty() is load-bearing.
# Change `! $model->isDirty('tenant_id')` to a comparison against the ambient
# tenant, then:
./vendor/bin/pest tests/Feature/TenantIdImmutabilityTest.php --filter="re-sends"
# Expected: FAIL. Restore.

# (c) The limitation test is load-bearing.
# Add a `static::updated` or query-builder-level guard that catches the
# query-builder write, then:
./vendor/bin/pest tests/Feature/TenantIdImmutabilityTest.php --filter="does NOT catch"
# Expected: FAIL — which is the test telling you the comment you just made false
# needs updating. Restore.
```

Capture all three.

- [ ] **Step 5: Run the full package suite**

```bash
./vendor/bin/pest
```

Expected: **453 tests pass** (450 after Task 13, + 3). Record the measured assertion count.

- [ ] **Step 6: Commit**

```bash
git add src/Models/Concerns/BelongsToTenant.php tests/Feature/TenantIdImmutabilityTest.php
git commit -m "test: pin tenant_id immutability, and the guard's query-builder bypass

G-2, code half. The guard is an updating model hook, so a query-builder
update fires no model events and bypasses it entirely. That is inherent,
not a bug — but it is a trap, because this codebase already uses
query-builder updates for status writes in CompleteRunActivity.

Stated in the guard's own comment, and pinned by a test that asserts the
bypass SUCCEEDS. Change the mechanism so it is caught and that test fails
on purpose, which is what forces the comment and the docs to be updated in
the same commit. Without it they could quietly become false on a green
suite.

The documentation half is the next task: CrossTenantWriteException appears
in no user-facing doc today."
```

---

## Task 15: Documentation, and closing the issues

**Files:**
- Modify: `docs/02-integration.md`, `docs/04-writing-triggers.md`, `docs/08-editor-client.md`, `docs/superpowers/open-issues.md`

**Interfaces:** none. Documentation only — but `install`'s whole job is to make `docs/02-integration.md` true, so this is not optional polish.

- [ ] **Step 1: Rewrite `docs/02-integration.md` Step 1**

Replace the `composer require` / `vendor:publish --tag=nodeflow-migrations` / `migrate` block with:

```markdown
## Step 1 — Install

```bash
composer require atram/laravel-nodeflow
php artisan nodeflow:install
php artisan migrate
```

`nodeflow:install` publishes `config/nodeflow.php`, creates
`app/Providers/NodeflowServiceProvider.php` with the three registration homes the
generators write into, lists that provider in `bootstrap/providers.php`, adds the
Tailwind `@source` line, and then **verifies** the four client settings it cannot
safely write — printing the exact snippet for each one and **exiting non-zero** if
anything is missing. Run it again any time; it reports "already wired" and changes
nothing. `nodeflow:install --check` verifies without writing, which is the form to
put in CI.

**It does not publish the migrations, and you should not either unless you mean
to own them.** The package loads its own migrations, so `php artisan migrate`
already finds them. If you publish a copy, that copy **permanently shadows the
package's own file for every `migrate` run** — Laravel resolves migrations by
name and the application's own directory is searched last, so your copy wins and
nothing warns you. A package upgrade then changes the file you are not using.
Publish with `nodeflow:install --publish-migrations` only if you intend to
maintain the schema yourself; `install` will hash any copy you have against ours
on every run and exit non-zero when they diverge.
```

Keep the requirements paragraph and the six-tables paragraph that follow, unchanged.

- [ ] **Step 2: Rewrite `docs/02-integration.md` Step 3**

Replace "In a service provider's `boot()`:" and its code block with:

```markdown
## Step 3 — Register your domain surface

`nodeflow:install` created `app/Providers/NodeflowServiceProvider.php` with three
registration homes. The generators append into them, and you can edit them by
hand:

```php
class NodeflowServiceProvider extends ServiceProvider
{
    /** Nodes — the things that do work. `nodeflow:make-node` appends here. */
    protected array $nodes = [
        \App\Nodeflow\Nodes\SendMessage::class,
    ];

    /** Triggers — which of your events start journeys. `nodeflow:make-trigger` appends here. */
    protected array $triggers = [
        \App\Nodeflow\Triggers\OrderPlaced::class,
    ];

    public function boot(): void
    {
        Nodeflow::register($this->nodes);
        app(TriggerRegistry::class)->register(...$this->triggers);
        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());
    }

    /**
     * What a non-technical author may build conditions on.
     * `nodeflow:make-subject-attribute` appends here.
     *
     * @return \Nodeflow\Schema\SubjectAttribute[]
     */
    protected function subjectAttributes(): array
    {
        return [
            \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Has clicked', 'boolean',
                fn ($subject) => $subject->clicked_at !== null),
        ];
    }
}
```

**Keep those three declarations exactly as written.** The generators match the
property and method lines literally: they append only when they can prove where
the entry belongs, and otherwise print a line for you to paste rather than guess.

Registering in any other provider's `boot()` still works at runtime — nothing in
the package cares which provider called `Nodeflow::register()` — but the
generators cannot find it there, so you would be pasting every entry by hand.
```

Keep the three "Three things worth knowing" paragraphs that follow, unchanged, and add a fourth — spec §9 puts `make-subject-attribute`'s documentation here, because the attribute registry has no page of its own and that is §7.3's stated reason the command exists:

```markdown
**Subject attributes have a generator.** `php artisan nodeflow:make-subject-attribute
clicked_offer --label='Has clicked the offer' --type=boolean` appends one entry to
`subjectAttributes()` with a `// TODO` on the resolver closure. `--type` accepts
`boolean`, `text` or `number` and nothing else: the type is what a `core.condition`
uses to coerce its comparison, so a value outside that set produces comparisons that
behave arbitrarily inside an already-published graph.
```

- [ ] **Step 3: Add the `tenant_id` immutability subsection (G-2, docs half)**

Insert after "Tenant isolation and your gate" in `docs/02-integration.md`:

```markdown
### `tenant_id` is fixed at creation

A row's tenant never changes. `Flow`, `FlowVersion`, `Run` and `Template` all
refuse an update that changes `tenant_id`, throwing
`Nodeflow\Models\CrossTenantWriteException`. Two ways to meet it by accident:

```php
$flow->update($request->all());        // if the request carries a tenant_id
$template->update(['tenant_id' => $org->id]);   // promoting a global template into a tenant's
```

Re-sending the row's *existing* `tenant_id` is fine — an unchanged value is not a
change. The exception exists because `$guarded` is empty on these models, so
without it a request field would silently reassign a row and take every child row
reachable through it: `RunSubject` and `NodeExecution` carry no `tenant_id` of
their own and rely entirely on their parent being scoped.

**The guard sees model writes only.** It is an `updating` model event, so
`Flow::withoutTenancy()->where(...)->update(['tenant_id' => ...])` bypasses it
completely — no model events fire for a query-builder update. If you write
`tenant_id` through the query builder, nothing will stop you. Don't.
```

- [ ] **Step 4: Rewrite "Verifying the install"**

Replace the paragraph beginning "There is no `nodeflow:install` command" with:

```markdown
`nodeflow:install --check` verifies a host without writing anything, and exits
non-zero if any requirement is unwired. That is the form for CI, because three of
the client-side requirements fail quietly: a missing tsconfig path lets Vite build
while your `tsc` fails, a missing Tailwind `@source` line lets the build succeed
with every package utility absent, and a missing `resolve.dedupe` mounts two React
copies and reports "Invalid hook call" as though React were at fault.

It also reports two things it will never fail on, because both are legitimate
states right after installing: which of the four authorization gates you have not
defined yet, and what `nodeflow.tenancy` currently resolves to — including, under
`auto`, which `TenantResolver` is bound, since that is what decides what a null
tenant means.
```

Keep the `nodeflow:check-node-types` block above it and the `nodeflow:make-node`
block below it. Update the `make-node` paragraph's "otherwise prints the
`Nodeflow::register([...])` line" sentence to mention that `nodeflow:install`
creates the provider.

- [ ] **Step 5: Document `make-trigger` in `docs/04-writing-triggers.md`**

Add near the top, after whatever introduces the `Trigger` contract:

```markdown
## Scaffold one

```bash
php artisan nodeflow:make-trigger FloodAlertFires \
    --event='App\Events\FloodAlertDispatched' \
    --type=rada.flood_alert
```

That writes `app/Nodeflow/Triggers/FloodAlertFires.php` with the four required
methods and `idempotencyKey()` / `matchesConfig()` commented out, and appends the
class to your provider's `$triggers` array.

`--event` is the part worth care. Registering a trigger calls `Event::listen()`
for that class, at the moment of registration — so naming the wrong class raises
no error at all: the listener attaches to an event that never fires and the
trigger is simply silent. The command warns when the class cannot be found, but it
still generates the file, because writing the trigger before the event is a normal
order of work.
```

- [ ] **Step 6: Add the install report to `docs/08-editor-client.md`**

Add at the end of "Wire the host application", before "Add the thin Inertia page":

```markdown
### Check all five

```bash
php artisan nodeflow:install --check
```

Reports each requirement as wired or not, prints the exact snippet for anything
missing, and exits non-zero if any of them is. `install` **writes** the Tailwind
`@source` line — computing the relative path from wherever your CSS entry actually
lives — and **verifies but does not write** the other three plus the provider
wiring:

| Requirement | `install` |
|---|---|
| 1. Vite alias | verifies |
| 2. tsconfig paths | verifies — structurally, so both `resources/js` and `resources/js/index.ts` are accepted |
| 3. Tailwind `@source` | **writes** |
| 4. `@xyflow/react` | verifies, and tells you to `npm install` it |
| 5. Vite `resolve.dedupe` | verifies |

The three it does not write are files it cannot edit and then prove it edited
correctly: `vite.config.ts` needs a TypeScript parser, `tsconfig.json` is usually
JSONC whose comments a JSON round-trip would delete, and writing a dependency into
`package.json` without running the installer would leave your lockfile disagreeing
with your manifest. Verification is comment-stripped, so a setting you commented
out while debugging reports as missing rather than as present.
```

- [ ] **Step 7: Update `docs/superpowers/open-issues.md`**

- **F-1** — mark ✅ RESOLVED, naming the `strtr` fix in both renderers and the corrected `paletteGroup()` docblock.
- **F-2** — mark ✅ RESOLVED, recording the measurement: with `->help(` renamed in `node.both.stub`, the `SendSms` and `SendBlast` execute tests both still pass and only the new `SendDigest` one fails.
- **G-2** — mark ✅ RESOLVED, naming the new docs subsection and the pinning test.
- **G-4** — mark ✅ RESOLVED by `ViteDedupeStep`, noting the check is bounded to the `dedupe` array's own text because every React app's Vite config mentions `react` somewhere.
- **R-2** — verify first: `git log -p --follow docs/02-integration.md` around the Plan 3a commits, and read lines 302–324 as they stand. If the text is mode-precise (it appears to be), mark ✅ RESOLVED with "corrected by Plan 3a's docs pass and never struck through here; verified during Plan 5 against the commit that introduced it (record the hash you find)". If it is not, correct the sentence and then close it.
- **G-3** — rewrite the entry to record E26: cut and reassigned to the security-hardening plan with D-1 and D-2, with the two measurements that killed the alternatives (`pragma foreign_keys = 0` and the bogus-FK insert succeeding; 4 + 27 call sites and `preventSilentlyDiscardingAttributes` being off by default).
- **New entry** — the demo's tenant switcher. Task 16 fixes the route binding, not the switcher: any authenticated demo user can still `POST /nodeflow/tenant` and act as another organisation, which is deliberate demo behaviour. Record it as an accepted demo limitation so it is not mistaken for a closed hole.
- Update the header's "Last updated" line and counts once Task 17 has measured them.

- [ ] **Step 8: Commit**

```bash
git add docs/
git commit -m "docs: make 02-integration true, and close F-1, F-2, G-2, G-4

install's job is to make docs/02-integration.md true, so Step 1 now runs
it, Step 3 teaches the provider it creates rather than 'any provider's
boot()' — which is what left a host who followed the docs unable to use
make-node — and 'Verifying the install' documents the command instead of
saying there isn't one.

Step 1 also states the shadowing consequence of publishing migrations,
which was the mechanism behind Plan 4's silent divergence.

Adds the tenant_id immutability subsection: CrossTenantWriteException
appeared in no user-facing document until now. Documents make-trigger in
04-writing-triggers.md, and what install --check reports per client
requirement in 08-editor-client.md.

open-issues: F-1, F-2, G-2 and G-4 closed with their evidence; G-3
rewritten to record E26 and the two measurements that killed its
alternatives; R-2 verified; the demo's tenant switcher recorded as an
accepted limitation rather than left to look closed."
```

---

## Task 16: The demo's cross-tenant write (E27)

**Files (all in `~/Sites/test-workflow`):**
- Modify: `routes/web.php:15-22`, `app/Http/Controllers/NodeflowDemoController.php:153-171`, `app/Nodeflow/SessionTenantResolver.php` (docblock), `resources/js/pages/nodeflow/demo.tsx:277,280`
- Test: `tests/Feature/NodeflowDemoSecurityTest.php` (create)

**Interfaces:** host application only. Nothing in the package changes.

**The bug.** `convert()` and `click()` route-bind `RunSubject` directly. `RunSubject` carries no `tenant_id` and no tenant scope **by design** (foundation spec E1) because it is meant to be reached only through an already-scoped `Run` — and `docs/02-integration.md:355` says exactly that. Implicit model binding resolves it by primary key alone, and the run is then re-fetched with `Run::withoutTenancy()`. Measured additionally: `php artisan route:list -v` shows both routes carry **`web` only, no `auth`**, and `switchTenant` puts any posted string into the session unvalidated.

**What this task fixes and what it does not.** It fixes the route binding, the unscoped `User` write, the missing `auth`, and the unvalidated tenant switch. It does **not** make the demo's tenant switcher authorization-aware: an authenticated demo user can still switch to another organisation deliberately, which is the switcher's purpose. Task 15 records that as an accepted demo limitation. Do not describe this task as closing it.

- [ ] **Step 1: Prepare the demo and confirm the baseline**

```bash
cd ~/Sites/test-workflow
readlink -f vendor/atram/laravel-nodeflow    # MUST be your package worktree
./vendor/bin/pest 2>&1 | tail -3             # 49 tests, 191 assertions
```

If the symlink points at `main` rather than your worktree, re-point it before going further — otherwise every gate below tests the wrong package.

- [ ] **Step 2: Write the failing tests**

Create `tests/Feature/NodeflowDemoSecurityTest.php`:

```php
<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\NodeflowDemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

/**
 * QUEUE_CONNECTION=sync cannot start a run — the durable engine throws
 * UnsupportedBackendCapabilitiesException because sync cannot provide the
 * worker/lease boundary. Switching to 'database' and draining one worker is what
 * NodeflowRunViewTest does, for the same reason.
 */
function startDemoRun(Flow $flow): Run
{
    config(['queue.default' => 'database']);

    $subjectIds = User::where('organization_id', $flow->tenant_id)
        ->pluck('id')->map(fn ($id) => (string) $id)->all();

    $run = app(StartRun::class)->forFlow($flow, 'user', $subjectIds, []);

    Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

    return $run->refresh();
}

beforeEach(function () {
    $this->seed(NodeflowDemoSeeder::class);

    // Two organisations, each with its own flow and its own run. The seeder
    // creates Acme Bank and Globex Credit.
    $organizations = Organization::orderBy('id')->get();

    $this->acme = $organizations->first();
    $this->globex = $organizations->last();

    expect($this->acme->id)->not->toBe($this->globex->id);

    $this->acmeFlow = Flow::withoutTenancy()->where('tenant_id', (string) $this->acme->id)->firstOrFail();
    $this->globexFlow = Flow::withoutTenancy()->where('tenant_id', (string) $this->globex->id)->firstOrFail();

    $this->acmeRun = startDemoRun($this->acmeFlow);
    $this->globexRun = startDemoRun($this->globexFlow);

    $this->acmeUser = User::where('organization_id', $this->acme->id)->firstOrFail();
});

it('refuses to reach another organisation\'s run', function () {
    // The bug, stated as a test. Counterfactual: restore
    // `convert(Request $request, RunSubject $subject)` with implicit binding and
    // this fails with a 302 — the write goes through, because implicit model
    // binding resolves RunSubject by primary key and RunSubject has no tenant
    // scope of its own by design.
    $victim = $this->globexRun->subjects()->firstOrFail();

    $before = User::find($victim->subject_id);

    expect($before->confirmed_interest_at)->toBeNull();

    $this->actingAs($this->acmeUser)
        ->withSession(['demo_tenant_id' => (string) $this->acme->id])
        ->post("/nodeflow/runs/{$this->globexRun->id}/subjects/{$victim->id}/convert")
        ->assertNotFound();

    // Nothing was written, and the subject was not force-exited.
    expect(User::find($victim->subject_id)->confirmed_interest_at)->toBeNull();
    expect($victim->fresh()->status)->toBe('active');
});

it('refuses a subject id that belongs to another run', function () {
    // The narrower form: the run is yours, the subject is not. Counterfactual:
    // look the subject up with RunSubject::findOrFail() instead of through
    // $run->subjects() and this fails.
    $victim = $this->globexRun->subjects()->firstOrFail();

    $this->actingAs($this->acmeUser)
        ->withSession(['demo_tenant_id' => (string) $this->acme->id])
        ->post("/nodeflow/runs/{$this->acmeRun->id}/subjects/{$victim->id}/convert")
        ->assertNotFound();

    expect(User::find($victim->subject_id)->confirmed_interest_at)->toBeNull();
});

it('still converts a subject of your own run', function () {
    // The demo has to keep working. This is the test that stops the fix from
    // being "deny everything".
    $subject = $this->acmeRun->subjects()->where('status', 'active')->firstOrFail();

    $this->actingAs($this->acmeUser)
        ->withSession(['demo_tenant_id' => (string) $this->acme->id])
        ->post("/nodeflow/runs/{$this->acmeRun->id}/subjects/{$subject->id}/convert")
        ->assertRedirect();

    expect(User::find($subject->subject_id)->confirmed_interest_at)->not->toBeNull();
});

it('still marks a click on a subject of your own run', function () {
    $subject = $this->acmeRun->subjects()->where('status', 'active')->firstOrFail();

    $this->actingAs($this->acmeUser)
        ->withSession(['demo_tenant_id' => (string) $this->acme->id])
        ->post("/nodeflow/runs/{$this->acmeRun->id}/subjects/{$subject->id}/click")
        ->assertRedirect();

    expect(User::find($subject->subject_id)->clicked_offer_at)->not->toBeNull();
});

it('requires authentication for every demo mutation', function () {
    // Measured before the fix: `route:list -v` showed `web` only on this group, so
    // every mutation below — including reseed, which runs db:seed — was reachable
    // by an anonymous visitor. Counterfactual: remove the auth middleware and this
    // fails on the first assertion.
    $subject = $this->acmeRun->subjects()->firstOrFail();

    $this->post("/nodeflow/runs/{$this->acmeRun->id}/subjects/{$subject->id}/convert")
        ->assertRedirect('/login');

    $this->post("/nodeflow/runs/{$this->acmeRun->id}/subjects/{$subject->id}/click")
        ->assertRedirect('/login');

    $this->post('/nodeflow/tenant', ['tenant_id' => (string) $this->acme->id])
        ->assertRedirect('/login');

    $this->post('/nodeflow/reseed')->assertRedirect('/login');

    $this->get('/nodeflow')->assertRedirect('/login');
});

it('refuses a tenant id that is not a real organisation', function () {
    // Counterfactual: put the raw input into the session and this fails — every
    // subsequent scoped read in the request uses an attacker-chosen tenant string.
    $this->actingAs($this->acmeUser)
        ->post('/nodeflow/tenant', ['tenant_id' => 'not-an-organisation'])
        ->assertSessionHasErrors('tenant_id');

    expect(session('demo_tenant_id'))->not->toBe('not-an-organisation');
});

it('still switches to a real organisation', function () {
    $this->actingAs($this->acmeUser)
        ->post('/nodeflow/tenant', ['tenant_id' => (string) $this->globex->id])
        ->assertRedirect();

    expect(session('demo_tenant_id'))->toBe((string) $this->globex->id);
});
```

- [ ] **Step 3: Run and verify they fail**

```bash
./vendor/bin/pest tests/Feature/NodeflowDemoSecurityTest.php
```

Expected: the first two FAIL because the write succeeds (the bug), the `auth` one FAILS with 302-to-nowhere or a 404, and the tenant-validation one FAILS. **Capture this output — it is the proof the bug was real**, and it is the only record of it that will exist after the fix.

If the seeder does not produce one flow per organisation, adapt the `beforeEach` to whatever it does produce rather than changing the seeder.

- [ ] **Step 4: Reshape the routes**

In `routes/web.php`, replace the demo group:

```php
// Every mutation here writes to a tenant's data, so the group requires a session.
// Before this it carried `web` alone, which left convert, click, the tenant
// switcher and reseed — which runs db:seed — reachable by an anonymous visitor.
Route::middleware(['auth'])->prefix('nodeflow')->name('nodeflow.')->group(function () {
    Route::get('/', [NodeflowDemoController::class, 'index'])->name('demo');
    Route::post('tenant', [NodeflowDemoController::class, 'switchTenant'])->name('tenant');
    Route::post('alert', [NodeflowDemoController::class, 'fireAlert'])->name('alert');
    Route::post('flows/{flow}/run', [NodeflowDemoController::class, 'runFlow'])->name('run');

    // The run is in the URL deliberately. RunSubject carries no tenant_id and no
    // tenant scope by design, so binding it directly hands out any tenant's row
    // by primary key — see docs/02-integration.md, "Never route-bind RunSubject".
    // {run} binds through the tenant-scoped Run, so another tenant's id is a 404.
    Route::post('runs/{run}/subjects/{subject}/convert', [NodeflowDemoController::class, 'convert'])->name('convert');
    Route::post('runs/{run}/subjects/{subject}/click', [NodeflowDemoController::class, 'click'])->name('click');

    Route::post('reseed', [NodeflowDemoController::class, 'reseed'])->name('reseed');
});
```

- [ ] **Step 5: Fix the controller**

Replace `convert()` and `click()` in `app/Http/Controllers/NodeflowDemoController.php`:

```php
    /** The interesting button: convert a customer mid-journey. */
    public function convert(Request $request, Run $run, int $subject)
    {
        $runSubject = $this->subjectOf($run, $subject);

        $this->userOf($run, $runSubject)->update(['confirmed_interest_at' => now()]);

        app(SubjectExiter::class)->exit($run, [(string) $runSubject->subject_id]);

        return back();
    }

    /** Mark a customer as having clicked, so the condition routes them to "yes". */
    public function click(Request $request, Run $run, int $subject)
    {
        $runSubject = $this->subjectOf($run, $subject);

        $this->userOf($run, $runSubject)->update(['clicked_offer_at' => now()]);

        return back();
    }

    /**
     * The only way this controller reaches a RunSubject.
     *
     * RunSubject carries no tenant_id and no tenant scope of its own — that is
     * deliberate in the package, because it is meant to be reached only through an
     * already-scoped Run. So `function convert(RunSubject $subject)` — which is
     * what this controller used to do — resolves any tenant's row by primary key
     * with nothing checking tenancy. {run} binds through the tenant-scoped Run
     * instead, and the subject is found within it.
     */
    private function subjectOf(Run $run, int $subjectId): RunSubject
    {
        return $run->subjects()->whereKey($subjectId)->firstOrFail();
    }

    /**
     * Scoped to the run's tenant as defence in depth, not because $runSubject is
     * untrusted — it came from a scoped run, so it is trustworthy. The extra
     * where() costs one comparison and is the only check still standing if this
     * app ever sets nodeflow.tenancy to disabled.
     */
    private function userOf(Run $run, RunSubject $runSubject): User
    {
        return User::where('organization_id', $run->tenant_id)
            ->whereKey($runSubject->subject_id)
            ->firstOrFail();
    }
```

Delete the now-unused `use Nodeflow\Models\Run;`? No — keep it; `Run` is now type-hinted in both signatures. `RunSubject` stays imported for the two return types. Remove nothing that is still referenced.

- [ ] **Step 6: Validate the tenant switch**

```php
    public function switchTenant(Request $request)
    {
        // Validated because this value becomes the ambient tenant for every scoped
        // read in every later request. Unvalidated, any string the client sent was
        // the tenant.
        //
        // NOTE what this does and does not do: it proves the organisation exists,
        // not that this user belongs to it. Letting you act as any organisation is
        // the switcher's whole purpose in a demo — it is not a check that was
        // forgotten. A real application reads the tenant from the authenticated
        // user and has no switcher at all.
        $validated = $request->validate([
            'tenant_id' => ['required', 'exists:organizations,id'],
        ]);

        $request->session()->put('demo_tenant_id', (string) $validated['tenant_id']);

        return back();
    }
```

- [ ] **Step 7: Update the resolver's docblock**

In `app/Nodeflow/SessionTenantResolver.php`, replace "set by the org switcher on the demo page, so you can flip tenants without logging in" with:

```php
/**
 * Demo tenancy: the "current" organisation comes from the session, set by the org
 * switcher on the demo page. The demo routes require authentication, so there is
 * always a logged-in user; the switcher then lets that user look at any
 * organisation's data, which is the point of a demo and would be a hole in a real
 * application.
 *
 * A real application would read this from the authenticated user and ship no
 * switcher. The contract is the same either way — nodeflow never learns what an
 * Organization is.
 */
```

- [ ] **Step 8: Update the two client URLs**

In `resources/js/pages/nodeflow/demo.tsx`, at lines 277 and 280, change the two template strings so they carry the run. `selected` is the run in scope:

```tsx
<button onClick={() => post(`/nodeflow/runs/${selected.id}/subjects/${s.id}/click`)} className="...">
```

```tsx
<button onClick={() => post(`/nodeflow/runs/${selected.id}/subjects/${s.id}/convert`)} className="...">
```

Leave the `className` values exactly as they are.

- [ ] **Step 9: Run the demo gates**

```bash
cd ~/Sites/test-workflow
./vendor/bin/pest 2>&1 | tail -3
npx tsc --noEmit && echo "tsc silent OK"
npm run build 2>&1 | tail -3
```

Expected: **56 tests pass** (49 baseline + 7). Record the measured assertion count. `tsc` silent. Build passes.

- [ ] **Step 10: Execute the counterfactuals and restore**

(a) restore implicit `RunSubject $subject` binding in `convert()` → `refuses to reach another organisation's run` fails and the cross-tenant write goes through; (b) swap `subjectOf()` for `RunSubject::findOrFail()` → `refuses a subject id that belongs to another run` fails; (c) remove `auth` from the group → `requires authentication for every demo mutation` fails; (d) put the raw input into the session → `refuses a tenant id that is not a real organisation` fails. Capture all four, restore.

- [ ] **Step 11: Commit in the demo repository**

```bash
cd ~/Sites/test-workflow
git add routes/web.php app/Http/Controllers/NodeflowDemoController.php app/Nodeflow/SessionTenantResolver.php resources/js/pages/nodeflow/demo.tsx tests/Feature/NodeflowDemoSecurityTest.php
git commit -m "fix: reach RunSubject through a scoped Run, and require auth

convert() and click() route-bound RunSubject directly. RunSubject carries
no tenant_id and no tenant scope by design — it is meant to be reached
only through an already-scoped Run, which docs/02-integration.md says in
so many words — so implicit binding handed out any tenant's row by primary
key, and the run was then re-fetched with withoutTenancy(). One
organisation could force-exit another's subject and write to their User row.

Measured while fixing it and worse than recorded: route:list showed the
whole demo group carried web alone, with no auth, so every mutation
including reseed (which runs db:seed) was reachable anonymously. And
switchTenant put any posted string into the session, so the ambient tenant
was attacker-chosen.

Now: {run} binds through the tenant-scoped Run, the subject is found
within it, the User write is scoped to the run's tenant, the group requires
auth, and the tenant switch is validated against organizations.

What this does NOT change: an authenticated user can still switch to
another organisation on purpose. That is the switcher's purpose in a demo,
not an oversight, and it is recorded as such in the package's open issues."
```

Do **not** commit `package-lock.json` if `npm run build` touched it.

---

## Task 17: Verification on merged `main`, and browser acceptance

**Files:** none created. `docs/superpowers/open-issues.md` gets its final counts.

**Interfaces:** none.

This task is the one that decides whether Plan 5 is done. Nothing here is optional and nothing here may be reported from memory — every claim is a command whose output you paste into the completion note.

- [ ] **Step 1: Merge the package worktree into `main` and retest there**

```bash
cd ~/Projects/laravel-nodeflow
git checkout main
git merge --no-ff plan-5-tooling
./vendor/bin/pest 2>&1 | tail -3
npx vitest run 2>&1 | tail -5
npx tsc --noEmit && echo "tsc silent OK"
```

Expected on merged `main`: **453 Pest tests**, **160 Vitest tests** (this plan touches no TypeScript, so 160 is the number — an increase means something unexpected happened and needs explaining, not accepting), silent `tsc`. Record measured assertion counts.

- [ ] **Step 2: Re-point the demo symlink and retest the demo against merged `main`**

```bash
cd ~/Sites/test-workflow
readlink -f vendor/atram/laravel-nodeflow
./vendor/bin/pest 2>&1 | tail -3
npx tsc --noEmit && echo "tsc silent OK"
npm run build 2>&1 | tail -3
```

Expected: the symlink resolves to `~/Projects/laravel-nodeflow`, **56 demo tests**, silent `tsc`, passing build. If a `composer install` ran at any point, the symlink now points at main's checkout — which is what you want *here*, and is exactly what you must not have during Task 16.

- [ ] **Step 3: Run `install` for real against the demo — the acceptance nobody can fake**

The demo is the only installed host, its provider predates the three registration homes, and Task 5 exists for precisely this file. So run the command that has never run against a real host:

```bash
cd ~/Sites/test-workflow
git status --porcelain           # must be clean first
php artisan nodeflow:install --check; echo "exit=$?"
```

Expected: exit **1**, with the provider reported NOT WIRED and every client requirement already wired.

```bash
php artisan nodeflow:install; echo "exit=$?"
git diff
```

Expected: exit **0**. The diff must show **only** additions to `app/Providers/NodeflowServiceProvider.php` — the three homes and the three `boot()` calls — and **must still contain the demo's existing `Nodeflow::register([SendMessage::class, TagUser::class, SegmentUsers::class])` call verbatim**. If anything else changed, or if that call was rewritten, stop: E25 says the edit is additive and this is the only place that claim is tested against a real hand-written provider.

Then prove the host still works and that the generators can now reach it:

```bash
./vendor/bin/pest 2>&1 | tail -3
php artisan nodeflow:install --check; echo "exit=$?"     # expect 0
php artisan nodeflow:make-subject-attribute demo_probe --label='Demo probe' --type=boolean
git diff app/Providers/NodeflowServiceProvider.php       # the entry landed inside subjectAttributes()
php -l app/Providers/NodeflowServiceProvider.php
git checkout app/Providers/NodeflowServiceProvider.php   # discard the probe
php artisan nodeflow:install                             # re-wire, since the checkout undid it
```

Commit the demo's newly wired provider:

```bash
git add app/Providers/NodeflowServiceProvider.php
git commit -m "chore: wire the demo provider with nodeflow:install

Run against the real host at the end of Plan 5. The provider predated the
three registration homes, so make-node returned AnchorMissing on it — the
exact case E25's additive edit exists for. The demo's own
Nodeflow::register([...]) call is untouched; both mechanisms registering is
harmless because register() is idempotent by type."
```

- [ ] **Step 4: Browser acceptance**

The harness needs Chrome remote debugging, which needs a manual toggle on the developer's own browser. Launch a separate instance instead, so nothing is asked of them and their profile is untouched:

```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --remote-debugging-port=9222 \
  --user-data-dir=/tmp/nodeflow-plan5-chrome &
```

Point the harness at it via `BU_CDP_WS`. A fresh profile forces a real login through the UI, which is what you want. Password-manager overlays can intercept clicks — keyboard activation works.

Start a queue worker first, or nothing will run:

```bash
cd ~/Sites/test-workflow && php artisan queue:work --tries=1 &
```

Five checks, all against `http://test-workflow.test/`:

1. **The demo still works end to end after the route reshape.** Log in, open `/nodeflow`, start a run, and click **clicked** and **convert (exit)** on a subject. Both must succeed and the subject must visibly change — this is the only check that exercises the new `runs/{run}/subjects/{subject}/…` URLs from the real client rather than from a test.
2. **The console is clean** across every interaction. No errors of any kind.
3. **The editor and run view still render.** Open a flow's editor and a run's view. Task 16 changed `demo.tsx`, and the Tailwind `@source` line and `dedupe` setting are the wiring `install` just reported on — a blank or unstyled canvas here means the wiring report was wrong about a real host.
4. **A cross-tenant convert is refused in the browser, not just in a test.** Switch the org to the *other* organisation, note a run id and a subject id belonging to it, switch back, then issue the convert POST for that pair from the page's own origin (devtools console `fetch` with the CSRF token). Expect **404**, and confirm in the UI afterwards that the other organisation's subject is still active.
5. **Logging out closes the demo.** Log out, then open `/nodeflow` — expect the login redirect, not the demo page.

Record each check's observed result. A check you did not run is a check that failed.

- [ ] **Step 5: Update `open-issues.md` with the measured totals**

Update the "Last updated" header with the merge commit and the measured numbers: package Pest tests and assertions, 160 Vitest tests, silent `tsc`; demo Pest tests and assertions, silent `tsc`, passing build. Add a "Plan 5 acceptance evidence" section recording the five browser checks and — separately, because it is the sharpest evidence in this plan — the result of Step 3: `install --check` exiting 1 against the real host, `install` exiting 0, and the diff being additions only with the demo's own `Nodeflow::register([...])` call intact.

Commit:

```bash
cd ~/Projects/laravel-nodeflow
git add docs/superpowers/open-issues.md
git commit -m "docs: record Plan 5 acceptance and the final counts"
```

- [ ] **Step 6: Report honestly**

State, with the command output for each: the package and demo test counts and assertion counts; the `tsc` and build results; the outcome of every counterfactual across all 17 tasks; the five browser checks; and anything you could not verify. If a counterfactual could not be reproduced, say so — the spec's §11 rule is that an unreproducible counterfactual is worth less than no claim, and reporting it as executed when it was not is the one failure this plan cannot absorb.

---

## Test Count Summary

Stated per task so drift is visible. **Assertion counts are measured, never predicted.**

| Task | Package tests added | Running total | Demo tests added |
|---|---|---|---|
| Baseline | — | 358 | 49 |
| 1 — F-1 | 1 | 359 | — |
| 2 — F-2 | 1 | 360 | — |
| 3 — Writer | 5 | 365 | — |
| 4 — Provider (create) | 6 | 371 | — |
| 5 — Provider (additive) | 6 | 377 | — |
| 6 — bootstrap/providers | 5 | 382 | — |
| 7 — Config + migrations | 8 | 390 | — |
| 8 — SourceText + Vite | 12 | 402 | — |
| 9 — tsconfig + xyflow | 12 | 414 | — |
| 10 — Tailwind | 7 | 421 | — |
| 11 — InstallCommand | 12 | 433 | — |
| 12 — make-trigger | 9 | 442 | — |
| 13 — make-subject-attribute | 8 | 450 | — |
| 14 — G-2 | 3 | 453 | — |
| 15 — Docs | 0 | 453 | — |
| 16 — Demo fix | 0 | 453 | 7 → **56** |
| 17 — Verification | 0 | **453** | 56 |

Vitest stays at **160**: this plan touches no TypeScript except two URL strings in the demo, which the demo has no Vitest suite for. An increase needs explaining, not accepting.

If a task's real count differs, record the measured number and why. Plan 4 shipped 160 Vitest tests against a predicted 151, and every one of those nine was a guard that had no test — so a count that comes in *higher* is worth reading, not rounding away. Do not pad, and do not trim to hit a number.
