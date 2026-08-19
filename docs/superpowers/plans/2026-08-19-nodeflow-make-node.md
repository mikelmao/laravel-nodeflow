# `nodeflow:make-node` Implementation Plan (Plan 1 of 6)

> ## ✅ EXECUTED — 2026-08-19/20, merged to `main` as `4cadfb7..e22bd89`
>
> Delivered in 18 commits. Suite 166 → **203 tests** (513 assertions). Executed with
> `superpowers:subagent-driven-development`: one implementer per task, an independent review after
> each, then a whole-branch review, one consolidated fix wave, and a scoped re-review.
>
> **Read the next two sections before copying anything out of this plan.** Parts of the task bodies
> below are wrong, and were found to be wrong during execution. The task bodies are deliberately left
> as written — they are the record of what was actually dispatched — so the corrections live here
> instead.
>
> ### Four rulings superseded this plan's text
>
> | # | Ruling | Why |
> |---|---|---|
> | A | Each task imports only what it uses | The plan pre-declared an import first needed two tasks later, producing a committed unused import |
> | B | **`handle()` returns `int`, not `bool|null`** | `Command::execute()` does `(int) $this->laravel->call(...)`, and `(int) false === 0`. The plan's own four `assertExitCode(1)` tests would have failed. Verified by running `make:event` twice |
> | C | No same-namespace `use` statements | The plan hedged ("drop them if your linter complains"); a hedge is a decision nobody made |
> | D | The `--type` prompt guards on `$this->input->isInteractive()` | An unguarded prompt under a mocked output throws `BadMethodCallException`, not a hang |
>
> ### Code in this plan that is known-defective — do not copy it
>
> - **Every `handle()` snippet** (Tasks 2, 3, 4, 5) declares `bool|null` and returns `false` for a
>   usage error. That exits **0**. See Ruling B. The shipped form is `handle(): int` mapping
>   `parent::handle() === false` to `self::FAILURE`.
> - **Task 1 Step 1's import block** lists three imports where two are used. See Ruling A.
> - **Task 4 Step 1's two writer tests** (`appends the node class inside the nodes array`, `appends
>   after the anchor line`) assert with `toContain` and a `strpos` ordering check. Both pass while the
>   insertion position is broken: removing `+ strlen(self::ANCHOR)` emits
>   `\App\...\SendSms::class,protected array $nodes = [`, a parse error, and neither test notices.
>   Mutation-proven. The shipped tests additionally lint the edited provider with `php -l` and assert
>   the entry lands between the anchor and its closing `];`.
> - **Task 4 Step 8's `registerNode()`** and **Task 5 Step 4's `writeTest()`** are correct as written,
>   but the surrounding `handle()` is not — see above.
> - **Task 5's generated test path** is `{Class}NodeTest.php`. Shipped as `{Class}Test.php`: the
>   package's own nodes are `WaitNode`/`ExitNode`/`ConditionNode`, so a host following that convention
>   got `SendSmsNodeNodeTest.php`.
>
> ### Six defects the plan did not anticipate, found by the whole-branch review and fixed
>
> `--force` could not overwrite any *registered* node — the only case it exists for — because
> `validateType()` ran before `parent::handle()` and rejected the type owned by the class being
> generated, advising a type change, which the foundation spec forbids for a live node
> (`6f15566`). The insertion-position blindness above (`8d92932`). Stub API references were linted
> but never executed, so renaming `NodeDefinition::outputNames()` would have left every stub green
> while fataling in every host (`7239ea0`). `docs/02-integration.md` denied that a scaffolding
> generator exists (`dec3fe6`). The already-present check required a leading backslash, so a
> provider listing the class unprefixed got a duplicate entry (`7daa903`). `--outputs`/`--group`
> rendered into PHP unescaped — `--group="O'Brien"` produced an unparseable file and exit 0
> (`67f00aa`).
>
> ### Two residuals parked, not fixed
>
> 1. `--group='{{ outputs }}'` still yields an unparseable file with exit 0: `buildClass()`
>    substitutes `{{ group }}` before `{{ outputs }}` and `str_replace` is sequential. Two-line fix.
>    `paletteGroup()`'s docblock claims a backslash and a quote are the only dangerous characters,
>    which is now false — fix the comment first.
> 2. Nothing watches `stubs/node.both.stub` for API drift. Renaming `->help(` in that file alone
>    leaves all 203 tests green while the stub fatals in every host. ~15 lines, mirroring the
>    existing audience test under a fourth class name.
>
> ### The process lesson worth carrying into Plans 2–6
>
> Five of this branch's findings originated in **this plan's text**, not in execution — most
> consequentially the two writer tests that could not detect the failure they named, in a plan whose
> own Global Constraints require naming that failure. The pre-flight scan checked cross-task
> interfaces but never turned the plan's own test-counterfactual rule on the plan's own test
> snippets. Add that check; it is cheap and would have caught this before Task 4 ran.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `php artisan nodeflow:make-node`, which generates a single-file Nodeflow node class — and optionally a Pest test for it — so a host application can start a domain node in one command.

**Architecture:** A `GeneratorCommand` subclass rendering one of three stub files chosen by cardinality, plus a small separate `NodeRegistrationWriter` that appends the new class to the host's `NodeflowServiceProvider` when it can prove where to put it and prints the registration snippet when it cannot. The command is the only new console entry point; registration writing is a separate unit so it can be tested without generating files.

**Tech Stack:** PHP 8.3, Laravel 13 (`Illuminate\Console\GeneratorCommand`, `laravel/prompts`), Pest 4, Orchestra Testbench 10/11.

**Spec:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — §7.2 is this command, §3 is why it is Plan 1. The foundation spec `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` §5 is the node contract being generated.

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP `^8.3`.** Dependencies stay at `illuminate/*: ^12.0|^13.0`.
- **One file per node.** The foundation spec's primary ergonomic goal is "a domain node is about an hour's work: one class plus one declarative definition." A generator that emits a directory contradicts the goal it exists to serve. Voodflow's three-file-per-node layout is the shape to avoid.
- **`type()` returns a plain string literal, never derived from the class name.** Foundation spec §5: graph versions are immutable and live mid-wait runs resolve through this string. The generated stub must hard-code it.
- **`core.` is a reserved type prefix** for package-supplied nodes. The command refuses it.
- **Cardinality is load-bearing, not decorative.** `NodeRunner` dispatches on `instanceof HandlesSubject` / `instanceof HandlesAudience`, never on method names. A generated class declaring `forSubject()` without `implements HandlesSubject` would register, validate, publish, start a run, and then throw on the first real subject. The generated stub and its generated test must both close that hole.
- **Anchor-assert then verify (spec E11).** Any edit to an existing file asserts its anchor exists and is unique *before* writing. Two edits in this project's history applied cleanly and changed nothing because a pattern did not match and nothing asserted it had.
- **For every test, name the production change that would make it fail.** Eight tests written during the foundation work read as covering a property while being unable to detect its failure. If you cannot name the counterfactual, the test is not finished.
- **Do not touch `src/Engine/` or `src/Workflows/`,** and do not import `Workflow\` anywhere. `tests/Unit/ArchitectureTest.php` enforces it over every `.php` file in `src/`.
- **Test command:** `vendor/bin/pest`. Filter with `vendor/bin/pest --filter='<pattern>'`.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `stubs/node.stub` | The `HandlesSubject` node template |
| `stubs/node.audience.stub` | The `HandlesAudience` node template |
| `stubs/node.both.stub` | The both-interfaces node template |
| `stubs/node.test.stub` | The generated Pest test template |
| `src/Console/MakeNodeCommand.php` | Option parsing, validation, stub selection, replacements |
| `src/Console/NodeRegistrationWriter.php` | Anchor-asserted append into the host provider |
| `src/Console/NodeRegistrationOutcome.php` | Enum of what the writer did, so the command's messaging is exhaustive |
| `tests/Feature/MakeNodeCommandTest.php` | The command, end to end, against a temp app root |
| `tests/Unit/NodeRegistrationWriterTest.php` | The writer in isolation |

**Modified:**

| Path | Change |
|---|---|
| `src/NodeflowServiceProvider.php:63-66` | Register `MakeNodeCommand` in the `$this->commands([...])` array |
| `composer.json` | Add `illuminate/console` to `require` |
| `docs/03-writing-nodes.md` | Document the generator at the top of the guide |

**Why `illuminate/console` is added:** `src/Console/CheckNodeTypesCommand.php` and `src/Console/PruneCommand.php` already extend `Illuminate\Console\Command` while `composer.json` requires only `illuminate/support` and `illuminate/database`. The dependency is real and undeclared today; this plan adds a third console command, so declaring it now is the right moment. `^12.0|^13.0` matches the existing constraints, so no resolution changes.

---

## A note on how the tests fake an application

`GeneratorCommand` writes to `app_path()` and derives the class namespace from
`Application::getNamespace()`, which reads `basePath('composer.json')`'s psr-4 map and matches it by
comparing `realpath(app_path())` against `realpath(base_path($psr4Path))`. Moving only the app path
therefore throws `RuntimeException: Unable to detect application namespace`.

So the tests build a whole minimal app root in a temp directory — `composer.json`, `app/`,
`tests/` — and call `setBasePath()` on it. Writing into Testbench's own skeleton at
`vendor/orchestra/testbench-core/laravel/app` would otherwise pollute `vendor/` and leak state
between runs.

This `beforeEach`/`afterEach` pair is used verbatim by both Task 1's test file and every later task
that adds to it.

```php
beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-make-node-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/app', 0777, true);
    mkdir($this->root.'/tests', 0777, true);

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
```

---

## Task 1: The command, generating a subject node

**Files:**
- Create: `stubs/node.stub`
- Create: `src/Console/MakeNodeCommand.php`
- Modify: `src/NodeflowServiceProvider.php:63-66`
- Modify: `composer.json`
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `Nodeflow\Console\MakeNodeCommand`, signature `nodeflow:make-node {name} [--type=] [--cardinality=subject] [--outputs=default] [--group=General] [--test] [--force|-f]`. Later tasks add behaviour behind `--cardinality`, `--outputs`, `--group` and `--test`; the options are all declared here so the signature never changes. Protected members later tasks override or call: `getStub(): string`, `resolveStubPath(string $stub): string`, `buildClass(string $name): string`, `nodeType(): string`, `outputNames(): array`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MakeNodeCommandTest.php` with the `beforeEach`/`afterEach` pair from the section above, then this test:

```php
it('generates a subject node at the conventional path', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    $path = $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    expect($path)->toBeFile();

    $contents = file_get_contents($path);

    expect($contents)
        ->toContain('namespace App\Nodeflow\Nodes;')
        ->toContain('class SendSms extends Node implements HandlesSubject')
        ->toContain("return 'yaya.send_sms';")
        ->toContain('public function forSubject(SubjectContext $context): NodeResult');
});

it('produces a class the registry accepts and can resolve', function () {
    // The counterfactual: drop `implements HandlesSubject` from the stub and this
    // fails. NodeRegistry::register() rejects a node implementing neither
    // cardinality interface, which is the whole reason the stub declares one.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    require $this->root.'/app/Nodeflow/Nodes/SendSms.php';

    app(NodeRegistry::class)->register('App\Nodeflow\Nodes\SendSms');

    expect(app(NodeRegistry::class)->has('yaya.send_sms'))->toBeTrue();
    expect(app(NodeRegistry::class)->resolve('yaya.send_sms'))
        ->toBeInstanceOf(HandlesSubject::class);
});
```

Add these imports at the top of the file, below `<?php`. All four are used by later tasks in this
plan, so declare them now and the file's header never changes again:

```php
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeSendNode;
```

**One caution about the `require` in the second test.** It defines `App\Nodeflow\Nodes\SendSms` in
the process, so a second `require` of a second generated `SendSms` in the same run would fatal with
"class already declared". Exactly one test in this plan requires a generated file; if you add
another, generate it under a different class name.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='generates a subject node'`

Expected: FAIL. `The command "nodeflow:make-node" does not exist.`

- [ ] **Step 3: Write the node stub**

Create `stubs/node.stub`:

```php
<?php

namespace {{ namespace }};

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class {{ class }} extends Node implements HandlesSubject
{
    /**
     * The stable identifier for this node type.
     *
     * Published graph versions are immutable and runs sitting mid-wait resolve
     * through this string, so changing it orphans every version that references
     * it. Never derive it from the class name — renaming the class must be free.
     */
    public static function type(): string
    {
        return '{{ type }}';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('{{ label }}')
            ->group('{{ group }}')
            ->description('TODO: describe this node for the person authoring a flow.')
            ->outputs([{{ outputs }}])
            ->fields([
                Field::text('example')
                    ->label('Example')
                    ->help('TODO: replace this with the fields this node actually needs.')
                    ->required(),
            ]);
    }

    /**
     * Runs once per subject. The runtime chunks the audience, iterates it, and
     * isolates per-subject failures, so write single-subject code here.
     */
    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            // Test mode must cause no externally visible side effect. A node that
            // ignores this is a reviewable bug, not a style preference.
            return $context->continue('{{ firstOutput }}');
        }

        // TODO: do the work. Return $context->continue('<output>') to route this
        // subject down an output, or $context->fail('why') to record a failure
        // against this subject alone without stopping the rest of the audience.
        return $context->continue('{{ firstOutput }}');
    }
}
```

- [ ] **Step 4: Write the command**

Create `src/Console/MakeNodeCommand.php`:

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputOption;

class MakeNodeCommand extends GeneratorCommand
{
    protected $name = 'nodeflow:make-node';

    protected $description = 'Create a Nodeflow node class.';

    protected $type = 'Node';

    protected function getStub(): string
    {
        return $this->resolveStubPath('/stubs/node.stub');
    }

    /**
     * Laravel's own generators let a host override a stub by placing a file of
     * the same name under its base path. Following that convention costs six
     * lines and is what a Laravel developer will expect.
     */
    protected function resolveStubPath(string $stub): string
    {
        $custom = $this->laravel->basePath(trim($stub, '/'));

        return file_exists($custom) ? $custom : __DIR__.'/../..'.$stub;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Nodeflow\Nodes';
    }

    protected function buildClass($name): string
    {
        $stub = parent::buildClass($name);

        $outputs = $this->outputNames();

        return str_replace(
            ['{{ type }}', '{{ label }}', '{{ group }}', '{{ outputs }}', '{{ firstOutput }}'],
            [
                $this->nodeType(),
                Str::headline(class_basename($this->getNameInput())),
                (string) $this->option('group'),
                implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
                $outputs[0],
            ],
            $stub,
        );
    }

    protected function nodeType(): string
    {
        return (string) ($this->option('type') ?: Str::snake(class_basename($this->getNameInput())));
    }

    /** @return string[] */
    protected function outputNames(): array
    {
        $outputs = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('outputs')),
        ), fn (string $o) => $o !== ''));

        return $outputs === [] ? ['default'] : $outputs;
    }

    protected function getOptions(): array
    {
        return [
            ['type', null, InputOption::VALUE_OPTIONAL, 'The stable type identifier, e.g. yaya.send_message'],
            ['cardinality', null, InputOption::VALUE_OPTIONAL, 'subject, audience, or both', 'subject'],
            ['outputs', null, InputOption::VALUE_OPTIONAL, 'Comma-separated output names', 'default'],
            ['group', null, InputOption::VALUE_OPTIONAL, 'Palette group shown in the editor', 'General'],
            ['test', null, InputOption::VALUE_NONE, 'Also generate a Pest test for the node'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the node if it already exists'],
        ];
    }
}
```

- [ ] **Step 5: Register the command**

In `src/NodeflowServiceProvider.php`, the `$this->commands([...])` call currently reads:

```php
            $this->commands([
                \Nodeflow\Console\CheckNodeTypesCommand::class,
                \Nodeflow\Console\PruneCommand::class,
            ]);
```

Replace it with:

```php
            $this->commands([
                \Nodeflow\Console\CheckNodeTypesCommand::class,
                \Nodeflow\Console\MakeNodeCommand::class,
                \Nodeflow\Console\PruneCommand::class,
            ]);
```

- [ ] **Step 6: Declare the console dependency**

In `composer.json`, the `require` block currently reads:

```json
    "require": {
        "php": "^8.3",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "durable-workflow/workflow": "^2.0@rc"
    },
```

Replace it with:

```json
    "require": {
        "php": "^8.3",
        "illuminate/console": "^12.0|^13.0",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "durable-workflow/workflow": "^2.0@rc"
    },
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='MakeNodeCommand'`

Expected: PASS, 2 tests.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS. The pre-existing 166 tests plus 2 new ones. `ArchitectureTest` must still pass — the new files import no `Workflow\`.

- [ ] **Step 9: Commit**

```bash
git add stubs/node.stub src/Console/MakeNodeCommand.php src/NodeflowServiceProvider.php composer.json tests/Feature/MakeNodeCommandTest.php
git commit -m "feat: add nodeflow:make-node generating a subject node"
```

---

## Task 2: Cardinality

**Files:**
- Create: `stubs/node.audience.stub`
- Create: `stubs/node.both.stub`
- Modify: `src/Console/MakeNodeCommand.php` — `getStub()`
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: `MakeNodeCommand::getStub()` and `resolveStubPath()` from Task 1.
- Produces: `--cardinality=subject|audience|both` selecting between three stubs. `MakeNodeCommand::cardinality(): string` returns the validated lowercase value; Task 5's test generation calls it to pick which interface the generated test asserts.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MakeNodeCommandTest.php`:

```php
it('generates an audience node that does not also declare forSubject', function () {
    // The counterfactual: make getStub() ignore --cardinality and this fails on
    // the forSubject assertion, because the subject stub would be rendered.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBatch',
        '--type' => 'yaya.send_batch',
        '--cardinality' => 'audience',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendBatch.php');

    expect($contents)
        ->toContain('class SendBatch extends Node implements HandlesAudience')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult')
        ->not->toContain('forSubject');
});

it('generates a both-cardinality node declaring two interfaces and two methods', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendEither',
        '--type' => 'yaya.send_either',
        '--cardinality' => 'both',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendEither.php');

    expect($contents)
        ->toContain('implements HandlesSubject, HandlesAudience')
        ->toContain('public function forSubject(SubjectContext $context): NodeResult')
        ->toContain('public function forAudience(AudienceContext $context): NodeResult');
});

it('refuses an unknown cardinality without writing a file', function () {
    // The counterfactual: accept any string and this fails, because getStub()
    // would resolve a nonexistent stub path and throw instead of exiting 1.
    $this->artisan('nodeflow:make-node', [
        'name' => 'Broken',
        '--type' => 'yaya.broken',
        '--cardinality' => 'sideways',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Broken.php')->not->toBeFile();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='audience node that does not also'`

Expected: FAIL. The subject stub is rendered, so `class SendBatch extends Node implements HandlesAudience` is absent.

- [ ] **Step 3: Write the audience stub**

Create `stubs/node.audience.stub`:

```php
<?php

namespace {{ namespace }};

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class {{ class }} extends Node implements HandlesAudience
{
    /**
     * The stable identifier for this node type.
     *
     * Published graph versions are immutable and runs sitting mid-wait resolve
     * through this string, so changing it orphans every version that references
     * it. Never derive it from the class name — renaming the class must be free.
     */
    public static function type(): string
    {
        return '{{ type }}';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('{{ label }}')
            ->group('{{ group }}')
            ->description('TODO: describe this node for the person authoring a flow.')
            ->outputs([{{ outputs }}])
            ->fields([
                Field::text('example')
                    ->label('Example')
                    ->help('TODO: replace this with the fields this node actually needs.')
                    ->required(),
            ]);
    }

    /**
     * Receives the whole audience at once, for work that batches natively.
     * $context->subjectIds() is the id list; $context->subjects() resolves them
     * to models through the host's SubjectResolver.
     */
    public function forAudience(AudienceContext $context): NodeResult
    {
        if ($context->isTest()) {
            // Test mode must cause no externally visible side effect. A node that
            // ignores this is a reviewable bug, not a style preference.
            return $context->all('{{ firstOutput }}');
        }

        // TODO: do the work. Return $context->all('<output>') to send everyone
        // down one output, or $context->partition(['a' => [...], 'b' => [...]])
        // to split the audience across several.
        return $context->all('{{ firstOutput }}');
    }
}
```

- [ ] **Step 4: Write the both stub**

Create `stubs/node.both.stub`:

```php
<?php

namespace {{ namespace }};

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class {{ class }} extends Node implements HandlesSubject, HandlesAudience
{
    /**
     * The stable identifier for this node type.
     *
     * Published graph versions are immutable and runs sitting mid-wait resolve
     * through this string, so changing it orphans every version that references
     * it. Never derive it from the class name — renaming the class must be free.
     */
    public static function type(): string
    {
        return '{{ type }}';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('{{ label }}')
            ->group('{{ group }}')
            ->description('TODO: describe this node for the person authoring a flow.')
            ->outputs([{{ outputs }}])
            ->fields([
                Field::text('example')
                    ->label('Example')
                    ->help('TODO: replace this with the fields this node actually needs.')
                    ->required(),
            ]);
    }

    /**
     * The correctness path, and what small runs use. Runs once per subject; the
     * runtime chunks, iterates and isolates per-subject failures.
     */
    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->continue('{{ firstOutput }}');
        }

        // TODO: the single-subject implementation.
        return $context->continue('{{ firstOutput }}');
    }

    /**
     * The efficiency path, used when the runtime has a whole audience in hand.
     * It must produce the same outcome as forSubject() for the same subjects —
     * a divergence between the two is invisible until scale changes which one
     * the runtime picks.
     */
    public function forAudience(AudienceContext $context): NodeResult
    {
        if ($context->isTest()) {
            return $context->all('{{ firstOutput }}');
        }

        // TODO: the batched implementation.
        return $context->all('{{ firstOutput }}');
    }
}
```

- [ ] **Step 5: Select the stub by cardinality**

In `src/Console/MakeNodeCommand.php`, replace the `getStub()` method:

```php
    protected function getStub(): string
    {
        return $this->resolveStubPath(match ($this->cardinality()) {
            'audience' => '/stubs/node.audience.stub',
            'both' => '/stubs/node.both.stub',
            default => '/stubs/node.stub',
        });
    }

    /**
     * Validated here rather than by an InputOption suggestion list, because an
     * unrecognised value would otherwise resolve a stub path that does not
     * exist and surface as a file-not-found rather than as a usage error.
     *
     * @throws \InvalidArgumentException
     */
    protected function cardinality(): string
    {
        $cardinality = strtolower(trim((string) $this->option('cardinality')));

        if (! in_array($cardinality, ['subject', 'audience', 'both'], true)) {
            throw new \InvalidArgumentException(
                "Unknown cardinality [{$cardinality}]. Use subject, audience, or both. ".
                'A node must implement at least one cardinality interface: forSubject() lets '.
                'the runtime chunk and iterate for you, forAudience() hands you the whole '.
                'audience for work that batches natively.'
            );
        }

        return $cardinality;
    }
```

Then add a `handle()` override that turns the exception into a usage error rather than a stack trace:

```php
    public function handle(): bool|null
    {
        try {
            $this->cardinality();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        return parent::handle();
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='MakeNodeCommand'`

Expected: PASS, 5 tests.

- [ ] **Step 7: Commit**

```bash
git add stubs/node.audience.stub stubs/node.both.stub src/Console/MakeNodeCommand.php tests/Feature/MakeNodeCommandTest.php
git commit -m "feat: select the node stub by --cardinality"
```

---

## Task 3: Outputs, group, and type guards

**Files:**
- Modify: `src/Console/MakeNodeCommand.php` — `nodeType()`, plus a new `validateType()`
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: `nodeType()`, `outputNames()`, `buildClass()` from Task 1; `handle()` from Task 2.
- Produces: `nodeType()` now prompts when `--type` is absent and throws `InvalidArgumentException` for a malformed, reserved, or already-registered type. Task 5 relies on `nodeType()` and `outputNames()` returning validated values.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MakeNodeCommandTest.php`:

```php
it('renders the declared outputs and group into the definition', function () {
    // The counterfactual: hard-code ['default'] in the stub and this fails.
    // Non-default values are used deliberately — asserting on 'default' would
    // pass while --outputs was being ignored entirely.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, failed',
        '--group' => 'Messaging',
    ])->assertExitCode(0);

    $contents = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendSms.php');

    expect($contents)
        ->toContain("->outputs(['sent', 'failed'])")
        ->toContain("->group('Messaging')")
        ->toContain("return \$context->continue('sent');")
        ->toContain("NodeDefinition::make('Send Sms')");
});

it('refuses a type using the reserved core prefix', function () {
    // Asserting the message, not just the exit code. `core.wait` is BOTH reserved
    // and already registered, so an exit-code-only assertion would pass even if
    // the reserved-prefix rule did not exist — the duplicate rule would catch it.
    // Two rules that can both fire on one input need messages to tell apart.
    $this->artisan('nodeflow:make-node', [
        'name' => 'Sneaky',
        '--type' => 'core.wait',
    ])
        ->expectsOutputToContain('reserved [core.] prefix')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Sneaky.php')->not->toBeFile();
});

it('refuses a type already registered by another node', function () {
    // NodeRegistry::register() assigns $types[$class::type()] = $class, so a
    // duplicate type silently replaces the existing node in every palette and
    // every graph that resolves it.
    //
    // `test.send` is used rather than a core.* type precisely because the
    // reserved-prefix rule runs first: a core.* type would exit 1 for the wrong
    // reason and this test would pass with the duplicate check deleted.
    app(NodeRegistry::class)->register(FakeSendNode::class);

    $this->artisan('nodeflow:make-node', [
        'name' => 'MyDuplicate',
        '--type' => 'test.send',
    ])
        ->expectsOutputToContain('is already registered by')
        ->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/MyDuplicate.php')->not->toBeFile();
});

it('refuses a malformed type', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'Shouty',
        '--type' => 'Yaya Send Message',
    ])->assertExitCode(1);

    expect($this->root.'/app/Nodeflow/Nodes/Shouty.php')->not->toBeFile();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='refuses a type using the reserved'`

Expected: FAIL. Exit code is 0 and the file is written, because nothing validates the type yet.

- [ ] **Step 3: Add type validation and prompting**

In `src/Console/MakeNodeCommand.php`, replace the `nodeType()` method with:

```php
    /** Reserved for the package's own nodes: core.wait, core.condition, and so on. */
    private const RESERVED_PREFIX = 'core.';

    /** Lowercase segments joined by dots or underscores: yaya.send_message, rada.read_severity. */
    private const TYPE_PATTERN = '/^[a-z0-9]+(?:[._][a-z0-9]+)*$/';

    protected function nodeType(): string
    {
        if ($this->resolvedType !== null) {
            return $this->resolvedType;
        }

        $suggested = Str::snake(class_basename($this->getNameInput()));

        $type = trim((string) $this->option('type'));

        // Guarded on isInteractive() rather than on the --no-interaction option:
        // a Testbench PendingCommand does not necessarily pass that flag, and an
        // unguarded prompt in a test suite hangs rather than fails.
        if ($type === '' && $this->input->isInteractive()) {
            $type = trim(text(
                label: 'Stable type identifier for this node',
                placeholder: 'yaya.send_message',
                default: $suggested,
                hint: 'Published flow versions resolve through this string forever. Prefix it with your domain.',
            ));
        }

        return $this->resolvedType = $this->validateType($type === '' ? $suggested : $type);
    }

    /** @throws \InvalidArgumentException */
    private function validateType(string $type): string
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException(
                "[{$type}] is not a valid node type. Use lowercase letters, digits, dots and ".
                'underscores, e.g. yaya.send_message.'
            );
        }

        if (str_starts_with($type, self::RESERVED_PREFIX)) {
            throw new \InvalidArgumentException(
                "[{$type}] uses the reserved [core.] prefix, which belongs to the nodes the ".
                'package itself ships. Prefix your own types with your domain instead.'
            );
        }

        // NodeRegistry keys by type, so registering a second node with an existing
        // type silently replaces the first in every palette and every graph that
        // resolves it. Refuse here rather than let that be discovered at runtime.
        if ($this->laravel->make(NodeRegistry::class)->has($type)) {
            $existing = $this->laravel->make(NodeRegistry::class)->all()[$type];

            throw new \InvalidArgumentException(
                "Type [{$type}] is already registered by [{$existing}]. Two nodes sharing a ".
                'type silently replace each other in the registry. Choose another type.'
            );
        }

        return $type;
    }
```

Add the memoisation property just below `protected $type = 'Node';`:

```php
    private ?string $resolvedType = null;
```

Add these imports below `use Illuminate\Support\Str;`:

```php
use Nodeflow\Nodes\NodeRegistry;

use function Laravel\Prompts\text;
```

- [ ] **Step 4: Validate the type before generating**

The `handle()` override from Task 2 currently validates only cardinality. Replace it with:

```php
    public function handle(): bool|null
    {
        // Both are resolved before parent::handle() writes anything, so a usage
        // error never leaves a half-generated file behind.
        try {
            $this->cardinality();
            $this->nodeType();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        return parent::handle();
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='MakeNodeCommand'`

Expected: PASS, 9 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Console/MakeNodeCommand.php tests/Feature/MakeNodeCommandTest.php
git commit -m "feat: validate the node type and render outputs and group"
```

---

## Task 4: Registration into the host provider

**Files:**
- Create: `src/Console/NodeRegistrationOutcome.php`
- Create: `src/Console/NodeRegistrationWriter.php`
- Modify: `src/Console/MakeNodeCommand.php` — `handle()`
- Test: `tests/Unit/NodeRegistrationWriterTest.php`
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: `MakeNodeCommand::handle()` from Task 3.
- Produces:
  - `Nodeflow\Console\NodeRegistrationOutcome` — enum cases `Appended`, `AlreadyPresent`, `ProviderMissing`, `AnchorMissing`, `AnchorAmbiguous`.
  - `Nodeflow\Console\NodeRegistrationWriter::__construct(Illuminate\Filesystem\Filesystem $files)`.
  - `NodeRegistrationWriter::register(string $providerPath, string $nodeClass): NodeRegistrationOutcome`.
  - `NodeRegistrationWriter::ANCHOR` — the string constant `'protected array $nodes = ['`. Plan 5's `nodeflow:install` generates a provider containing this exact line; that is the contract between the two plans.

- [ ] **Step 1: Write the failing writer tests**

Create `tests/Unit/NodeRegistrationWriterTest.php`:

```php
<?php

use Illuminate\Filesystem\Filesystem;
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;

function provider(string $body): string
{
    $path = sys_get_temp_dir().'/nodeflow-provider-'.bin2hex(random_bytes(6)).'.php';

    file_put_contents($path, $body);

    return $path;
}

function providerWithAnchor(string $entries = '        //'): string
{
    return provider(<<<PHP
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;
    use Nodeflow\Nodeflow;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array \$nodes = [
    {$entries}
        ];

        public function boot(): void
        {
            Nodeflow::register(\$this->nodes);
        }
    }
    PHP);
}

it('appends the node class inside the nodes array', function () {
    $path = providerWithAnchor();

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::Appended);
    expect(file_get_contents($path))
        ->toContain('\App\Nodeflow\Nodes\SendSms::class,');
});

it('appends after the anchor line, not at the end of the file', function () {
    // The counterfactual: append to the end of the file and this fails. A class
    // constant written after the closing brace is a syntax error, and a plain
    // str_replace on ']' would land in the wrong array.
    $path = providerWithAnchor();

    (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    $contents = file_get_contents($path);

    expect(strpos($contents, 'SendSms::class'))
        ->toBeLessThan(strpos($contents, 'public function boot'));
});

it('does not add a second entry for a class already registered', function () {
    $path = providerWithAnchor('        \App\Nodeflow\Nodes\SendSms::class,');

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AlreadyPresent);
    expect(substr_count(file_get_contents($path), 'SendSms::class'))->toBe(1);
});

it('reports a missing provider without creating one', function () {
    $path = sys_get_temp_dir().'/nodeflow-absent-'.bin2hex(random_bytes(6)).'.php';

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::ProviderMissing);
    expect($path)->not->toBeFile();
});

it('refuses to guess when the anchor is absent', function () {
    // This is the trap the project has already paid for: an edit that applies
    // cleanly and changes nothing. The counterfactual: skip the anchor check and
    // this returns Appended while the file is untouched.
    $path = provider("<?php\n\nclass Whatever {}\n");
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorMissing);
    expect(file_get_contents($path))->toBe($before);
});

it('refuses to guess when the anchor is ambiguous', function () {
    $path = provider(<<<'PHP'
    <?php

    class Two
    {
        protected array $nodes = [
        ];

        protected array $nodes = [
        ];
    }
    PHP);
    $before = file_get_contents($path);

    $outcome = (new NodeRegistrationWriter(new Filesystem))
        ->register($path, 'App\Nodeflow\Nodes\SendSms');

    expect($outcome)->toBe(NodeRegistrationOutcome::AnchorAmbiguous);
    expect(file_get_contents($path))->toBe($before);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='NodeRegistrationWriter'`

Expected: FAIL. `Class "Nodeflow\Console\NodeRegistrationWriter" not found`.

- [ ] **Step 3: Write the outcome enum**

Create `src/Console/NodeRegistrationOutcome.php`:

```php
<?php

namespace Nodeflow\Console;

/**
 * What NodeRegistrationWriter did. An enum rather than a boolean so the command
 * can explain each case differently: "already registered" needs no action,
 * while a missing anchor means the author must paste a line themselves.
 */
enum NodeRegistrationOutcome
{
    case Appended;
    case AlreadyPresent;
    case ProviderMissing;
    case AnchorMissing;
    case AnchorAmbiguous;

    public function needsManualRegistration(): bool
    {
        return match ($this) {
            self::Appended, self::AlreadyPresent => false,
            default => true,
        };
    }
}
```

- [ ] **Step 4: Write the writer**

Create `src/Console/NodeRegistrationWriter.php`:

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Filesystem\Filesystem;

/**
 * Appends a node class to the host's NodeflowServiceProvider.
 *
 * Separate from MakeNodeCommand because editing someone else's file is the
 * riskiest thing the generator does and deserves tests that do not involve
 * generating anything. The rule it exists to enforce: assert the anchor is
 * present and unique before writing, and change nothing at all otherwise. An
 * edit that applies cleanly and silently matches nothing has cost this project
 * time twice already.
 */
class NodeRegistrationWriter
{
    public const ANCHOR = 'protected array $nodes = [';

    public function __construct(private Filesystem $files) {}

    public function register(string $providerPath, string $nodeClass): NodeRegistrationOutcome
    {
        if (! $this->files->exists($providerPath)) {
            return NodeRegistrationOutcome::ProviderMissing;
        }

        $contents = $this->files->get($providerPath);
        $entry = '\\'.ltrim($nodeClass, '\\').'::class';

        if (str_contains($contents, $entry)) {
            return NodeRegistrationOutcome::AlreadyPresent;
        }

        $occurrences = substr_count($contents, self::ANCHOR);

        if ($occurrences === 0) {
            return NodeRegistrationOutcome::AnchorMissing;
        }

        if ($occurrences > 1) {
            return NodeRegistrationOutcome::AnchorAmbiguous;
        }

        $position = strpos($contents, self::ANCHOR) + strlen(self::ANCHOR);

        $this->files->put($providerPath, substr_replace(
            $contents,
            PHP_EOL.'        '.$entry.',',
            $position,
            0,
        ));

        return NodeRegistrationOutcome::Appended;
    }
}
```

- [ ] **Step 5: Run the writer tests to verify they pass**

Run: `vendor/bin/pest --filter='NodeRegistrationWriter'`

Expected: PASS, 6 tests.

- [ ] **Step 6: Write the failing command tests**

Append to `tests/Feature/MakeNodeCommandTest.php`:

```php
it('registers the generated node in the host provider when it can', function () {
    mkdir($this->root.'/app/Providers', 0777, true);
    file_put_contents($this->root.'/app/Providers/NodeflowServiceProvider.php', <<<'PHP'
    <?php

    namespace App\Providers;

    use Illuminate\Support\ServiceProvider;

    class NodeflowServiceProvider extends ServiceProvider
    {
        protected array $nodes = [
            //
        ];
    }
    PHP);

    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    expect(file_get_contents($this->root.'/app/Providers/NodeflowServiceProvider.php'))
        ->toContain('\App\Nodeflow\Nodes\SendSms::class,');
});

it('prints the registration snippet when there is no provider to edit', function () {
    // nodeflow:install lands in Plan 5, so through Plans 1-4 this is the normal
    // path, not the edge case. The counterfactual: exit non-zero or say nothing
    // when the provider is absent, and the author is left with an unregistered
    // node that never appears in the palette.
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->expectsOutputToContain('Nodeflow::register([')
        ->expectsOutputToContain('\App\Nodeflow\Nodes\SendSms::class')
        ->assertExitCode(0);
});
```

- [ ] **Step 7: Run them to verify they fail**

Run: `vendor/bin/pest --filter='registers the generated node in the host provider'`

Expected: FAIL. The provider is untouched, because `handle()` does not call the writer yet.

- [ ] **Step 8: Call the writer from the command**

In `src/Console/MakeNodeCommand.php`, replace the `handle()` method with:

```php
    public function handle(): bool|null
    {
        // Both are resolved before parent::handle() writes anything, so a usage
        // error never leaves a half-generated file behind.
        try {
            $this->cardinality();
            $this->nodeType();
        } catch (\InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return false;
        }

        $generated = parent::handle();

        if ($generated === false) {
            return false;
        }

        $this->registerNode($this->qualifyClass($this->getNameInput()));

        return $generated;
    }

    /**
     * Registration is explicit in this package by design — there is no directory
     * auto-discovery — so a generated node that nobody registers never reaches
     * the palette. The writer edits the provider only when it can prove where
     * the entry belongs; otherwise the author gets a line to paste, and is told
     * why they got it rather than left to wonder.
     */
    private function registerNode(string $nodeClass): void
    {
        $outcome = $this->laravel->make(NodeRegistrationWriter::class)->register(
            $this->laravel->basePath('app/Providers/NodeflowServiceProvider.php'),
            $nodeClass,
        );

        match ($outcome) {
            NodeRegistrationOutcome::Appended => $this->components->info(
                'Registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::AlreadyPresent => $this->components->info(
                'Already registered in app/Providers/NodeflowServiceProvider.php.'
            ),
            NodeRegistrationOutcome::ProviderMissing => $this->manualRegistration($nodeClass,
                'No app/Providers/NodeflowServiceProvider.php found.'
            ),
            NodeRegistrationOutcome::AnchorMissing => $this->manualRegistration($nodeClass,
                'app/Providers/NodeflowServiceProvider.php has no `'.NodeRegistrationWriter::ANCHOR.'` line.'
            ),
            NodeRegistrationOutcome::AnchorAmbiguous => $this->manualRegistration($nodeClass,
                'app/Providers/NodeflowServiceProvider.php has more than one `'.NodeRegistrationWriter::ANCHOR.'` line.'
            ),
        };
    }

    private function manualRegistration(string $nodeClass, string $because): void
    {
        $this->components->warn($because.' Register the node yourself:');
        $this->newLine();
        $this->line('    Nodeflow::register([');
        $this->line('        \\'.$nodeClass.'::class,');
        $this->line('    ]);');
        $this->newLine();
    }
```

Add the import below `use Nodeflow\Nodes\NodeRegistry;`:

```php
use Nodeflow\Console\NodeRegistrationOutcome;
use Nodeflow\Console\NodeRegistrationWriter;
```

Both classes are in the same namespace as `MakeNodeCommand`, so if your linter flags the redundant
imports, drop them and reference the classes unqualified — they resolve either way.

- [ ] **Step 9: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='MakeNodeCommand'`

Expected: PASS, 11 tests.

- [ ] **Step 10: Commit**

```bash
git add src/Console/NodeRegistrationOutcome.php src/Console/NodeRegistrationWriter.php src/Console/MakeNodeCommand.php tests/Unit/NodeRegistrationWriterTest.php tests/Feature/MakeNodeCommandTest.php
git commit -m "feat: register generated nodes in the host provider, or print the snippet"
```

---

## Task 5: `--test`

**Files:**
- Create: `stubs/node.test.stub`
- Modify: `src/Console/MakeNodeCommand.php` — `handle()`, plus a new `writeTest()`
- Test: `tests/Feature/MakeNodeCommandTest.php`

**Interfaces:**
- Consumes: `cardinality()`, `nodeType()`, `outputNames()`, `handle()` from Tasks 1–4.
- Produces: `--test` writes `base_path('tests/Feature/Nodeflow/{Class}NodeTest.php')`. Nothing later in this plan consumes it.

The generated test asserts only what needs no database, because it lands in the host's suite where
`TestCase` is theirs, not ours. Four properties are worth asserting and all four are real: the type
string, the declared outputs, the cardinality interface, and that `NodeRegistry` accepts the class.
That last one is the load-bearing check — it is what catches a node declaring `forSubject()` without
`implements HandlesSubject`, which otherwise registers, validates, publishes, starts a run, and
throws on the first subject.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/MakeNodeCommandTest.php`:

```php
it('generates no test unless asked', function () {
    $this->artisan('nodeflow:make-node', ['name' => 'SendSms', '--type' => 'yaya.send_sms'])
        ->assertExitCode(0);

    expect($this->root.'/tests/Feature/Nodeflow/SendSmsNodeTest.php')->not->toBeFile();
});

it('generates a test whose expectations match the node it generated', function () {
    // The counterfactual, and the reason this assertion is shaped this way: a
    // test stub that hard-codes ['default'] passes a "file exists" check while
    // asserting the wrong outputs. Non-default outputs are used so drift
    // between the two stubs is detectable at all.
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendSms',
        '--type' => 'yaya.send_sms',
        '--outputs' => 'sent, failed',
        '--test' => true,
    ])->assertExitCode(0);

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendSmsNodeTest.php');
    $node = file_get_contents($this->root.'/app/Nodeflow/Nodes/SendSms.php');

    expect($test)
        ->toContain('use App\Nodeflow\Nodes\SendSms;')
        ->toContain("expect(SendSms::type())->toBe('yaya.send_sms');")
        ->toContain("->toBe(['sent', 'failed'])")
        ->toContain('HandlesSubject::class');

    // Both files must name the same output list, or the generated test asserts
    // something the generated node does not do.
    expect($node)->toContain("['sent', 'failed']");
});

it('asserts the audience interface for an audience node', function () {
    $this->artisan('nodeflow:make-node', [
        'name' => 'SendBatch',
        '--type' => 'yaya.send_batch',
        '--cardinality' => 'audience',
        '--test' => true,
    ])->assertExitCode(0);

    $test = file_get_contents($this->root.'/tests/Feature/Nodeflow/SendBatchNodeTest.php');

    expect($test)
        ->toContain('HandlesAudience::class')
        ->not->toContain('HandlesSubject');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='generates a test whose expectations match'`

Expected: FAIL. The test file does not exist, because `--test` does nothing yet.

- [ ] **Step 3: Write the test stub**

Create `stubs/node.test.stub`:

```php
<?php

use {{ namespacedClass }};
use Nodeflow\Nodes\NodeRegistry;
{{ cardinalityImports }}

it('keeps its type stable', function () {
    // Published flow versions are immutable and runs sitting mid-wait resolve
    // through this string. If this test ever fails because someone changed
    // type(), the fix is to change it back and add a NodeRegistry::alias().
    expect({{ class }}::type())->toBe('{{ type }}');
});

it('declares the outputs its flow edges can use', function () {
    // GraphValidator rejects an edge naming an output the node does not declare,
    // so removing one here breaks every published flow that routed through it.
    expect((new {{ class }})->definition()->outputNames())->toBe([{{ outputs }}]);
});

it('is executable by the runtime', function () {
    // NodeRunner dispatches on the interface, never on the method name. A node
    // with the method but not the interface registers, validates, publishes and
    // starts a run — then throws on the first subject that reaches it.
{{ cardinalityExpectations }}

    app(NodeRegistry::class)->register({{ class }}::class);

    expect(app(NodeRegistry::class)->has('{{ type }}'))->toBeTrue();
});

it('rejects a config missing a required field', function () {
    // TODO: update the key when you replace the scaffolded 'example' field.
    expect((new {{ class }})->validate([]))->toHaveKey('example');
});

// TODO: add a test per output. Build the context your cardinality receives,
// invoke the node, and assert which output the subject or audience landed in —
// that is the behaviour the four tests above deliberately do not cover.
```

- [ ] **Step 4: Generate the test from the command**

In `src/Console/MakeNodeCommand.php`, add these two methods:

```php
    /**
     * The generated test asserts only what needs no database, because it lands in
     * the host's suite where the base TestCase is theirs. The four properties it
     * does assert are the ones that break silently: the type string, the declared
     * outputs, the cardinality interface, and that the registry accepts the class.
     */
    private function writeTest(string $nodeClass): void
    {
        $directory = $this->laravel->basePath('tests/Feature/Nodeflow');

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0777, true, true);
        }

        $class = class_basename($nodeClass);
        $path = $directory.'/'.$class.'NodeTest.php';

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("Test already exists at {$path}; left untouched.");

            return;
        }

        $outputs = $this->outputNames();

        [$imports, $expectations] = match ($this->cardinality()) {
            'audience' => [
                'use Nodeflow\Nodes\HandlesAudience;',
                '    expect(new '.$class.')->toBeInstanceOf(HandlesAudience::class);',
            ],
            'both' => [
                "use Nodeflow\Nodes\HandlesAudience;\nuse Nodeflow\Nodes\HandlesSubject;",
                '    expect(new '.$class.')->toBeInstanceOf(HandlesSubject::class)'.PHP_EOL
                    .'        ->toBeInstanceOf(HandlesAudience::class);',
            ],
            default => [
                'use Nodeflow\Nodes\HandlesSubject;',
                '    expect(new '.$class.')->toBeInstanceOf(HandlesSubject::class);',
            ],
        };

        $this->files->put($path, str_replace(
            [
                '{{ namespacedClass }}',
                '{{ cardinalityImports }}',
                '{{ cardinalityExpectations }}',
                '{{ class }}',
                '{{ type }}',
                '{{ outputs }}',
            ],
            [
                $nodeClass,
                $imports,
                $expectations,
                $class,
                $this->nodeType(),
                implode(', ', array_map(fn (string $o) => "'{$o}'", $outputs)),
            ],
            $this->files->get($this->resolveStubPath('/stubs/node.test.stub')),
        ));

        $this->components->info("Test [{$path}] created successfully.");
    }
```

Then, in `handle()`, insert the `--test` branch immediately after the `registerNode(...)` call:

```php
        $this->registerNode($this->qualifyClass($this->getNameInput()));

        if ($this->option('test')) {
            $this->writeTest($this->qualifyClass($this->getNameInput()));
        }

        return $generated;
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='MakeNodeCommand'`

Expected: PASS, 14 tests.

- [ ] **Step 6: Assert the generated PHP actually parses**

Every assertion so far is a substring check, and a stub that renders syntactically invalid PHP passes
all of them. This is the check that catches a broken stub — so it belongs in the suite permanently,
not in a manual step. Append to `tests/Feature/MakeNodeCommandTest.php`:

```php
it('generates syntactically valid PHP for every cardinality', function (string $cardinality) {
    // The counterfactual: leave an unbalanced brace or a stray {{ placeholder }}
    // in any stub and this fails, while every substring assertion above still
    // passes. Four stubs render PHP; nothing else verifies that it parses.
    $class = 'Send'.ucfirst($cardinality);

    $this->artisan('nodeflow:make-node', [
        'name' => $class,
        '--type' => 'yaya.send_'.$cardinality,
        '--cardinality' => $cardinality,
        '--outputs' => 'sent, failed',
        '--test' => true,
    ])->assertExitCode(0);

    $paths = [
        $this->root.'/app/Nodeflow/Nodes/'.$class.'.php',
        $this->root.'/tests/Feature/Nodeflow/'.$class.'NodeTest.php',
    ];

    foreach ($paths as $path) {
        expect($path)->toBeFile();

        exec('php -l '.escapeshellarg($path).' 2>&1', $output, $exitCode);

        expect($exitCode)->toBe(0, "php -l failed for {$path}: ".implode(PHP_EOL, $output));
    }

    // No placeholder survived rendering in either file.
    foreach ($paths as $path) {
        expect(file_get_contents($path))->not->toContain('{{');
    }
})->with(['subject', 'audience', 'both']);
```

Run: `vendor/bin/pest --filter='syntactically valid PHP'`

Expected: PASS, 3 datasets.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS. The pre-existing 166 tests plus 23 new ones (17 in `MakeNodeCommandTest`, counting
the three-dataset lint test as three, and 6 in `NodeRegistrationWriterTest`).

- [ ] **Step 8: Commit**

```bash
git add stubs/node.test.stub src/Console/MakeNodeCommand.php tests/Feature/MakeNodeCommandTest.php
git commit -m "feat: add --test to nodeflow:make-node"
```

---

## Task 6: Document the generator

**Files:**
- Modify: `docs/03-writing-nodes.md:1-6`

**Interfaces:**
- Consumes: the finished command from Tasks 1–5.
- Produces: nothing consumed by later code.

- [ ] **Step 1: Read the current opening**

Run: `sed -n '1,10p' docs/03-writing-nodes.md`

Confirm the file starts with `# 3. Writing nodes` followed by introductory prose and then
`## A complete node`. The new section goes between the introduction and `## A complete node`, so a
reader meets the generator before the hand-written example.

- [ ] **Step 2: Insert the section**

Insert immediately before the `## A complete node` line:

```markdown
## Start from the generator

```bash
php artisan nodeflow:make-node SendSms \
    --type=yaya.send_sms \
    --cardinality=subject \
    --outputs='sent, failed' \
    --group=Messaging \
    --test
```

That writes one file, `app/Nodeflow/Nodes/SendSms.php`, and — with `--test` —
`tests/Feature/Nodeflow/SendSmsNodeTest.php`. One class plus one declarative definition is the whole
node; if you find yourself creating a directory, something has gone wrong.

| Option | Meaning |
|---|---|
| `--type` | The stable identifier. Prompted if omitted. Published flow versions resolve through it forever, so it must not change once a flow is live, and the `core.` prefix is reserved for the nodes this package ships |
| `--cardinality` | `subject` (default), `audience`, or `both`. See [Cardinality and partitioning](#cardinality-and-partitioning) — the interface is what the runtime dispatches on, so the generator always writes it for you |
| `--outputs` | Comma-separated output names, rendered into `definition()` and into the generated test |
| `--group` | The palette group the editor shows the node under |
| `--test` | Also generate a Pest test asserting the type, the outputs, the cardinality interface, and that the registry accepts the class |

The command registers the node in `app/Providers/NodeflowServiceProvider.php` when it can find the
`protected array $nodes = [` line that `php artisan nodeflow:install` writes. When it cannot, it
prints the `Nodeflow::register([...])` line for you to paste and says why — registration is explicit
in this package, so an unregistered node never reaches the palette.

It refuses, rather than generating something broken, when the type is malformed, uses the reserved
`core.` prefix, or is already registered by another node. That last case matters: the registry keys
by type, so two nodes sharing one type silently replace each other.

```

- [ ] **Step 3: Verify the anchor moved nothing else**

Run: `git diff --stat docs/03-writing-nodes.md`

Expected: one file changed, insertions only, zero deletions. A non-zero deletion count means the
insertion overwrote existing prose — revert and redo.

- [ ] **Step 4: Commit**

```bash
git add docs/03-writing-nodes.md
git commit -m "docs: document nodeflow:make-node in the node authoring guide"
```

---

## Definition of done

- `vendor/bin/pest` passes, with the pre-existing 166 tests still green.
- `php -l` exits 0 on the node and the test generated for all three cardinalities, and no `{{ placeholder }}` survives rendering — asserted by the suite, not by hand.
- `nodeflow:make-node` generates one file per node, and one more only when `--test` is passed.
- A generated node registers with `NodeRegistry` and resolves by its type.
- A malformed, reserved, or already-registered type exits non-zero and writes nothing.
- The provider edit never happens unless its anchor is present exactly once.
- `docs/03-writing-nodes.md` documents the command before it documents hand-writing a node.

## Deliberately not in this plan

Per spec §3 and §7.3, these belong to later plans and must not be built here:

- `nodeflow:install` — Plan 5. Until it exists, the provider is usually absent and the generator's
  snippet-printing path is the normal one.
- `nodeflow:make-trigger`, `nodeflow:make-subject-attribute` — Plan 5.
- `nodeflow:make-node-package`, `nodeflow:extract-node` — Plan 6.
- `make-flow` and `make-field-control` — cut from scope entirely (spec §7.3).
- Any editor, route, controller, tenancy, or authorization work — Plans 2 and 3.
