# Plan 7 Release Readiness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Correct four bounded installer/generator gaps, complete honest real-browser acceptance,
refresh the GitHub README, and leave an evidence-backed documentation handoff for the separate
GitBook session.

**Architecture:** Keep each correction behind its existing command/step boundary. Add one
alias-specific lexical scanner, make optional config a read-only report, bind both generator fallback
snippets to fully qualified registries, and make the shared Vite config resolver follow installed
Vite. Browser acceptance and documentation are downstream gates fed only by measured implementation
evidence.

**Tech Stack:** PHP 8.3+, Laravel 12/13, Orchestra Testbench, Pest 4, Node.js, Vite 8.2.2, Vitest,
TypeScript, Inertia/React, Composer, Chrome CDP, Git.

## Global Constraints

- Binding design: `docs/superpowers/specs/2026-08-22-plan-7-release-readiness-design.md` at
  `dd3d92e`.
- Use local `main`; this repository has no remote. Never invent or pull `origin`.
- At execution time, invoke `superpowers:using-git-worktrees` and create a fresh ignored worktree
  for branch `plan-7-release-readiness`. Do not use, unlock or remove the locked Plan 6 worktree.
- Do not begin implementation until this plan is approved and an execution mode is chosen.
- Strict red-green-refactor TDD: commit each failing counterexample before production code.
- Execute every named counterfactual, record its expected failure, and restore production
  immediately. A surviving mutation blocks the task.
- Request independent spec-compliance and code-quality review after each production task; resolve
  every Critical or Important finding before moving on.
- Preserve all unrelated and concurrent changes. Stop if they overlap a Plan 7 target.
- The demo repository is `/Users/mikelmao/Sites/test-workflow`; never run `migrate:fresh`, reseed,
  reset, or broaden fixture cleanup there.
- Before every demo gate, assert that
  `/Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow` resolves exactly to the package
  `main` checkout. Do not commit a machine-local worktree path.
- `COMPOSER_DISABLE_NETWORK=1` is permitted only for bounded test fixtures, never as a production
  Composer setting.
- Format only changed PHP files. Repository-wide demo Pint drift is a known unrelated baseline.
- Do not edit `docs/02-integration.md`, `docs/08-editor-client.md`,
  `docs/superpowers/open-issues.md`, or the historical Plan 5 design. Put their required changes in
  `docs/documentation-changes.md`.
- Update `README.md` directly, because it is the GitHub landing page.
- Measure test/assertion counts; never pad, trim or preselect a total.
- G-5 passes only through actual browser observation. If Chrome remote debugging or authentication
  is unavailable, finish independent work and record G-5 as blocked.
- D-1, D-2, G-3, C-1 through C-6, G-13, release publication, semantic versioning, new features,
  unrelated refactors and broad formatting are outside this plan.

---

## File Map

### Create

- `src/Console/Install/ViteAliasValue.php` — extracts the one quoted
  `@nodeflow/editor` property's value from comment-stripped Vite source.
- `tests/Support/resolve-vite-config.mjs` — asks the installed Vite resolver which config filename
  it loads for a fixture root.
- `docs/documentation-changes.md` — evidence-backed instructions for the separate documentation
  session.
- `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md` — mutations,
  reviews, browser observations, created demo rows and measured gates.

### Modify

- `src/Console/Install/ViteConfigStep.php` — Vite 8.2.2 candidate order.
- `src/Console/Install/ViteAliasStep.php` — consume `ViteAliasValue` instead of two whole-file
  substrings; correct its stated limit.
- `src/Console/Install/PublishConfigStep.php` — optional read-only config reporter.
- `src/Console/InstallCommand.php` — update the config/write contract comments while retaining the
  reporter in the nine-step list.
- `src/Console/MakeTriggerCommand.php` — fully qualified fallback registry.
- `src/Console/MakeSubjectAttributeCommand.php` — fully qualified fallback registry.
- `tests/Feature/Install/ViteStepsTest.php` — precedence parity, candidate support, and bound alias
  discriminators.
- `tests/Feature/Install/PublishConfigStepTest.php` — absent/present optional semantics.
- `tests/Feature/InstallCommandTest.php` — healthy unpublished config at command level.
- `tests/Feature/MakeTriggerCommandTest.php` — execute captured fallback output without imports.
- `tests/Feature/MakeSubjectAttributeCommandTest.php` — execute captured fallback output without
  imports.
- `README.md` — shipped commands, packaging guide, measured counts and local-worker/CI distinction.

### Explicit non-targets

- No tracked demo file should change.
- No TypeScript/React production file should change.
- Non-README documentation targets are listed in `docs/documentation-changes.md`, not edited.

---

## Phase 0: Isolated Worktree and Baseline

- [ ] **Step 1: Re-verify both starting repositories**

Run:

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow
git branch --show-current
git log --oneline -5
git status --short
git remote -v
git worktree list --porcelain

git -C /Users/mikelmao/Sites/test-workflow branch --show-current
git -C /Users/mikelmao/Sites/test-workflow rev-parse HEAD
git -C /Users/mikelmao/Sites/test-workflow status --short
realpath /Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow
```

Expected: package and demo are on `main`; both statuses are clean; demo HEAD remains `e15e5bd`
unless a separately authorized demo change is explained; no remote exists; the vendor link resolves
to `/Users/mikelmao/Projects/laravel-nodeflow`. The package HEAD must contain this plan and the
approved design. Stop on an unexplained difference.

- [ ] **Step 2: Create the isolated implementation worktree**

Invoke `superpowers:using-git-worktrees`, using:

```text
branch: plan-7-release-readiness
path: /Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-7-release-readiness
base: local main
```

Expected: the new worktree is clean, its branch point contains this plan, and the old
`.claude/worktrees/plan-6-packaging` worktree remains untouched.

- [ ] **Step 3: Measure package baseline in the new worktree**

Run:

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-7-release-readiness
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
```

Expected starting evidence: Pest 904 tests / 7,469 assertions, Vitest 160/160 across 17 files,
silent TypeScript and valid Composer metadata. A documentation-only concurrent commit may change
HEAD but not these counts. Stop on an unexplained test delta.

- [ ] **Step 4: Measure demo baseline without mutating it**

Run:

```bash
cd /Users/mikelmao/Sites/test-workflow
test "$(realpath vendor/atram/laravel-nodeflow)" = "/Users/mikelmao/Projects/laravel-nodeflow"
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
git status --short
```

Expected: 56 Pest tests / 223 assertions, silent TypeScript, passing build, valid Composer metadata
and lock consistency, and a clean demo tree. Do not accept a modified `package-lock.json`.

- [ ] **Step 5: Start the execution record**

Create `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md` with this
initial shape and the actual commit hashes/outputs just measured:

```markdown
# Plan 7 execution record

This records what happened while executing
`docs/superpowers/plans/2026-08-22-plan-7-release-readiness.md`.

## Starting state

- Package branch point and worktree path
- Demo commit and exact vendor-link target
- Package and demo baseline gates

## Counterfactuals

## Reviews and remediation

## Browser acceptance

## Final merged-main verification
```

Do not commit the record alone yet; update it alongside the first completed task.

---

### Task 1: G-12 — Match Installed Vite's Config Precedence

**Files:**

- Create: `tests/Support/resolve-vite-config.mjs`
- Modify: `tests/Feature/Install/ViteStepsTest.php:8-134`
- Modify: `src/Console/Install/ViteConfigStep.php:8-52`
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Produces: `ViteConfigStep::CONFIG_CANDIDATES` in the exact order
  `js, mjs, ts, cjs, mts, cts`.
- Produces: test helper script accepting one fixture-root argument and printing only the basename of
  `ResolvedConfig.configFile`.
- Consumed by: both `ViteAliasStep::configSource()` and `ViteDedupeStep::configSource()`.

- [ ] **Step 1: Add the installed-Vite resolver probe**

Create `tests/Support/resolve-vite-config.mjs`:

```js
import path from 'node:path'
import { resolveConfig } from 'vite'

const root = path.resolve(process.argv[2])
const config = await resolveConfig({ root, logLevel: 'silent' }, 'build')

process.stdout.write(path.basename(config.configFile ?? ''))
```

Add this helper to `tests/Feature/Install/ViteStepsTest.php`:

```php
function viteSelectedConfig(string $root): string
{
    $output = [];
    $command = 'node '
        .escapeshellarg(__DIR__.'/../../Support/resolve-vite-config.mjs').' '
        .escapeshellarg($root).' 2>&1';

    exec($command, $output, $exitCode);

    expect($exitCode)->toBe(0, implode(PHP_EOL, $output));

    return trim(implode(PHP_EOL, $output));
}
```

Update teardown to recursively remove the resolver's generated fixture files/directories, following
the existing recursive fixture cleanup used by the generator test files.

- [ ] **Step 2: Write the failing precedence and candidate tests**

Add a multi-candidate discriminator. The `.js` file is fully wired; `.ts` is deliberately wrong:

```php
it('inspects the same config file Vite loads when candidates coexist', function () {
    file_put_contents($this->root.'/vite.config.js', <<<'JS'
    export default {
        resolve: {
            alias: { '@nodeflow/editor': 'vendor/atram/laravel-nodeflow/resources/js' },
            dedupe: ['react', 'react-dom', '@xyflow/react'],
        },
    }
    JS);

    file_put_contents($this->root.'/vite.config.ts', <<<'TS'
    export default {
        resolve: {
            alias: { '@nodeflow/editor': 'resources/js' },
            dedupe: ['lodash'],
        },
    }
    TS);

    expect(viteSelectedConfig($this->root))->toBe('vite.config.js');
    expect($this->alias->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->dedupe->check())->toBe(InstallOutcome::AlreadyPresent);
});
```

Add dataset cases for `vite.config.cjs` and `vite.config.cts`. Each fixture is the only config,
contains correct alias/dedupe text, is valid for its module format, and is asserted as the file
selected by `viteSelectedConfig()` and accepted by both PHP steps.

- [ ] **Step 3: Run the tests and preserve the red counterexample**

Run:

```bash
vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact
```

Expected: the multi-candidate test fails because PHP reads `.ts`, while installed Vite reports
`.js`; `.cjs` and `.cts` cases fail because PHP currently omits them.

Commit the failing evidence:

```bash
git add tests/Support/resolve-vite-config.mjs tests/Feature/Install/ViteStepsTest.php \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "test: reproduce Vite config precedence gap"
```

- [ ] **Step 4: Implement the exact Vite 8.2.2 order**

Replace the candidate constant with:

```php
public const CONFIG_CANDIDATES = [
    'vite.config.js',
    'vite.config.mjs',
    'vite.config.ts',
    'vite.config.cjs',
    'vite.config.mts',
    'vite.config.cts',
];
```

Update the class docblock to say the order mirrors installed Vite 8.2.2 and is shared so alias and
dedupe cannot drift.

- [ ] **Step 5: Verify green and execute the counterfactuals**

Run:

```bash
vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact
```

Expected: all Vite step tests pass.

Counterfactual A: temporarily move `.ts` before `.js`; rerun the multi-candidate test. Expected:
FAIL because PHP accepts the wrong `.ts` fixture while Vite selects `.js`.

Counterfactual B: restore order, temporarily remove `.cjs`, run its dataset case. Expected: FAIL
with `CannotWire`. Repeat for `.cts`. Restore the complete constant immediately and rerun the full
file green. Record all three failures in the execution record.

- [ ] **Step 6: Review and commit G-12**

Request independent spec-compliance and code-quality review for Task 1. Resolve Critical/Important
findings and rerun the test file.

```bash
git add src/Console/Install/ViteConfigStep.php \
  tests/Feature/Install/ViteStepsTest.php tests/Support/resolve-vite-config.mjs \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "fix: follow Vite config precedence"
```

---

### Task 2: G-7 — Bind the Package Path to the Alias Entry

**Files:**

- Create: `src/Console/Install/ViteAliasValue.php`
- Modify: `src/Console/Install/ViteAliasStep.php:5-43`
- Modify: `tests/Feature/Install/ViteStepsTest.php:43-80`
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Produces: `ViteAliasValue::extract(string $source): ?string`.
- Contract: return the sole quoted `@nodeflow/editor` property value span; return `null` for zero,
  duplicate or malformed entries.
- Consumes: comment-stripped source returned by `ViteConfigStep::configSource()`.

- [ ] **Step 1: Persist the proven false accept**

Add:

```php
it('does not combine a wrong alias with the package path elsewhere in the file', function () {
    ($this->write)(<<<'TS'
    export default defineConfig({
        resolve: {
            alias: {
                '@nodeflow/editor': path.resolve(__dirname, 'resources/js'),
            },
        },
    })

    const documentationPath = 'vendor/atram/laravel-nodeflow/resources/js'
    TS);

    expect($this->alias->check())->toBe(InstallOutcome::CannotWire);
});
```

Also add cases proving:

- single- and double-quoted keys/paths remain accepted;
- a nested `path.resolve(__dirname, 'vendor/...')` value is scanned through its inner comma;
- a commented correct entry is still rejected;
- two live alias keys are rejected conservatively; and
- a correct package path in another property's nested object cannot rescue a wrong alias.

- [ ] **Step 2: Run red and commit the discriminator**

Run:

```bash
vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --filter="does not combine" --compact
```

Expected: FAIL; current `check()` returns `AlreadyPresent`.

Then run the full file to identify any test-fixture confounds. Commit only once the new failure is
the intended G-7 failure:

```bash
git add tests/Feature/Install/ViteStepsTest.php
git commit -m "test: reproduce unbound Vite alias facts"
```

- [ ] **Step 3: Implement the bounded alias-value scanner**

Create this interface and helper decomposition:

```php
<?php

namespace Nodeflow\Console\Install;

final class ViteAliasValue
{
    private const KEY = '@nodeflow/editor';

    public static function extract(string $source): ?string
    {
        $values = [];
        $length = strlen($source);
        $offset = 0;

        while ($offset < $length) {
            if (! in_array($source[$offset], ["'", '"', '`'], true)) {
                $offset++;

                continue;
            }

            $quoted = self::quotedAt($source, $offset);

            if ($quoted === null) {
                return null;
            }

            $before = self::previousSignificant($source, $offset - 1);
            $colon = self::nextSignificant($source, $quoted['next']);

            if (
                $quoted['value'] === self::KEY
                && in_array($before, ['{', ','], true)
                && ($source[$colon] ?? null) === ':'
            ) {
                $start = self::nextSignificant($source, $colon + 1);
                $value = self::valueAt($source, $start);

                if ($value === null) {
                    return null;
                }

                $values[] = trim($value['value']);
                $offset = $value['next'];

                continue;
            }

            $offset = $quoted['next'];
        }

        return count($values) === 1 ? $values[0] : null;
    }

    /** @return array{value: string, next: int}|null */
    private static function quotedAt(string $source, int $offset): ?array
    {
        $quote = $source[$offset] ?? null;

        if (! in_array($quote, ["'", '"', '`'], true)) {
            return null;
        }

        $value = '';
        $length = strlen($source);

        for ($i = $offset + 1; $i < $length; $i++) {
            if ($source[$i] === '\\') {
                if ($i + 1 >= $length) {
                    return null;
                }

                $value .= $source[$i].$source[$i + 1];
                $i++;

                continue;
            }

            if ($source[$i] === $quote) {
                return ['value' => $value, 'next' => $i + 1];
            }

            $value .= $source[$i];
        }

        return null;
    }

    /** @return array{value: string, next: int}|null */
    private static function valueAt(string $source, int $offset): ?array
    {
        $depth = ['(' => 0, '[' => 0, '{' => 0];
        $closing = [')' => '(', ']' => '[', '}' => '{'];
        $length = strlen($source);

        for ($i = $offset; $i < $length; $i++) {
            $char = $source[$i];

            if (in_array($char, ["'", '"', '`'], true)) {
                $quoted = self::quotedAt($source, $i);

                if ($quoted === null) {
                    return null;
                }

                $i = $quoted['next'] - 1;

                continue;
            }

            if (isset($depth[$char])) {
                $depth[$char]++;

                continue;
            }

            if (isset($closing[$char])) {
                $open = $closing[$char];

                if ($char === '}' && $depth[$open] === 0 && self::allZero($depth)) {
                    return ['value' => substr($source, $offset, $i - $offset), 'next' => $i];
                }

                if ($depth[$open] === 0) {
                    return null;
                }

                $depth[$open]--;

                continue;
            }

            if ($char === ',' && self::allZero($depth)) {
                return ['value' => substr($source, $offset, $i - $offset), 'next' => $i + 1];
            }
        }

        return self::allZero($depth)
            ? ['value' => substr($source, $offset), 'next' => $length]
            : null;
    }

    /** @param array<string, int> $depth */
    private static function allZero(array $depth): bool
    {
        return array_sum($depth) === 0;
    }

    private static function nextSignificant(string $source, int $offset): int
    {
        $length = strlen($source);

        while ($offset < $length && ctype_space($source[$offset])) {
            $offset++;
        }

        return $offset;
    }

    private static function previousSignificant(string $source, int $offset): ?string
    {
        while ($offset >= 0 && ctype_space($source[$offset])) {
            $offset--;
        }

        return $offset >= 0 ? $source[$offset] : null;
    }
}
```

The implementation rules are exact:

1. Scan left-to-right; only `'`, `"` and backtick tokens can name the key.
2. A matching token is a property only when the preceding non-whitespace character is `{` or `,`
   and the following non-whitespace character is `:`.
3. `quotedAt()` consumes backslash plus its following character together and returns `null` on an
   unterminated token.
4. `valueAt()` tracks separate depths for `()`, `[]` and `{}`. A comma ends the value only when all
   are zero. An enclosing `}` ends it when object depth is zero.
5. Any unmatched closing delimiter or unterminated string returns `null`.
6. Zero or more than one matching property returns `null`.

Update `ViteAliasStep::check()`:

```php
$value = ViteAliasValue::extract($source);

return $value !== null && str_contains($value, self::PACKAGE_SOURCE)
    ? InstallOutcome::AlreadyPresent
    : InstallOutcome::CannotWire;
```

Correct its `KNOWN LIMIT`: the check binds the path to one uncommented alias property but still
cannot prove that property's object is the actively exported Vite configuration.

- [ ] **Step 4: Verify green and kill the defining mutation**

Run:

```bash
vendor/bin/pest tests/Feature/Install/ViteStepsTest.php --compact
php -l src/Console/Install/ViteAliasValue.php
php -l src/Console/Install/ViteAliasStep.php
```

Expected: all pass.

Counterfactual: temporarily replace the value-bound condition with the original two whole-file
`str_contains()` calls. Run the G-7 discriminator. Expected: FAIL because it returns
`AlreadyPresent`. Restore `ViteAliasValue::extract()` immediately and rerun the full file. Record the
failure and the accepted lexical cases in the execution record.

- [ ] **Step 5: Review and commit G-7**

Request independent spec-compliance and code-quality review. The reviewer must construct at least
one additional wrong-entry/correct-elsewhere input and one delimiter-in-string input. Resolve all
Critical/Important findings.

```bash
git add src/Console/Install/ViteAliasValue.php src/Console/Install/ViteAliasStep.php \
  tests/Feature/Install/ViteStepsTest.php \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "fix: bind Vite alias to its package path"
```

---

### Task 3: G-8 — Make Published Config Optional

**Files:**

- Modify: `src/Console/Install/PublishConfigStep.php:7-54`
- Modify: `src/Console/InstallCommand.php:20-42,94-121`
- Modify: `tests/Feature/Install/PublishConfigStepTest.php:23-39`
- Modify: `tests/Feature/InstallCommandTest.php:73-124`
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Preserves: `PublishConfigStep implements InstallStep` and its position in the report.
- Changes: `check()` and `apply()` always return `InstallOutcome::AlreadyPresent` and never write.
- Preserves: `vendor:publish --tag=nodeflow-config` from `NodeflowServiceProvider` as the sole
  explicit publication path.

- [ ] **Step 1: Rewrite the focused tests to the approved contract**

Replace the publish-on-absence test with:

```php
it('reports healthy and writes nothing when config is intentionally unpublished', function () {
    expect($this->path)->not->toBeFile();
    expect($this->step->check())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->step->apply())->toBe(InstallOutcome::AlreadyPresent);
    expect($this->path)->not->toBeFile();
});
```

Strengthen the existing customized-config test by snapshotting exact bytes before both calls and
asserting byte identity afterward. Assert that `describe()` contains `optional`.

In `InstallCommandTest`:

- change the fully-wireable-host test to assert `config/nodeflow.php` does **not** exist after
  normal install;
- remove config from the idempotency byte snapshot;
- add a named test that runs normal install, confirms every other step is wired, confirms config is
  absent, then runs `nodeflow:install --check` and expects exit 0; and
- preserve the existing migration tests unchanged.

- [ ] **Step 2: Run red and commit the new contract**

Run:

```bash
vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php \
  tests/Feature/InstallCommandTest.php --compact
```

Expected: focused config tests fail because absence is `Writable` and normal install creates the
file. Migration assertions remain green.

```bash
git add tests/Feature/Install/PublishConfigStepTest.php tests/Feature/InstallCommandTest.php
git commit -m "test: require optional merged config semantics"
```

- [ ] **Step 3: Make the config step a read-only reporter**

Replace the behavior with:

```php
public function describe(): string
{
    return 'Config (optional; package defaults are merged)';
}

public function check(): InstallOutcome
{
    return InstallOutcome::AlreadyPresent;
}

public function apply(): InstallOutcome
{
    return $this->check();
}
```

Remove the unused private `path()` method and update the class docblock to state:

- an absent file uses merged package defaults;
- a present file is host-owned customization, not drift; and
- explicit publication is `php artisan vendor:publish --tag=nodeflow-config`.

Retain the constructor signature for the existing step-construction contract even though the
filesystem and base path are no longer read; name the promoted fields only if used, otherwise accept
ordinary constructor parameters without storing them. Update `InstallCommand` comments so normal
install is described as three default writes, one opt-in migration writer, four verifiers and one
optional-config report.

- [ ] **Step 4: Verify green and kill the G-8 mutation**

Run:

```bash
vendor/bin/pest tests/Feature/Install/PublishConfigStepTest.php \
  tests/Feature/Install/MigrationStepTest.php \
  tests/Feature/InstallCommandTest.php --compact
php -l src/Console/Install/PublishConfigStep.php
php -l src/Console/InstallCommand.php
```

Expected: all pass; the command-level unpublished host exits 0 under `--check`.

Counterfactual: temporarily return `InstallOutcome::Writable` from the absent-config path and restore
the old copy-on-apply code. Expected: the focused absence test fails, normal install creates the file,
and the command-level `--check` test exits 1. Restore read-only behavior immediately and rerun all
three files. Record the exact failures.

- [ ] **Step 5: Review and commit G-8**

Request independent spec-compliance and code-quality review. The reviewer must verify the
`nodeflow-config` publish mapping still exists in `NodeflowServiceProvider` and migration drift
semantics did not change.

```bash
git add src/Console/Install/PublishConfigStep.php src/Console/InstallCommand.php \
  tests/Feature/Install/PublishConfigStepTest.php tests/Feature/InstallCommandTest.php \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "fix: make published config optional"
```

---

### Task 4: G-9 — Make Both Fallback Snippets Import-Free

**Files:**

- Modify: `tests/Feature/MakeTriggerCommandTest.php:143-153`
- Modify: `tests/Feature/MakeSubjectAttributeCommandTest.php:162-168`
- Modify: `src/Console/MakeTriggerCommand.php:111-121`
- Modify: `src/Console/MakeSubjectAttributeCommand.php:121-129`
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Produces trigger fallback rooted at `\Nodeflow\Triggers\TriggerRegistry::class`.
- Produces attribute fallback rooted at
  `\Nodeflow\Schema\SubjectAttributeRegistry::class`.
- Preserves generated class/entry FQCNs and command exit behavior.

- [ ] **Step 1: Add an output-to-provider execution helper in each test file**

Use `Artisan::call()` rather than `$this->artisan()` so the real buffered output is available:

```php
$exitCode = \Illuminate\Support\Facades\Artisan::call('nodeflow:make-trigger', [
    'name' => 'ManualTrigger',
    '--event' => MakeTriggerTestEvent::class,
    '--type' => 'shop.manual',
]);
$output = \Illuminate\Support\Facades\Artisan::output();
```

Add a distinct helper name in each test file so Pest's shared process does not redeclare a global
function. The trigger version is:

```php
function triggerManualRegistrationSnippet(string $output): string
{
    $lines = preg_split('/\R/', $output) ?: [];
    $start = null;

    foreach ($lines as $index => $line) {
        if (str_contains($line, 'app(') && str_contains($line, 'TriggerRegistry::class')) {
            $start = $index;

            break;
        }
    }

    if ($start === null) {
        return '';
    }

    for ($end = $start; $end < count($lines); $end++) {
        if (trim($lines[$end]) === ');') {
            return implode(PHP_EOL, array_slice($lines, $start, $end - $start + 1));
        }
    }

    return '';
}
```

The attribute file uses the same body under the name
`attributeManualRegistrationSnippet()` and searches for `SubjectAttributeRegistry::class`.

Fail explicitly when the returned snippet is empty, then embed that exact captured block in a
provider probe:

```php
$snippet = triggerManualRegistrationSnippet($output);
expect($snippet)->not->toBe('');

$probePath = $this->root.'/app/Providers/ManualRegistrationProbe.php';
file_put_contents(
    $probePath,
    "<?php\n\nnamespace App\\Providers;\n\n"
    ."final class ManualRegistrationProbe\n{\n"
    ."    public static function run(): void\n    {\n"
    .$snippet."\n    }\n}\n",
);
```

For the trigger test, require the generated trigger and probe, invoke
`App\Providers\ManualRegistrationProbe::run()`, then assert:

```php
expect(app(TriggerRegistry::class)->has('shop.manual'))->toBeTrue();
```

Use a distinct probe class in the attribute test. Capture its command output, embed and execute it,
then assert:

```php
expect(app(SubjectAttributeRegistry::class)->has('manual_plan'))->toBeTrue();
```

Both probe files must pass `expectParseablePhp()` before `require`. Neither probe declares a
registry `use` import.

- [ ] **Step 2: Run red and commit both counterexamples**

Run:

```bash
vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php \
  tests/Feature/MakeSubjectAttributeCommandTest.php --filter="prints the line" --compact
```

Expected: both execution tests fail because PHP resolves the short registry class constants as
`App\Providers\TriggerRegistry` and `App\Providers\SubjectAttributeRegistry`.

```bash
git add tests/Feature/MakeTriggerCommandTest.php \
  tests/Feature/MakeSubjectAttributeCommandTest.php
git commit -m "test: execute generator fallback snippets without imports"
```

- [ ] **Step 3: Fully qualify both registry names**

Change the trigger line to:

```php
$this->line('    app(\Nodeflow\Triggers\TriggerRegistry::class)->register(');
```

Change the attribute line to:

```php
$this->line('    app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(');
```

Do not alter the already fully qualified generated trigger class or subject-attribute entry.

- [ ] **Step 4: Verify green and kill both mutations separately**

Run:

```bash
vendor/bin/pest tests/Feature/MakeTriggerCommandTest.php \
  tests/Feature/MakeSubjectAttributeCommandTest.php --compact
php -l src/Console/MakeTriggerCommand.php
php -l src/Console/MakeSubjectAttributeCommand.php
```

Expected: both complete files pass.

Counterfactual A: restore only the trigger registry's short name and run the trigger execution test.
Expected: failure resolving/binding `App\Providers\TriggerRegistry`. Restore immediately.

Counterfactual B: restore only the attribute registry's short name and run the attribute execution
test. Expected: failure resolving/binding `App\Providers\SubjectAttributeRegistry`. Restore
immediately. Rerun both full files and record the failures.

- [ ] **Step 5: Review and commit G-9**

Request independent spec-compliance and code-quality review. The reviewer must confirm the tests
execute captured output rather than reconstructing the expected fixed snippet.

```bash
git add src/Console/MakeTriggerCommand.php src/Console/MakeSubjectAttributeCommand.php \
  tests/Feature/MakeTriggerCommandTest.php tests/Feature/MakeSubjectAttributeCommandTest.php \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "fix: qualify generator fallback registries"
```

---

### Task 5: Integrated Tooling Gate and Adversarial Review

**Files:**

- Modify only if findings require it: Task 1-4 production/tests
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Consumes all four production corrections.
- Produces a tooling branch with no unresolved Critical/Important review findings.

- [ ] **Step 1: Run the complete focused surface together**

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest \
  tests/Feature/Install/ViteStepsTest.php \
  tests/Feature/Install/PublishConfigStepTest.php \
  tests/Feature/Install/MigrationStepTest.php \
  tests/Feature/InstallCommandTest.php \
  tests/Feature/MakeTriggerCommandTest.php \
  tests/Feature/MakeSubjectAttributeCommandTest.php --compact
```

Expected: all pass. Record measured tests/assertions.

- [ ] **Step 2: Run static/style gates on changed production files**

```bash
php -l src/Console/Install/ViteAliasValue.php
php -l src/Console/Install/ViteAliasStep.php
php -l src/Console/Install/ViteConfigStep.php
php -l src/Console/Install/PublishConfigStep.php
php -l src/Console/InstallCommand.php
php -l src/Console/MakeTriggerCommand.php
php -l src/Console/MakeSubjectAttributeCommand.php
vendor/bin/pint --test \
  src/Console/Install/ViteAliasValue.php \
  src/Console/Install/ViteAliasStep.php \
  src/Console/Install/ViteConfigStep.php \
  src/Console/Install/PublishConfigStep.php \
  src/Console/InstallCommand.php \
  src/Console/MakeTriggerCommand.php \
  src/Console/MakeSubjectAttributeCommand.php \
  tests/Feature/Install/ViteStepsTest.php \
  tests/Feature/Install/PublishConfigStepTest.php \
  tests/Feature/InstallCommandTest.php \
  tests/Feature/MakeTriggerCommandTest.php \
  tests/Feature/MakeSubjectAttributeCommandTest.php
git diff --check
```

Expected: clean.

- [ ] **Step 3: Request one cross-gap adversarial review**

Use `superpowers:requesting-code-review`. Give the reviewer the approved design, branch-point commit,
all Task 1-4 commits, and these mandatory probes:

1. a wrong alias plus the right path in a nested unrelated value;
2. duplicate alias keys where only one is correct;
3. missing and customized config under normal install and `--check`;
4. both fallback snippets in an import-free namespace; and
5. `.js`/`.ts` coexistence plus `.cjs`/`.cts` only.

The reviewer must also check that no GitBook/open-issues/historical-spec file changed.

- [ ] **Step 4: Resolve review findings with TDD**

For every Critical/Important finding, add a failing input first, run it red, implement the minimal
fix, rerun green, and execute a counterfactual. Record each finding and disposition. If there are no
findings, record explicit PASS.

Commit remediation only if required:

```bash
git add src tests docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "fix: close Plan 7 tooling review findings"
```

Never stage a non-target documentation file through broad `git add` if concurrent work appears.

---

### Task 6: G-5 Real-Browser Acceptance

**Files:**

- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`
- Ignored evidence: `.superpowers/sdd/2026-08-22-plan-7-release-readiness/browser/`
- Demo database: one new Fast demo run and its naturally created related rows; no tracked files

**Interfaces:**

- Consumes: real demo at `http://test-workflow.test/`, compiled host assets, existing authenticated
  account, Chrome CDP and a real database queue worker.
- Produces: honest `G-5 passed` or `G-5 blocked`, with URLs, console/network observations, row IDs,
  screenshots and worker cleanup.

- [ ] **Step 1: Prove the gate starts from clean tracked state**

```bash
git -C /Users/mikelmao/Projects/laravel-nodeflow status --short
git -C /Users/mikelmao/Sites/test-workflow status --short
test "$(realpath /Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow)" \
  = "/Users/mikelmao/Projects/laravel-nodeflow"
curl -I http://test-workflow.test/
```

Expected: both statuses clean, exact main link, and the site responds. Stop on tracked drift.

- [ ] **Step 2: Snapshot candidate users and row high-water marks read-only**

From the demo, use `php artisan tinker --execute` to print only IDs, names, tenant IDs and the two
acceptance timestamps for users satisfying both `whereNull` predicates. Do not print passwords,
passkey data, tokens or session contents.

Also record `max(id)` and counts for:

```text
nodeflow_runs
nodeflow_run_subjects
nodeflow_node_executions
demo_messages
jobs
```

Select two users in one tenant with both timestamps null. If fewer than two exist, stop and ask the
user before creating any fixture row.

- [ ] **Step 3: Start the controlled queue worker**

Start this as a retained PTY/session, not a detached `&` process:

```bash
cd /Users/mikelmao/Sites/test-workflow
php artisan queue:work --sleep=1 --tries=1 --timeout=120
```

Record the session/process identifier and initial output. Do not start a second worker if an existing
demo worker is found; identify and report it first.

- [ ] **Step 4: Launch Chrome and stop at the user-controlled gate**

Start Chrome as another retained process:

```bash
/Applications/Google\ Chrome.app/Contents/MacOS/Google\ Chrome \
  --remote-debugging-port=9222 \
  --user-data-dir=/tmp/nodeflow-chrome
```

Ask the user to visit `chrome://inspect/#remote-debugging` in that window, enable **Allow remote
debugging**, and authenticate manually with their existing demo account. Never request or capture
their password/passkey.

Verify:

```bash
curl 'http://[::1]:9222/json/version'
```

Expected: JSON including the Chrome version and WebSocket debugger URL. IPv4 failure is not a
blocker; this Chrome binds IPv6 loopback. If the toggle/authentication is unavailable, mark G-5
blocked, stop the controlled processes, and continue with Task 7.

- [ ] **Step 5: Exercise the compiled editor and create one acceptance run**

Using the browser/CDP harness with console and network capture enabled:

1. Open `http://test-workflow.test/nodeflow` and confirm the authenticated tenant/flows render.
2. Open **Fast demo (seconds)** through **open editor**.
3. Confirm the document title begins `Edit Fast demo`, `FlowEditor` renders the ten-node canvas, and
   the configuration surface is visible.
4. Return to `/nodeflow`, click **run** for Fast demo, select the newest run, and record its run ID
   and subject IDs.

Expected network: compiled JS/CSS assets and the two page requests succeed; no blank/unstyled canvas,
console error, invalid-hook-call message or failed request.

- [ ] **Step 6: Exercise both reshaped action URLs**

For the first selected clean subject, click **clicked**. Record the exact POST:

```text
/nodeflow/runs/{new-run-id}/subjects/{first-run-subject-id}/click
```

For the second, click **convert (exit)** and record:

```text
/nodeflow/runs/{new-run-id}/subjects/{second-run-subject-id}/convert
```

Expected for each: POST returns a successful Laravel/Inertia redirect (`302` or `303`, recorded
exactly), the follow-up GET is 200, and no request uses `/nodeflow/subjects/{subject}/...`. The
converted subject visibly becomes `exited` and loses both buttons.

- [ ] **Step 7: Observe worker progression and the real run view**

Wait up to 90 seconds for the Fast demo run to become terminal. Expected:

- the clicked clean user's `plan` changes from `basic` to `plus`, demonstrating `clicked → yes →
  upgrade`;
- the converted run subject remains `exited`, with no later message for that run/user; and
- the run reaches terminal status with queue work drained.

Open **run view** for the same run. Confirm title `Run #{id} — Fast demo (seconds)`, the pinned
ten-node graph, status and node badges render through `FlowRun`.

- [ ] **Step 8: Prove logout closes the experience**

Navigate to `/dashboard`, open the user menu, activate **Log out**, then navigate directly to
`/nodeflow`.

Expected: redirect to `/login`; no tenant, flow, run or subject content remains accessible. Keep
console/network capture active through this step.

- [ ] **Step 9: Capture database and browser evidence**

Repeat the read-only high-water queries and record every new row ID/count above the Step 2 marks.
Query only the two chosen users and the new run to record:

- first user's clicked timestamp and final `plan`;
- second user's confirmed-interest timestamp and exited run-subject state;
- new run status, subjects, node executions and demo messages; and
- remaining jobs count.

Save screenshots under the ignored browser evidence directory for editor, post-action demo, run view
and login page. Record zero/non-zero console errors, unhandled rejections and failed requests exactly;
any non-zero item is investigated and prevents G-5 PASS.

- [ ] **Step 10: Stop controlled processes and record the result**

Send an interrupt to the retained worker session, wait for exit, then confirm no Plan 7 worker
remains. Stop only the Chrome process/session launched by this task; do not use a broad `killall`.
Record remaining queued jobs and whether `/tmp/nodeflow-chrome` was retained.

Update the execution record with `G-5 passed` only if all observations ran. Otherwise write
`G-5 blocked` with the exact failed gate. Preserve the acceptance run and report its rows; do not
delete or reseed them.

Commit the textual evidence only:

```bash
git add docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "docs: record Plan 7 browser acceptance"
```

---

### Task 7: Measure Acceptance, Update README, and Write the Documentation Handoff

**Files:**

- Modify: `README.md`
- Create: `docs/documentation-changes.md`
- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`

**Interfaces:**

- Consumes: accepted tooling branch, Task 6 browser outcome, actual suite counts and commit hashes.
- Produces: truthful GitHub landing page and a standalone instruction source for the GitBook session.

- [ ] **Step 1: Run the full package gates and capture exact totals**

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-7-release-readiness
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
```

Expected: every gate passes. Record exact Pest tests/assertions and Vitest file/test totals from this
run; do not assume 904/7,469 or 160 remain unchanged.

- [ ] **Step 2: Run demo gates against package main**

```bash
cd /Users/mikelmao/Sites/test-workflow
test "$(realpath vendor/atram/laravel-nodeflow)" = "/Users/mikelmao/Projects/laravel-nodeflow"
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
git diff --check
git status --short
```

Expected: all pass and the demo remains clean. Record exact demo tests/assertions. Do not run broad
Pint.

- [ ] **Step 3: Update README from measured evidence**

Replace the stale status block with prose that says:

- the durable engine, node generator, installer, editor, run view, node-package scaffolder and node
  extractor ship;
- the package is verified by the exact PHP and Vitest totals captured in Step 1;
- the interpreter and browser flow have been exercised locally with a real queue worker when G-5
  passed; and
- real-queue execution is not yet part of CI.

If G-5 is blocked, omit the new browser claim while retaining already-recorded truthful local-worker
evidence from Plans 4 and 6. Replace the install example with `php artisan nodeflow:install` and keep
`php artisan migrate`. Remove “There is no nodeflow:install.” Add this documentation-table row:

```markdown
| [9. Packaging nodes](docs/09-packaging-nodes.md) | Scaffold Composer node packages, ship editor controls, and safely extract existing nodes. |
```

Use only counts printed by Step 1.

- [ ] **Step 4: Write `docs/documentation-changes.md` as a standalone handoff**

Use this complete structure, replacing evidence prose with the actual results already captured—not
future-tense promises:

```markdown
# Nodeflow documentation changes after Plan 7

This file is the implementation handoff for the separate GitBook/documentation session. Plan 7 did
not edit the target files below. Apply each change only when its named evidence is present.

## Measured release-readiness evidence

- Package commit, Pest tests/assertions, Vitest tests/files, TypeScript and Composer results
- Demo commit, Pest tests/assertions, TypeScript, build and Composer results
- G-5 browser result and evidence-record link

## docs/02-integration.md

- Current stale claim: normal `nodeflow:install` publishes `config/nodeflow.php`.
- Required truth: config publication is optional; merged defaults work without a host copy.
- Explicit path: `php artisan vendor:publish --tag=nodeflow-config`.
- Preserve: migration publication remains optional and drift-checked.

## docs/08-editor-client.md

- Remove the statement that `nodeflow:install` is still Plan 5 work.
- Keep the five host-wiring requirements and current run-view limitations.

## docs/superpowers/open-issues.md

- Reconcile Plan 6 to `8b51a3d`, remediation `31e070a`, and 904 / 7,469.
- G-7, G-8, G-9 and G-12 may close only with their implementation commits, killed
  counterfactuals and final green gates.
- G-5 may close only when the execution record says PASS from browser observation; keep GAP when
  blocked.
- G-11 closes only when E20 is actually corrected.
- Keep G-13 as ACCEPTED RESIDUAL.

## docs/superpowers/specs/2026-08-21-remaining-tooling-design.md

- Historical E20 correction: Plan 5's nine steps were five writer-capable steps and four verifiers.
- Do not rewrite Plan 5 execution evidence.
- Separately note the post-Plan-7 installer shape: four writer-capable steps, four verifiers, and
  one optional-config reporter; default execution performs three writes because migrations are
  opt-in.

## Additional exact-search findings

List every remaining public statement that says Plans 5 or 6 are unbuilt, every obsolete test
count, and the required measured replacement. Do not broaden into unrelated prose rewriting.

## Deferred facts that must remain visible

- D-1, D-2 and G-3 security hardening
- C-1 through C-6 durable-runtime/scaling/database/real-queue-CI work
- G-13 dynamic/database reference residual
- Release publication/versioning and unrelated formatting
```

Run exact searches across README and `docs/` for `Plan 5`, `Plan 6`, `358`, `488`, `891`, `904`,
`nodeflow:install`, `real queue worker`, `make-node-package`, and `extract-node`. Add only genuine
stale findings to the handoff; do not edit their source files.

- [ ] **Step 5: Update the execution record and verify documentation truthfulness**

Add Task 1-7 commit hashes, counterfactual outcomes, review results, exact gate totals, browser
result, demo row IDs and the documentation boundary.

Run:

```bash
rg -n "358 PHP|There is no `nodeflow:install`|Plan 5.*remain|Plan 6.*remain" README.md
git diff --check
git status --short
```

Expected: the stale-claim search returns nothing, the diff check is clean, and only intended Plan 7
files are modified. Read all three documents once for unfinished markers or future-tense delivery
claims before committing.

- [ ] **Step 6: Review and commit documentation artifacts**

Request independent review focused on source-to-claim traceability. The reviewer must compare every
README number to captured output, every proposed issue status to its prerequisite, and verify no
target GitBook/open-issues/historical-spec file changed.

```bash
git add README.md docs/documentation-changes.md \
  docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md
git commit -m "docs: record Plan 7 release readiness"
```

---

### Task 8: Whole-Branch Review and Remediation

**Files:**

- Modify only when a finding requires it: Plan 7 production/tests/README/handoff/record

**Interfaces:**

- Consumes: the complete branch from its local-main base through Task 7.
- Produces: explicit PASS or remediated branch with every Critical/Important finding closed.

- [ ] **Step 1: Request whole-branch spec-compliance review**

Use `superpowers:requesting-code-review` against the branch-point commit. Require the reviewer to
answer every whole-task question from the approved design and verify:

- G-7 binds path to the alias value and refuses duplicate/malformed entries;
- G-8 makes missing config healthy without weakening migration drift;
- G-9 tests execute actual emitted snippets;
- G-12 behavior matches installed Vite, including `.cjs` and `.cts`;
- G-11 is handed off with historically and currently correct arithmetic;
- README/handoff claims are evidence-backed;
- browser evidence is actual observation or explicitly blocked; and
- deferred work did not enter the diff.

- [ ] **Step 2: Request whole-branch code-quality/adversarial review**

Ask a separate reviewer to construct new runtime inputs instead of relying on source reading. Focus
on string delimiters, duplicate alias keys, multiple Vite candidates, customized config bytes and
namespaced fallback execution. Require explicit Critical/Important findings or PASS.

- [ ] **Step 3: Remediate findings under red-green discipline**

For each valid finding: reproduce it, persist the failing test, implement minimally, execute its
counterfactual, and update the execution record. Do not dismiss a finding merely because existing
tests pass.

Commit fixes by coherent finding group. Enumerate every reviewed path literally in `git add`, verify
the staged name list with `git diff --cached --name-only`, then run:

```bash
git commit -m "fix: close Plan 7 review findings"
```

Never use a broad directory add; unrelated concurrent files must remain unstaged.

- [ ] **Step 4: Rerun all branch gates after remediation**

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
```

If test totals changed, update README, `docs/documentation-changes.md` and the execution record with
the new measured totals, review those updates, and commit them before integration.

---

### Task 9: Integrate Locally and Verify Merged `main`

**Files:**

- Modify: `docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md`
- Modify only if measured totals changed: `README.md`, `docs/documentation-changes.md`

**Interfaces:**

- Consumes: reviewed `plan-7-release-readiness` branch.
- Produces: merged local `main`, final measured evidence and a standard finishing choice for the
  worktree/branch.

- [ ] **Step 1: Use the finishing workflow and check for concurrent-main changes**

Invoke `superpowers:finishing-a-development-branch`. Before merge:

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow
git status --short
git log --oneline --decorate -8
git diff --name-only main...plan-7-release-readiness
```

Expected: main is clean. If the separate documentation session changed README or another Plan 7
target, stop and resolve ownership with the user rather than overwriting it.

With local merge selected:

```bash
git merge --no-ff plan-7-release-readiness
```

There is no push step and no remote PR step.

- [ ] **Step 2: Run final package verification on merged main**

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
```

Expected: all pass. Record exact totals and compare them to README/handoff. Any difference requires a
truthful documentation correction before completion.

- [ ] **Step 3: Run final demo verification against merged main**

```bash
cd /Users/mikelmao/Sites/test-workflow
test "$(realpath vendor/atram/laravel-nodeflow)" = "/Users/mikelmao/Projects/laravel-nodeflow"
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
git diff --check
git status --short
```

Expected: all pass, demo clean, exact package-main vendor link. Run Pint only if a Plan 7 process
unexpectedly changed a demo PHP file; such a change must first be explained and authorized.

- [ ] **Step 4: Finalize merged-main evidence**

Append the merge commit, final package/demo results, final browser status, process cleanup and
repository statuses to the execution record. If measured totals differ, correct README and the
documentation handoff in the same commit.

```bash
git add docs/superpowers/plans/2026-08-22-plan-7-release-readiness-execution-record.md \
  README.md docs/documentation-changes.md
git commit -m "docs: record merged Plan 7 verification"
```

If README/handoff did not change, stage only the execution record.

- [ ] **Step 5: Run final hygiene checks and report honestly**

```bash
git status --short
git -C /Users/mikelmao/Sites/test-workflow status --short
git worktree list --porcelain
realpath /Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow
```

Confirm no Plan 7 queue worker or browser-automation process remains, no tracked worktree path was
introduced, and the old locked Plan 6 worktree was untouched. Report:

- every commit and review result;
- every counterfactual and its failure;
- exact final test/assertion totals;
- G-5 PASS or BLOCKED with evidence;
- demo rows created and retained;
- README changes made directly;
- the location and scope of `docs/documentation-changes.md`; and
- all deferred items still deferred.

Offer the remaining standard cleanup choice for the fully merged Plan 7 branch/worktree. Do not
force-remove a locked worktree.
