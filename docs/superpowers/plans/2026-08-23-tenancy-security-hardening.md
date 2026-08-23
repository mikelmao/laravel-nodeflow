# Plan 8 Tenancy Security Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `auto` tenancy decisions inspectable, reject unsafe Flow/Run version references at
Eloquent write time, and refuse a persisted cross-tenant run before its node executes.

**Architecture:** A public `TenancyDecisionResolver` becomes the single interpreter of tenancy
configuration and the current resolver binding. `Flow` and `Run` keep small model-local reference
guards at their create/update boundaries, while `RunNodeActivity` independently checks the persisted
run/version edge before any mutation. The three layers use purpose-specific exceptions rather than
one generic guard abstraction.

**Tech Stack:** PHP 8.3+, Laravel 12/13 Eloquent and service container, Orchestra Testbench,
Pest 4, Laravel Pint 1.x, Composer, Vitest, TypeScript, Git.

## Global Constraints

- Binding design: `docs/superpowers/specs/2026-08-23-tenancy-security-hardening-design.md` at
  `85f12b8a82cb6e335c7f08f2829b9ce2f1136233`.
- Use the then-current local `main`. Do not pull, push, tag, open a PR, or otherwise mutate a remote.
- At execution time, invoke `superpowers:using-git-worktrees` and create a fresh ignored worktree
  for branch `plan-8-tenancy-security-hardening`. Do not use, unlock, remove, or modify the locked
  Plan 6 worktree.
- Do not begin implementation until this plan is approved and an execution mode is chosen.
- Use strict red-green-refactor TDD. Commit each failing counterexample before production code, then
  commit the minimal passing implementation separately.
- Use `apply_patch` for hand edits. Preserve unrelated or concurrent changes and stop if one overlaps
  a named Plan 8 target.
- A non-null tenant scopes reads in all three configured modes. `nodeflow.tenancy` changes only the
  meaning of a null tenant; do not turn `disabled` into “never scope.”
- Invalid configuration must fail closed even when `TenantResolver::currentTenantId()` returns a
  tenant.
- Do not cache a tenant ID, configured mode, or resolver class in `TenancyDecisionResolver`.
- Durable execution compares persisted `Run.tenant_id` and `FlowVersion.tenant_id`; it must not
  require an ambient tenant in queue or console workers.
- `Flow.current_version_id` remains nullable. `Run.flow_version_id` remains required.
- `TenancyGuardSuspension` must not suppress version-reference validation.
- Do not add migrations, composite keys, triggers, mass-assignment guards, a run failure status,
  logging, events, or new configuration.
- Eloquent instance writes are the G-3 boundary. Keep the query-builder/raw-SQL bypass explicit in
  code, tests, README and GitBook documentation.
- Existing stored rows are not scanned, repaired or rewritten.
- No TypeScript/React production file and no tracked demo file should change.
- The demo repository is `/Users/mikelmao/Sites/test-workflow`. Never run `migrate:fresh`, reseed,
  reset, or alter its persistent application data.
- Before a demo gate, prove its package link target. Repoint only the exact
  `vendor/atram/laravel-nodeflow` symlink to the Plan 8 worktree, and restore it exactly to package
  `main` immediately afterward.
- The package does not declare Pint. Use the demo's installed `vendor/bin/pint` against only Plan 8
  changed PHP files; do not add a package dependency.
- Measure final test/assertion counts. Never pad tests or predict the final totals.
- D-1/D-2/G-3 alone are in scope. C-1 through C-6, G-13, Plan 7's high-octal decoder fidelity minor,
  release publication and unrelated refactors remain deferred.

---

## File Map

### Create

- `src/Tenancy/TenancyDecision.php` — immutable structured result for configured/effective tenancy
  behavior.
- `src/Tenancy/TenancyDecisionResolver.php` — the only interpreter of `nodeflow.tenancy` and the
  current `TenantResolver` binding.
- `src/Models/InvalidFlowVersionReferenceException.php` — missing/null write-time version reference.
- `src/Models/FlowVersionReferenceGuard.php` — shared existence and tenant comparison used by the
  two model-local event boundaries.
- `src/Execution/CrossTenantExecutionException.php` — persisted run/version mismatch at execution.
- `tests/Feature/TenancyDecisionTest.php` — public API, freshness and scope/API agreement.
- `tests/Feature/FlowVersionReferenceGuardTest.php` — Flow and Run create/update invariants, query
  cost, suspension and query-builder bypass.
- `tests/Feature/RunNodeActivityTest.php` — durable refusal ordering and successful no-ambient path.
- `tests/Support/RecordingNodeRunner.php` — records whether the activity reached `NodeRunner`.
- `docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md` — exact RED,
  GREEN, counterfactual, review and final-gate evidence.

### Modify

- `src/NodeflowServiceProvider.php` — register the shared decision resolver.
- `src/Models/Concerns/BelongsToTenant.php` — delegate scope resolution to the shared decision path
  and replace the now-stale D-1 comment.
- `src/Console/InstallCommand.php` — report the structured decision without changing exit status.
- `tests/Feature/InstallCommandTest.php` — package-fallback and host-binding D-1 output.
- `src/Models/CrossTenantWriteException.php` — add `forReferenceMismatch()`.
- `src/Models/Flow.php` — create/update guard for `current_version_id`; update invariant comment.
- `src/Models/Run.php` — create/update guard for `flow_version_id`; update invariant comment.
- `src/Workflows/Activities/RunNodeActivity.php` — missing-version and tenant checks before mutation.
- `README.md` — concise public tenancy safety and diagnostic API.
- `docs/gitbook/integration/tenancy.md` — exact diagnostic, write and execution guarantees.
- `docs/gitbook/experimental/known-limitations.md` — narrow the stale parent-invariant limitation to
  the model-event bypass and other still-unenforced relationships.
- `docs/documentation-changes.md` — mark the D-1/D-2/G-3 handoff as applied while preserving
  unrelated deferred items.
- `docs/superpowers/open-issues.md` — close D-1, D-2 and G-3 with measured evidence.

### Regression-only files

- `tests/Feature/TenancyModeTest.php`
- `tests/Feature/TenancyAutoModeTest.php`
- `tests/Feature/FlowVersionTenancyTest.php`
- `tests/Feature/PublishFlowTest.php`
- `tests/Feature/StartRunTest.php`
- `tests/Feature/SubFlowStarterTest.php`

---

## Phase 0: Isolated Worktree and Baseline

- [ ] **Step 1: Re-verify the package, worktrees and demo**

Run:

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow
git branch --show-current
git rev-parse HEAD
git status --short
git worktree list --porcelain
git remote -v

git -C /Users/mikelmao/Sites/test-workflow branch --show-current
git -C /Users/mikelmao/Sites/test-workflow rev-parse HEAD
git -C /Users/mikelmao/Sites/test-workflow status --short
realpath /Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow
```

Expected: package and demo are on clean `main`; package HEAD contains design commit `85f12b8` and
this plan; demo is clean at `e15e5bd912fee2e248654861b826d9e1458707dc` unless a separately
authorized change is explained; the demo link resolves to package `main`; the locked Plan 6
worktree remains at `8b51a3d` and locked. A remote may exist, but this plan does not touch it.

- [ ] **Step 2: Create the Plan 8 worktree**

Invoke `superpowers:using-git-worktrees` with:

```text
branch: plan-8-tenancy-security-hardening
path: /Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-8-tenancy-security-hardening
base: local main
```

Expected: the new worktree is clean at the plan commit and the locked Plan 6 worktree is unchanged.

- [ ] **Step 3: Make existing dependencies available without changing manifests**

From the new worktree:

```bash
cd /Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-8-tenancy-security-hardening
test ! -e vendor
test ! -e node_modules
ln -s /Users/mikelmao/Projects/laravel-nodeflow/vendor vendor
ln -s /Users/mikelmao/Projects/laravel-nodeflow/node_modules node_modules
test -x vendor/bin/pest
test -x /Users/mikelmao/Sites/test-workflow/vendor/bin/pint
test -x node_modules/.bin/vitest
git status --short
```

Expected: both symlinks are ignored and `git status --short` stays empty. If either target is absent,
install dependencies in the package main checkout using its existing lockfiles; do not change
`composer.json`, `composer.lock`, `package.json` or `package-lock.json`.

- [ ] **Step 4: Measure the package baseline**

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
```

Expected starting evidence: Pest 937 tests / 7,538 assertions; Vitest 160 tests across 17 files;
silent TypeScript; valid Composer metadata; clean diff check. Stop on an unexplained delta.

- [ ] **Step 5: Measure the demo baseline without repointing or modifying it**

```bash
cd /Users/mikelmao/Sites/test-workflow
test "$(realpath vendor/atram/laravel-nodeflow)" = "/Users/mikelmao/Projects/laravel-nodeflow"
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
git status --short
```

Expected: 56 Pest tests / 223 assertions, silent TypeScript, successful build, valid Composer
metadata with only already-known local-package warnings, and a clean tree.

- [ ] **Step 6: Start and commit the execution record**

Create `docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md` with the
actual worktree hashes, dependency-link targets and baseline outputs. Use these headings:

```markdown
# Plan 8 tenancy security hardening execution record

## Starting state

## Task 1 — D-1 tenancy decisions

## Task 2 — Flow version-reference guard

## Task 3 — Run version-reference guard

## Task 4 — D-2 durable execution assertion

## Documentation

## Whole-branch reviews and remediation

## Final gates and integration
```

Then run:

```bash
git add docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "docs: start Plan 8 execution record"
```

---

## Task 1: D-1 — One Inspectable Tenancy Decision

**Files:**

- Create: `src/Tenancy/TenancyDecision.php`
- Create: `src/Tenancy/TenancyDecisionResolver.php`
- Create: `tests/Feature/TenancyDecisionTest.php`
- Modify: `src/NodeflowServiceProvider.php::register()`
- Modify: `src/Models/Concerns/BelongsToTenant.php::resolveTenantIdForScope()`
- Modify: `src/Console/InstallCommand.php::reportTenancy()`
- Modify: `tests/Feature/InstallCommandTest.php`
- Update evidence: `docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md`

**Interfaces:**

- Produces: `TenancyDecisionResolver::decision(): TenancyDecision`
- Produces: `TenancyDecisionResolver::tenantIdForScope(string $modelClass): ?string`
- Produces: the public readonly `TenancyDecision` properties and constants defined in Step 4.
- Consumed later: README and GitBook examples resolve `TenancyDecisionResolver` from the container.

- [ ] **Step 1: Write the failing structured-decision tests**

Create `tests/Feature/TenancyDecisionTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;
use Nodeflow\Tenancy\NoTenancyResolver;
use Nodeflow\Tenancy\TenancyDecision;
use Nodeflow\Tenancy\TenancyDecisionResolver;

beforeEach(function () {
    // Keep the first RED run as an assertion failure, not an autoload error.
    expect(class_exists(TenancyDecisionResolver::class))
        ->toBeTrue('TenancyDecisionResolver has not been implemented yet.');

    $this->bindResolver = function (?string $tenantId): void {
        app()->bind(TenantResolver::class, fn () => new class($tenantId) implements TenantResolver
        {
            public function __construct(private ?string $tenantId) {}

            public function currentTenantId(): ?string
            {
                return $this->tenantId;
            }

            public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
            {
                return true;
            }
        });
    };
});

it('describes the auto fallback as an inferred unscoped null outcome', function () {
    $decision = app(TenancyDecisionResolver::class)->decision();

    expect(app(TenantResolver::class))->toBeInstanceOf(NoTenancyResolver::class)
        ->and($decision->configuredMode)->toBe('auto')
        ->and($decision->effectiveMode)->toBe(TenancyDecision::EFFECTIVE_DISABLED)
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_UNSCOPED)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK)
        ->and($decision->inferred)->toBeTrue()
        ->and($decision->resolverClass)->toBe(NoTenancyResolver::class)
        ->and($decision->isValid())->toBeTrue();
});

it('records that a host binding made auto fail closed', function () {
    ($this->bindResolver)(null);

    $decision = app(TenancyDecisionResolver::class)->decision();

    expect($decision->effectiveMode)->toBe(TenancyDecision::EFFECTIVE_RESOLVER)
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_AUTO_HOST_RESOLVER)
        ->and($decision->inferred)->toBeTrue()
        ->and($decision->resolverClass)->not->toBe(NoTenancyResolver::class);

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('reports explicit modes without claiming inference', function (string $mode, string $effective, string $outcome, string $reason) {
    config()->set('nodeflow.tenancy', $mode);
    ($this->bindResolver)(null);

    $decision = app(TenancyDecisionResolver::class)->decision();

    expect($decision->effectiveMode)->toBe($effective)
        ->and($decision->nullTenantOutcome)->toBe($outcome)
        ->and($decision->reason)->toBe($reason)
        ->and($decision->inferred)->toBeFalse();
})->with([
    'disabled' => ['disabled', TenancyDecision::EFFECTIVE_DISABLED, TenancyDecision::NULL_TENANT_UNSCOPED, TenancyDecision::REASON_EXPLICIT_DISABLED],
    'resolver' => ['resolver', TenancyDecision::EFFECTIVE_RESOLVER, TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED, TenancyDecision::REASON_EXPLICIT_RESOLVER],
]);

it('represents invalid configuration while an actual scope still refuses it', function () {
    config()->set('nodeflow.tenancy', 'Resolver');
    ($this->bindResolver)('org-1');

    $decision = app(TenancyDecisionResolver::class)->decision();

    expect($decision->effectiveMode)->toBeNull()
        ->and($decision->nullTenantOutcome)->toBe(TenancyDecision::NULL_TENANT_THROWS_INVALID)
        ->and($decision->reason)->toBe(TenancyDecision::REASON_INVALID_CONFIGURATION)
        ->and($decision->isValid())->toBeFalse();

    expect(fn () => Flow::count())->toThrow(InvalidArgumentException::class, "'Resolver'");
});

it('recomputes config and the resolver binding after an earlier inspection', function () {
    $service = app(TenancyDecisionResolver::class);
    $first = $service->decision();

    config()->set('nodeflow.tenancy', 'resolver');
    ($this->bindResolver)('org-9');
    $second = $service->decision();

    expect($first->reason)->toBe(TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK)
        ->and($second->reason)->toBe(TenancyDecision::REASON_EXPLICIT_RESOLVER)
        ->and($second->resolverClass)->not->toBe($first->resolverClass)
        ->and($service->tenantIdForScope(Flow::class))->toBe('org-9');
});
```

- [ ] **Step 2: Update the failing installer-output tests**

In `tests/Feature/InstallCommandTest.php`, import `TenantResolver`. Replace the existing tenancy
report test and add the host-binding case:

```php
it('reports that auto inferred unscoped reads from the package fallback', function () {
    writeClientWiring($this->root);

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('package fallback')
        ->expectsOutputToContain('auto inferred disabled mode')
        ->assertExitCode(0);
});

it('reports when a host resolver binding made auto fail closed without failing install', function () {
    writeClientWiring($this->root);

    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return null;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    $this->artisan('nodeflow:install')
        ->expectsOutputToContain('host TenantResolver binding')
        ->expectsOutputToContain('caused auto to infer resolver mode')
        ->expectsOutputToContain('TenancyUnresolvedException')
        ->assertExitCode(0);
});
```

- [ ] **Step 3: Run RED and commit the discriminator**

```bash
vendor/bin/pest tests/Feature/TenancyDecisionTest.php tests/Feature/InstallCommandTest.php --compact
git add tests/Feature/TenancyDecisionTest.php tests/Feature/InstallCommandTest.php
git diff --cached --check
git commit -m "test: specify inspectable tenancy decisions"
```

Expected: the focused run fails because `TenancyDecision` and `TenancyDecisionResolver` do not
exist and the old installer prose does not satisfy the new contract. Record exact failures.

- [ ] **Step 4: Add the immutable decision result**

Create `src/Tenancy/TenancyDecision.php` with these exact public names:

```php
<?php

namespace Nodeflow\Tenancy;

final readonly class TenancyDecision
{
    public const EFFECTIVE_DISABLED = 'disabled';
    public const EFFECTIVE_RESOLVER = 'resolver';

    public const NULL_TENANT_UNSCOPED = 'unscoped';
    public const NULL_TENANT_THROWS_UNRESOLVED = 'throws_tenancy_unresolved';
    public const NULL_TENANT_THROWS_INVALID = 'throws_invalid_configuration';

    public const REASON_AUTO_PACKAGE_FALLBACK = 'auto_package_fallback';
    public const REASON_AUTO_HOST_RESOLVER = 'auto_host_resolver';
    public const REASON_EXPLICIT_DISABLED = 'explicit_disabled';
    public const REASON_EXPLICIT_RESOLVER = 'explicit_resolver';
    public const REASON_INVALID_CONFIGURATION = 'invalid_configuration';

    public function __construct(
        public mixed $configuredMode,
        public ?string $effectiveMode,
        public string $resolverClass,
        public string $nullTenantOutcome,
        public bool $inferred,
        public string $reason,
    ) {}

    public function isValid(): bool
    {
        return $this->reason !== self::REASON_INVALID_CONFIGURATION;
    }
}
```

- [ ] **Step 5: Implement the single decision resolver**

Create `src/Tenancy/TenancyDecisionResolver.php` with this complete structure:

```php
<?php

namespace Nodeflow\Tenancy;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\TenancyUnresolvedException;

final class TenancyDecisionResolver
{
    public function __construct(
        private Container $container,
        private Repository $config,
    ) {}

    public function decision(): TenancyDecision
    {
        $resolver = $this->container->make(TenantResolver::class);

        return $this->decisionFor($resolver, $this->config->get('nodeflow.tenancy'));
    }

    public function tenantIdForScope(string $modelClass): ?string
    {
        $resolver = $this->container->make(TenantResolver::class);
        $decision = $this->decisionFor($resolver, $this->config->get('nodeflow.tenancy'));

        if (! $decision->isValid()) {
            throw new InvalidArgumentException($this->invalidModeMessage($decision->configuredMode));
        }

        $tenantId = $resolver->currentTenantId();

        if ($tenantId !== null) {
            return $tenantId;
        }

        return match ($decision->nullTenantOutcome) {
            TenancyDecision::NULL_TENANT_UNSCOPED => null,
            TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED => throw new TenancyUnresolvedException($modelClass),
            default => throw new InvalidArgumentException($this->invalidModeMessage($decision->configuredMode)),
        };
    }

    private function decisionFor(TenantResolver $resolver, mixed $mode): TenancyDecision
    {
        return match ($mode) {
            'auto' => $resolver instanceof NoTenancyResolver
                ? new TenancyDecision(
                    $mode,
                    TenancyDecision::EFFECTIVE_DISABLED,
                    $resolver::class,
                    TenancyDecision::NULL_TENANT_UNSCOPED,
                    true,
                    TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK,
                )
                : new TenancyDecision(
                    $mode,
                    TenancyDecision::EFFECTIVE_RESOLVER,
                    $resolver::class,
                    TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED,
                    true,
                    TenancyDecision::REASON_AUTO_HOST_RESOLVER,
                ),
            'disabled' => new TenancyDecision(
                $mode,
                TenancyDecision::EFFECTIVE_DISABLED,
                $resolver::class,
                TenancyDecision::NULL_TENANT_UNSCOPED,
                false,
                TenancyDecision::REASON_EXPLICIT_DISABLED,
            ),
            'resolver' => new TenancyDecision(
                $mode,
                TenancyDecision::EFFECTIVE_RESOLVER,
                $resolver::class,
                TenancyDecision::NULL_TENANT_THROWS_UNRESOLVED,
                false,
                TenancyDecision::REASON_EXPLICIT_RESOLVER,
            ),
            default => new TenancyDecision(
                $mode,
                null,
                $resolver::class,
                TenancyDecision::NULL_TENANT_THROWS_INVALID,
                false,
                TenancyDecision::REASON_INVALID_CONFIGURATION,
            ),
        };
    }

    private function invalidModeMessage(mixed $mode): string
    {
        return 'Unrecognised nodeflow.tenancy mode '.$this->describeMode($mode)
            ."; the only valid values are 'auto', 'resolver' and 'disabled'. All are matched "
            ."exactly, so 'Auto', 'AUTO' and true are all invalid. Reading is refused rather "
            .'than falling back to unscoped, which on a null tenant would return every '
            .'tenant\'s rows. Check NODEFLOW_TENANCY in the environment, and run '
            .'`php artisan config:clear` if a cached config predates the key existing.';
    }

    private function describeMode(mixed $mode): string
    {
        if ($mode === null) {
            return 'null (the key is absent)';
        }

        return is_scalar($mode) ? var_export($mode, true) : get_debug_type($mode);
    }
}
```

The duplicated defensive default in `tenantIdForScope()` is unreachable for a valid decision, but
keeps a future outcome constant from silently failing open. Do not call `app()` inside this class.

- [ ] **Step 6: Register and consume the resolver**

In `NodeflowServiceProvider::register()` add:

```php
$this->app->singleton(\Nodeflow\Tenancy\TenancyDecisionResolver::class);
```

Replace `BelongsToTenant::resolveTenantIdForScope()` with:

```php
protected static function resolveTenantIdForScope(): ?string
{
    return app(\Nodeflow\Tenancy\TenancyDecisionResolver::class)
        ->tenantIdForScope(static::class);
}
```

Remove its now-unused `InvalidArgumentException`, `TenantResolver` and `NoTenancyResolver` imports
and `describeTenancyMode()`. Rewrite the stale known-limit paragraph to say that middleware-only
bindings remain unsafe but are now visible through the decision API and installer report.

In `InstallCommand::reportTenancy()`, resolve `TenancyDecisionResolver` and use this shape (split the
long strings across concatenations to satisfy Pint):

```php
$decision = $this->laravel->make(TenancyDecisionResolver::class)->decision();
$configured = $decision->configuredMode === null
    ? 'null (the key is absent)'
    : (is_scalar($decision->configuredMode)
        ? var_export($decision->configuredMode, true)
        : get_debug_type($decision->configuredMode));

$message = match ($decision->reason) {
    TenancyDecision::REASON_AUTO_PACKAGE_FALLBACK =>
        'auto inferred disabled mode from the package fallback, so a null tenant means '
        .'this application has no tenancy and scoped reads are unscoped',
    TenancyDecision::REASON_AUTO_HOST_RESOLVER =>
        "host TenantResolver binding [{$decision->resolverClass}] caused auto to infer resolver mode, "
        .'so a null tenant throws TenancyUnresolvedException rather than reading every tenant\'s rows. '
        .'Bind it unconditionally in register(), never in middleware.',
    TenancyDecision::REASON_EXPLICIT_DISABLED =>
        'disabled — a null tenant reads unscoped; a non-null tenant still scopes normally',
    TenancyDecision::REASON_EXPLICIT_RESOLVER =>
        'resolver — a null tenant throws TenancyUnresolvedException',
    TenancyDecision::REASON_INVALID_CONFIGURATION =>
        "UNRECOGNISED value {$configured} — every scoped read throws InvalidArgumentException. "
        .'Valid values are auto, disabled and resolver, matched exactly. Run '
        .'`php artisan config:clear` if a cached config predates the key.',
};

$this->components->info('nodeflow.tenancy: '.$message);
```

Remove the old `TenantResolver` and `NoTenancyResolver` imports, add `TenancyDecision` and
`TenancyDecisionResolver`, and do not feed this report into `exitCode()`.

- [ ] **Step 7: Run GREEN and all tenancy regressions**

```bash
vendor/bin/pest \
  tests/Feature/TenancyDecisionTest.php \
  tests/Feature/TenancyModeTest.php \
  tests/Feature/TenancyAutoModeTest.php \
  tests/Feature/TenancyTest.php \
  tests/Feature/InstallCommandTest.php \
  --compact
```

Expected: all pass. Explicitly verify the existing `disabled` + non-null resolver test still scopes
to one tenant and invalid mode still throws when the resolver returns a tenant.

- [ ] **Step 8: Execute the D-1 counterfactual**

Using `apply_patch`, temporarily make the `auto` branch always return the package-fallback decision.
Run:

```bash
vendor/bin/pest tests/Feature/TenancyDecisionTest.php tests/Feature/TenancyAutoModeTest.php \
  --filter="host" --compact
```

Expected: the host-decision assertion fails and the existing host-null scope test stops throwing.
Restore the exact production branch with `apply_patch`, rerun the complete Step 7 command, and record
both outputs. A counterfactual that stays green blocks the task.

- [ ] **Step 9: Format, update evidence and commit GREEN**

```bash
/Users/mikelmao/Sites/test-workflow/vendor/bin/pint --test \
  src/Tenancy/TenancyDecision.php \
  src/Tenancy/TenancyDecisionResolver.php \
  src/NodeflowServiceProvider.php \
  src/Models/Concerns/BelongsToTenant.php \
  src/Console/InstallCommand.php \
  tests/Feature/TenancyDecisionTest.php \
  tests/Feature/InstallCommandTest.php
git diff --check
```

If Pint reports style failures, run the identical list without `--test`, inspect the diff, rerun
Step 7 and then rerun `--test`. Add RED/GREEN/counterfactual/Pint evidence to the execution record.

```bash
git add src/Tenancy/TenancyDecision.php src/Tenancy/TenancyDecisionResolver.php \
  src/NodeflowServiceProvider.php src/Models/Concerns/BelongsToTenant.php \
  src/Console/InstallCommand.php tests/Feature/TenancyDecisionTest.php \
  tests/Feature/InstallCommandTest.php \
  docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "feat: expose effective tenancy decisions"
```

---

## Task 2: G-3 — Guard Flow Current-Version Writes

**Files:**

- Create: `src/Models/InvalidFlowVersionReferenceException.php`
- Create: `src/Models/FlowVersionReferenceGuard.php`
- Create: `tests/Feature/FlowVersionReferenceGuardTest.php`
- Modify: `src/Models/CrossTenantWriteException.php`
- Modify: `src/Models/Flow.php`
- Update evidence: execution record

**Interfaces:**

- Produces: `InvalidFlowVersionReferenceException::forMissing(string $modelClass, string $attribute, mixed $attemptedId): self`
- Produces: `CrossTenantWriteException::forReferenceMismatch(string $modelClass, string $attribute, mixed $referenceId, mixed $modelTenant, mixed $referenceTenant): self`
- Produces: `FlowVersionReferenceGuard::assert(Model $model, string $attribute, bool $nullable): void`
- Consumed by Task 3: the shared guard and both exception constructors.

- [ ] **Step 1: Write the failing Flow guard fixtures and tests**

Create `tests/Feature/FlowVersionReferenceGuardTest.php` with these imports and fixture before the
cases below:

```php
<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\InvalidFlowVersionReferenceException;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    $this->makeVersion = function (string $tenantId): FlowVersion {
        return TenancyGuardSuspension::run(function () use ($tenantId) {
            $flow = Flow::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'name' => "{$tenantId} flow",
                'trigger_type' => 'manual',
                'status' => 'active',
            ]);

            return FlowVersion::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'version' => 1,
                'graph' => [
                    'start' => 'n1',
                    'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
                    'edges' => [],
                ],
                'content_hash' => "hash-{$tenantId}",
            ]);
        });
    };
});
```

Then add these exact cases:

```php
it('allows a null or same-tenant current version', function () {
    $draft = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);
    $version = ($this->makeVersion)('org-1');

    $flow = Flow::create([
        'name' => 'Published',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => $version->id,
    ]);

    expect($draft->current_version_id)->toBeNull()
        ->and($flow->current_version_id)->toBe($version->id);
});

it('refuses a missing current version on create and update', function () {
    expect(fn () => Flow::create([
        'name' => 'Missing',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => 999999,
    ]))->toThrow(InvalidFlowVersionReferenceException::class, 'current_version_id');

    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => $flow->update(['current_version_id' => 999999]))
        ->toThrow(InvalidFlowVersionReferenceException::class, '999999');
});

it('refuses a cross-tenant current version on create and update', function () {
    $foreign = ($this->makeVersion)('org-2');

    expect(fn () => Flow::create([
        'name' => 'Unsafe',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => $foreign->id,
    ]))->toThrow(CrossTenantWriteException::class, 'current_version_id');

    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => $flow->update(['current_version_id' => $foreign->id]))
        ->toThrow(CrossTenantWriteException::class, "'org-2'");
});

it('does not let guard suspension create a contradictory flow reference', function () {
    $version = ($this->makeVersion)('org-1');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => TenancyGuardSuspension::run(
        fn () => $flow->update(['tenant_id' => 'org-2', 'current_version_id' => $version->id])
    ))->toThrow(CrossTenantWriteException::class);
});

it('queries the version only when a flow write can change the invariant', function () {
    $version = ($this->makeVersion)('org-1');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);
    $versionQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$versionQueries) {
        if (str_contains($query->sql, 'nodeflow_flow_versions')) {
            $versionQueries[] = $query->sql;
        }
    });

    $flow->update(['name' => 'Renamed']);
    expect($versionQueries)->toBe([]);

    $flow->update(['current_version_id' => $version->id]);
    expect($versionQueries)->toHaveCount(1);
});

it('documents that a query-builder flow update bypasses model events', function () {
    $foreign = ($this->makeVersion)('org-2');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    Flow::withoutTenancy()->whereKey($flow->id)->update(['current_version_id' => $foreign->id]);

    expect(Flow::withoutTenancy()->findOrFail($flow->id)->current_version_id)->toBe($foreign->id);
});
```

Import `DB`, `TenantResolver`, `CrossTenantWriteException`, `Flow`, `FlowVersion`,
`InvalidFlowVersionReferenceException`, and `TenancyGuardSuspension`. The fixture graph must be a
minimal valid array with `start`, one `core.exit` node, and no edges.

- [ ] **Step 2: Run RED and commit the Flow discriminator**

```bash
vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --compact
git add tests/Feature/FlowVersionReferenceGuardTest.php
git diff --cached --check
git commit -m "test: specify flow version-reference guards"
```

Expected: exception classes are missing; after temporary imports are resolvable, current behavior
also accepts missing/cross-tenant IDs. Record exact failures.

- [ ] **Step 3: Add the write exceptions**

Create `InvalidFlowVersionReferenceException` as a `RuntimeException` with a private constructor and:

```php
public static function forMissing(string $modelClass, string $attribute, mixed $attemptedId): self
{
    $value = $attemptedId === null ? 'null' : "[{$attemptedId}]";

    return new self(
        "Invalid FlowVersion reference: {$modelClass}.{$attribute} points to {$value}, "
        .'but that FlowVersion does not exist. The write was refused before persistence.'
    );
}
```

Add this named constructor to `CrossTenantWriteException`:

```php
public static function forReferenceMismatch(
    string $modelClass,
    string $attribute,
    mixed $referenceId,
    mixed $modelTenant,
    mixed $referenceTenant,
): self {
    return new self(
        "Cross-tenant write attempted: {$modelClass}.{$attribute} references FlowVersion "
        ."[{$referenceId}] for tenant ".self::describe($referenceTenant)
        .' while the model belongs to '.self::describe($modelTenant)
        .'. The referenced version must belong to the same tenant.'
    );
}
```

Update the exception class docblock with this fourth shape.

- [ ] **Step 4: Implement the focused shared guard and Flow event boundary**

Create `src/Models/FlowVersionReferenceGuard.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;

final class FlowVersionReferenceGuard
{
    public static function assert(Model $model, string $attribute, bool $nullable): void
    {
        $referenceId = $model->getAttribute($attribute);

        if ($referenceId === null) {
            if ($nullable) {
                return;
            }

            throw InvalidFlowVersionReferenceException::forMissing(
                $model::class,
                $attribute,
                null,
            );
        }

        $version = FlowVersion::withoutTenancy()->find($referenceId);

        if ($version === null) {
            throw InvalidFlowVersionReferenceException::forMissing(
                $model::class,
                $attribute,
                $referenceId,
            );
        }

        if ((string) $version->tenant_id !== (string) $model->getAttribute('tenant_id')) {
            throw CrossTenantWriteException::forReferenceMismatch(
                $model::class,
                $attribute,
                $version->id,
                $model->getAttribute('tenant_id'),
                $version->tenant_id,
            );
        }
    }
}
```

In `Flow::booted()` register listeners after trait booting:

```php
protected static function booted(): void
{
    static::creating(fn (self $flow) => $flow->assertCurrentVersionReference());

    static::updating(function (self $flow) {
        if ($flow->isDirty(['current_version_id', 'tenant_id'])) {
            $flow->assertCurrentVersionReference();
        }
    });
}

private function assertCurrentVersionReference(): void
{
    FlowVersionReferenceGuard::assert($this, 'current_version_id', nullable: true);
}
```

Rewrite `currentVersion()`'s invariant comment: Eloquent instance writes now enforce existence and
same-tenant reference, `FlowVersion` creation enforces its Flow parent tenant, and query-builder/raw
SQL writes remain the explicit bypass. Do not claim same-Flow identity enforcement.

- [ ] **Step 5: Run GREEN, PublishFlow regression and counterfactual**

```bash
vendor/bin/pest \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  tests/Feature/FlowVersionTenancyTest.php \
  tests/Feature/PublishFlowTest.php \
  --compact
```

Then temporarily remove both Flow listeners with `apply_patch` and run:

```bash
vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php \
  --filter="missing current|cross-tenant current|guard suspension" --compact
```

Expected: the missing and mismatch tests fail because the unsafe writes succeed or fall through to
database behavior. Restore the listeners, rerun the complete GREEN command, and record both outputs.

- [ ] **Step 6: Format, update evidence and commit GREEN**

```bash
/Users/mikelmao/Sites/test-workflow/vendor/bin/pint --test \
  src/Models/InvalidFlowVersionReferenceException.php \
  src/Models/FlowVersionReferenceGuard.php \
  src/Models/CrossTenantWriteException.php \
  src/Models/Flow.php \
  tests/Feature/FlowVersionReferenceGuardTest.php
git diff --check
```

Apply scoped formatting only if required, rerun Step 5, update the execution record, then:

```bash
git add src/Models/InvalidFlowVersionReferenceException.php \
  src/Models/FlowVersionReferenceGuard.php \
  src/Models/CrossTenantWriteException.php src/Models/Flow.php \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "feat: guard flow version references"
```

---

## Task 3: G-3 — Guard Run Version Writes

**Files:**

- Modify: `src/Models/Run.php`
- Modify: `tests/Feature/FlowVersionReferenceGuardTest.php`
- Regression: `tests/Feature/StartRunTest.php`
- Regression: `tests/Feature/SubFlowStarterTest.php`
- Regression: `tests/Feature/FlowVersionTenancyTest.php`
- Update evidence: execution record

**Interfaces:**

- Consumes: `FlowVersionReferenceGuard::assert()` and both Task 2 exception constructors.
- Produces: `Run` create/update enforcement used by every package run writer.

- [ ] **Step 1: Append the failing Run cases**

Append to `FlowVersionReferenceGuardTest.php`:

```php
it('allows a run to reference a same-tenant version', function () {
    $version = ($this->makeVersion)('org-1');

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    expect($run->flow_version_id)->toBe($version->id);
});

it('refuses null and missing run version references', function () {
    expect(fn () => Run::create([
        'flow_version_id' => null,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]))->toThrow(InvalidFlowVersionReferenceException::class, 'null');

    expect(fn () => Run::create([
        'flow_version_id' => 999999,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]))->toThrow(InvalidFlowVersionReferenceException::class, '999999');
});

it('refuses cross-tenant run references on create and update', function () {
    $own = ($this->makeVersion)('org-1');
    $foreign = ($this->makeVersion)('org-2');

    expect(fn () => Run::create([
        'flow_version_id' => $foreign->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]))->toThrow(CrossTenantWriteException::class, 'flow_version_id');

    $run = Run::create([
        'flow_version_id' => $own->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    expect(fn () => $run->update(['flow_version_id' => $foreign->id]))
        ->toThrow(CrossTenantWriteException::class, "'org-2'");
});

it('does not let guard suspension create a contradictory run reference', function () {
    $version = ($this->makeVersion)('org-1');
    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    expect(fn () => TenancyGuardSuspension::run(
        fn () => $run->update(['tenant_id' => 'org-2'])
    ))->toThrow(CrossTenantWriteException::class);
});

it('queries the version only when a run write can change the invariant', function () {
    $version = ($this->makeVersion)('org-1');
    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);
    $versionQueries = [];

    DB::listen(function (Illuminate\Database\Events\QueryExecuted $query) use (&$versionQueries) {
        if (str_contains($query->sql, 'nodeflow_flow_versions')) {
            $versionQueries[] = $query->sql;
        }
    });

    $run->update(['status' => 'running']);
    expect($versionQueries)->toBe([]);

    $replacement = ($this->makeVersion)('org-1');
    $versionQueries = [];
    $run->update(['flow_version_id' => $replacement->id]);
    expect($versionQueries)->toHaveCount(1);
});

it('documents that a query-builder run update bypasses model events', function () {
    $own = ($this->makeVersion)('org-1');
    $foreign = ($this->makeVersion)('org-2');
    $run = Run::create([
        'flow_version_id' => $own->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    Run::withoutTenancy()->whereKey($run->id)->update(['flow_version_id' => $foreign->id]);

    expect(Run::withoutTenancy()->findOrFail($run->id)->flow_version_id)->toBe($foreign->id);
});
```

Import `Run` at the top. The test named “null and missing” must see the package exception in both
cases, never a database `QueryException`.

- [ ] **Step 2: Run RED and commit the Run discriminator**

```bash
vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php --filter="run" --compact
git add tests/Feature/FlowVersionReferenceGuardTest.php
git diff --cached --check
git commit -m "test: specify run version-reference guards"
```

Expected: current Run writes accept the cross-tenant/missing reference on SQLite or fail with the
wrong database exception; suspension also permits a contradiction.

- [ ] **Step 3: Implement the Run event guard**

Add to `Run`:

```php
protected static function booted(): void
{
    static::creating(fn (self $run) => $run->assertFlowVersionReference());

    static::updating(function (self $run) {
        if ($run->isDirty(['flow_version_id', 'tenant_id'])) {
            $run->assertFlowVersionReference();
        }
    });
}

private function assertFlowVersionReference(): void
{
    FlowVersionReferenceGuard::assert($this, 'flow_version_id', nullable: false);
}
```

Rewrite the `flowVersion()` invariant comment to name the new Eloquent guard and explicit
query-builder/raw-SQL bypass. Do not add a scope to the relation.

- [ ] **Step 4: Run GREEN and writer regressions**

```bash
vendor/bin/pest \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  tests/Feature/StartRunTest.php \
  tests/Feature/SubFlowStarterTest.php \
  tests/Feature/FlowVersionTenancyTest.php \
  tests/Feature/PublishFlowTest.php \
  --compact
```

Expected: all pass, including `StartRun` inside `TenancyGuardSuspension`.

- [ ] **Step 5: Execute the Run counterfactual**

Temporarily remove both Run listeners with `apply_patch` and run:

```bash
vendor/bin/pest tests/Feature/FlowVersionReferenceGuardTest.php \
  --filter="null and missing run|cross-tenant run|guard suspension create a contradictory run" \
  --compact
```

Expected: all three discriminators fail for the intended reason. Restore the listeners, rerun Step
4, and record the mutation and restoration.

- [ ] **Step 6: Format, update evidence and commit GREEN**

```bash
/Users/mikelmao/Sites/test-workflow/vendor/bin/pint --test \
  src/Models/Run.php tests/Feature/FlowVersionReferenceGuardTest.php
git diff --check
```

Apply scoped formatting if required, rerun Step 4, update the execution record, then:

```bash
git add src/Models/Run.php tests/Feature/FlowVersionReferenceGuardTest.php \
  docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "feat: guard run version references"
```

---

## Task 4: D-2 — Refuse Cross-Tenant Durable Execution

**Files:**

- Create: `src/Execution/CrossTenantExecutionException.php`
- Create: `tests/Support/RecordingNodeRunner.php`
- Create: `tests/Feature/RunNodeActivityTest.php`
- Modify: `src/Workflows/Activities/RunNodeActivity.php`
- Update evidence: execution record

**Interfaces:**

- Produces: `CrossTenantExecutionException::forRunVersion(Run $run, FlowVersion $version): self`
- Produces: `RecordingNodeRunner::$calls` and `$result` for activity-boundary tests only.

- [ ] **Step 1: Create the recording test runner**

Create `tests/Support/RecordingNodeRunner.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;

class RecordingNodeRunner extends NodeRunner
{
    public int $calls = 0;

    /** @param string[] $result */
    public function __construct(public array $result = []) {}

    public function run(Run $run, Graph $graph, string $nodeId): array
    {
        $this->calls++;

        return $this->result;
    }
}
```

- [ ] **Step 2: Write the failing activity tests**

Create `tests/Feature/RunNodeActivityTest.php` with this complete fixture before the cases:

```php
<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Tests\Support\RecordingNodeRunner;

beforeEach(function () {
    $this->makeVersion = function (string $tenantId): FlowVersion {
        return TenancyGuardSuspension::run(function () use ($tenantId) {
            $flow = Flow::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'name' => "{$tenantId} flow",
                'trigger_type' => 'manual',
                'status' => 'active',
            ]);

            return FlowVersion::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'version' => 1,
                'graph' => [
                    'start' => 'n1',
                    'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
                    'edges' => [],
                ],
                'content_hash' => "hash-{$tenantId}",
            ]);
        });
    };

    $this->insertRun = function (int $versionId, string $tenantId, int $stepsTaken): int {
        return DB::table('nodeflow_runs')->insertGetId([
            'flow_version_id' => $versionId,
            'tenant_id' => $tenantId,
            'strategy' => 'cohort',
            'status' => 'running',
            'is_test' => false,
            'steps_taken' => $stepsTaken,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->bindNullResolver = function (): void {
        app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
        {
            public function currentTenantId(): ?string
            {
                return null;
            }

            public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
            {
                return true;
            }
        });
    };
});
```

Then add:

```php
it('refuses a persisted tenant mismatch before any mutation or node call', function () {
    $version = ($this->makeVersion)('org-2');
    $runId = ($this->insertRun)($version->id, 'org-1', 7);
    $runner = new RecordingNodeRunner(['next']);
    app()->instance(NodeRunner::class, $runner);

    expect(fn () => app(RunNodeActivity::class)->handle($runId, 'n1'))
        ->toThrow(CrossTenantExecutionException::class, "Run [{$runId}]");

    expect(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(7)
        ->and($runner->calls)->toBe(0);
});

it('fails a missing pinned version before incrementing or calling the runner', function () {
    $runId = ($this->insertRun)(999999, 'org-1', 4);
    $runner = new RecordingNodeRunner;
    app()->instance(NodeRunner::class, $runner);

    expect(fn () => app(RunNodeActivity::class)->handle($runId, 'n1'))
        ->toThrow(ModelNotFoundException::class, 'FlowVersion');

    expect(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(4)
        ->and($runner->calls)->toBe(0);
});

it('executes a matching persisted pair once without an ambient tenant', function () {
    config()->set('nodeflow.tenancy', 'resolver');
    ($this->bindNullResolver)();
    $version = ($this->makeVersion)('org-1');
    $runId = ($this->insertRun)($version->id, 'org-1', 0);
    $runner = new RecordingNodeRunner(['next']);
    app()->instance(NodeRunner::class, $runner);

    $result = app(RunNodeActivity::class)->handle($runId, 'n1');

    expect($result)->toBe(['next'])
        ->and(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(1)
        ->and($runner->calls)->toBe(1);
});
```

The query-builder insert is intentional: it creates the persisted corruption D-2 must catch without
being refused first by G-3. Do not replace it with `Run::create()`.

- [ ] **Step 3: Run RED and commit the activity discriminator**

```bash
vendor/bin/pest tests/Feature/RunNodeActivityTest.php --compact
git add tests/Support/RecordingNodeRunner.php tests/Feature/RunNodeActivityTest.php
git diff --cached --check
git commit -m "test: specify durable tenant assertion"
```

Expected: the exception class is absent; with that import temporarily satisfied, current activity
increments and calls the runner for the mismatched row and produces a null-property error for the
missing version.

- [ ] **Step 4: Add the execution exception**

Create `src/Execution/CrossTenantExecutionException.php`:

```php
<?php

namespace Nodeflow\Execution;

use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use RuntimeException;

class CrossTenantExecutionException extends RuntimeException
{
    public static function forRunVersion(Run $run, FlowVersion $version): self
    {
        return new self(
            "Cross-tenant execution refused: Run [{$run->id}] belongs to tenant "
            .self::describe($run->tenant_id)." but its FlowVersion [{$version->id}] belongs to "
            .self::describe($version->tenant_id).'. No execution-side mutation was performed.'
        );
    }

    private static function describe(mixed $tenant): string
    {
        return $tenant === null ? 'null' : "'{$tenant}'";
    }
}
```

- [ ] **Step 5: Put both checks before the mutation boundary**

Change `RunNodeActivity::handle()` to:

```php
$run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);
$version = $run->flowVersion;

if ($version === null) {
    throw (new ModelNotFoundException)->setModel(FlowVersion::class, [$run->flow_version_id]);
}

if ((string) $run->tenant_id !== (string) $version->tenant_id) {
    throw CrossTenantExecutionException::forRunVersion($run, $version);
}

$run->increment('steps_taken');

return app(NodeRunner::class)->run($run, Graph::fromArray($version->graph), $nodeId);
```

Add imports for `ModelNotFoundException`, `CrossTenantExecutionException` and `FlowVersion`. Do not
read `TenantResolver`, alter run status, catch the exception, or move graph construction above the
tenant check.

- [ ] **Step 6: Run GREEN and execute both counterfactuals**

```bash
vendor/bin/pest tests/Feature/RunNodeActivityTest.php --compact
```

Counterfactual A: temporarily move `$run->increment('steps_taken')` above the mismatch check and run
the first test. Expected: it fails on `7` versus `8`. Restore.

Counterfactual B: temporarily remove the tenant comparison and run the first test. Expected: it
fails because no `CrossTenantExecutionException` is thrown and the recording runner is called.
Restore, rerun the full file, and record all outputs.

- [ ] **Step 7: Format, update evidence and commit GREEN**

```bash
/Users/mikelmao/Sites/test-workflow/vendor/bin/pint --test \
  src/Execution/CrossTenantExecutionException.php \
  src/Workflows/Activities/RunNodeActivity.php \
  tests/Support/RecordingNodeRunner.php \
  tests/Feature/RunNodeActivityTest.php
git diff --check
```

Apply scoped formatting if needed and rerun Step 6. Update the execution record, then:

```bash
git add src/Execution/CrossTenantExecutionException.php \
  src/Workflows/Activities/RunNodeActivity.php \
  tests/Support/RecordingNodeRunner.php tests/Feature/RunNodeActivityTest.php \
  docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "feat: assert tenants before node execution"
```

---

## Task 5: Public Documentation and Issue Reconciliation

**Files:**

- Modify: `README.md`
- Modify: `docs/gitbook/integration/tenancy.md`
- Modify: `docs/gitbook/experimental/known-limitations.md`
- Modify: `docs/documentation-changes.md`
- Modify: `docs/superpowers/open-issues.md`
- Update evidence: execution record

**Interfaces:**

- Consumes: exact class names, behavior, test counts and commit hashes from Tasks 1–4.
- Produces: public guidance that agrees with the shipped boundaries.

- [ ] **Step 1: Update README with the concise public contract**

Add a `## Tenancy safety` section after Capabilities. It must state:

```markdown
`nodeflow.tenancy=auto` inspects the bound `TenantResolver`: Nodeflow's fallback permits unscoped
reads when the host has no tenancy, while a host resolver returning null fails closed. Inspect the
effective decision with `app(\Nodeflow\Tenancy\TenancyDecisionResolver::class)->decision()` or run
`php artisan nodeflow:install --check`.

Eloquent writes reject missing or cross-tenant `Flow.current_version_id` and
`Run.flow_version_id` references. Durable node execution independently refuses a persisted
run/version tenant mismatch before incrementing or invoking a node. Query-builder and raw-SQL writes
bypass Eloquent model guards, so keep version and tenant foreign-key writes on model instances or in
equivalently validated trusted services.
```

Link the section to `docs/gitbook/integration/tenancy.md` rather than duplicating the full mode table.

- [ ] **Step 2: Rewrite the GitBook parent-invariant section**

In `docs/gitbook/integration/tenancy.md`:

- add a structured-diagnostic example showing `$decision->configuredMode`, `effectiveMode`,
  `resolverClass`, `nullTenantOutcome`, `inferred`, and `reason`;
- state that the installer uses the same resolver and its report does not change wiring exit code;
- replace “application code must preserve the rest” with the exact Flow/Run create/update guards;
- name `InvalidFlowVersionReferenceException`, `CrossTenantWriteException`, and
  `CrossTenantExecutionException`;
- state that `Flow.current_version_id` may be null and `Run.flow_version_id` may not;
- state that `TenancyGuardSuspension` does not bypass these structural guards;
- retain the warning against untrusted IDs; and
- retain and expand the query-builder/raw-SQL bypass warning.

Do not claim that the Flow guard proves the version belongs to that same Flow; it proves existence
and same tenant only. Do not claim existing rows were audited.

- [ ] **Step 3: Narrow the adjacent known limitation**

In `docs/gitbook/experimental/known-limitations.md`, replace “Parent-child tenant invariants depend
partly on host discipline” with a title such as “Model-event tenant guards can be bypassed.” State
that Flow/Run Eloquent writes now validate version existence and tenant equality, while query-builder
and raw SQL writes bypass those hooks, and other application-owned foreign-key writes still require
trusted input. Keep the mitigation focused on model-instance writes or equivalent explicit checks.

- [ ] **Step 4: Reconcile the documentation handoff and open issues**

In `docs/documentation-changes.md`, replace only the deferred D-1/D-2/G-3 bullet with a Plan 8
applied entry naming the implementation commits and public pages updated. Preserve C-series, G-13,
release work and the high-octal minor.

In `docs/superpowers/open-issues.md`:

- update D-1's follow-up status to resolved, preserving the historical auto-inference decision;
- update D-2 to resolved with the exception and pre-mutation tests;
- update G-3 to resolved with both model hooks, existence checks, suspension behavior, one-query
  cost and explicit query-builder bypass;
- correct the old rough “saving guard” wording to the shipped `creating`/`updating` ordering;
- do not rewrite historical Plan 3–7 counts; and
- update the current header/evidence block only with final Plan 8 measured totals after Task 6.

- [ ] **Step 5: Search for contradictory current guidance**

```bash
rg -n "application code must preserve|unimplemented|security-hardening plan|saving guard|parent-child tenant invariants|D-1|D-2|G-3|current_version_id|flow_version_id" \
  README.md docs/gitbook docs/documentation-changes.md docs/superpowers/open-issues.md
git diff --check
```

Expected: historical explanations remain where clearly time-scoped; current public guidance no
longer says D-1/D-2/G-3 are unimplemented or wholly host-enforced.

- [ ] **Step 6: Verify docs against focused behavior and commit**

```bash
vendor/bin/pest \
  tests/Feature/TenancyDecisionTest.php \
  tests/Feature/InstallCommandTest.php \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  tests/Feature/RunNodeActivityTest.php \
  --compact
git diff --check
```

Update the execution record with the documentation search and focused counts, then:

```bash
git add README.md docs/gitbook/integration/tenancy.md \
  docs/gitbook/experimental/known-limitations.md docs/documentation-changes.md \
  docs/superpowers/open-issues.md \
  docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "docs: publish tenancy hardening guarantees"
```

---

## Task 6: Whole-Branch Review, Gates and Integration Handoff

**Files:**

- Modify only if evidence requires it: Plan 8 production/tests/docs targets.
- Finalize: `docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md`

- [ ] **Step 1: Run focused security gates**

```bash
vendor/bin/pest \
  tests/Feature/TenancyDecisionTest.php \
  tests/Feature/TenancyModeTest.php \
  tests/Feature/TenancyAutoModeTest.php \
  tests/Feature/TenancyTest.php \
  tests/Feature/InstallCommandTest.php \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  tests/Feature/FlowVersionTenancyTest.php \
  tests/Feature/PublishFlowTest.php \
  tests/Feature/StartRunTest.php \
  tests/Feature/SubFlowStarterTest.php \
  tests/Feature/RunNodeActivityTest.php \
  --compact
```

Expected: all pass. Record measured tests/assertions.

- [ ] **Step 2: Run one scoped Pint gate over every changed PHP file**

```bash
/Users/mikelmao/Sites/test-workflow/vendor/bin/pint --test \
  src/Tenancy/TenancyDecision.php \
  src/Tenancy/TenancyDecisionResolver.php \
  src/NodeflowServiceProvider.php \
  src/Models/Concerns/BelongsToTenant.php \
  src/Console/InstallCommand.php \
  src/Models/InvalidFlowVersionReferenceException.php \
  src/Models/FlowVersionReferenceGuard.php \
  src/Models/CrossTenantWriteException.php \
  src/Models/Flow.php \
  src/Models/Run.php \
  src/Execution/CrossTenantExecutionException.php \
  src/Workflows/Activities/RunNodeActivity.php \
  tests/Feature/TenancyDecisionTest.php \
  tests/Feature/InstallCommandTest.php \
  tests/Feature/FlowVersionReferenceGuardTest.php \
  tests/Feature/RunNodeActivityTest.php \
  tests/Support/RecordingNodeRunner.php
```

Expected: PASS. A missing/unavailable Pint is a blocked required gate, not a pass.

- [ ] **Step 3: Run the complete package gates exactly once each**

```bash
COMPOSER_DISABLE_NETWORK=1 vendor/bin/pest --compact
npx vitest run
npx tsc --noEmit
composer validate --no-check-publish
git diff --check
git status --short
```

Expected: all pass. Record actual Pest tests/assertions and Vitest file/test totals; do not infer
them from the number of added tests. Status must be clean except for an intentional execution-record
edit awaiting its final commit.

- [ ] **Step 4: Perform fresh spec-compliance and code-quality review**

Review the complete branch diff against every P8-E1 through P8-E11 decision and acceptance criterion.
The review must explicitly probe:

- `disabled` with a non-null resolver still scopes;
- invalid mode with a non-null resolver still throws;
- a cached service sees a later binding/config change;
- Flow and Run create/update missing and cross-tenant references;
- suspension cannot bypass either reference guard;
- unrelated saves do not query FlowVersion;
- query-builder writes demonstrably bypass model events;
- D-2 mismatch and missing version occur before `steps_taken`, Graph construction and `NodeRunner`;
- D-2 succeeds with a matching persisted pair and null ambient tenant; and
- public docs do not overclaim same-Flow identity, database-wide enforcement, an existing-row audit,
  or run-status mutation.

If using `superpowers:subagent-driven-development`, this is the required two-stage spec and quality
review. If executing inline, invoke `superpowers:requesting-code-review`. Resolve every Critical or
Important finding with a fresh RED/GREEN cycle and rerun Steps 1–3.

- [ ] **Step 5: Repoint the exact demo symlink and run demo regression gates**

```bash
demo_link=/Users/mikelmao/Sites/test-workflow/vendor/atram/laravel-nodeflow
plan8_root=/Users/mikelmao/Projects/laravel-nodeflow/.claude/worktrees/plan-8-tenancy-security-hardening
main_root=/Users/mikelmao/Projects/laravel-nodeflow

test -L "$demo_link"
test "$(realpath "$demo_link")" = "$main_root"
unlink "$demo_link"
ln -s "$plan8_root" "$demo_link"
test "$(realpath "$demo_link")" = "$plan8_root"

cd /Users/mikelmao/Sites/test-workflow
vendor/bin/pest --compact
npx tsc --noEmit
npm run build
composer validate --no-check-publish
git status --short

unlink "$demo_link"
ln -s "$main_root" "$demo_link"
test "$(realpath "$demo_link")" = "$main_root"
git status --short
```

Expected: demo Pest remains 56 / 223 unless a separately explained test-only environment change
exists; TypeScript/build/Composer pass; demo stays clean; the link is restored to package `main`
even if a gate fails. If a command fails before restoration, restore the exact link before any
diagnosis or retry.

- [ ] **Step 6: Finalize and commit the execution record**

Record:

- every RED and GREEN commit hash;
- counterfactual mutations and their exact failures;
- review findings and remediation hashes;
- scoped Pint output;
- package and demo final counts/timings;
- demo link target before/during/after gates;
- remaining deferred items; and
- the fact that no remote was mutated.

Then:

```bash
git add docs/superpowers/plans/2026-08-23-tenancy-security-hardening-execution-record.md
git diff --cached --check
git commit -m "docs: record Plan 8 security acceptance"
git status --short --branch
git log --oneline --decorate -15
```

Expected: clean feature branch, complete evidence and no uncommitted source/docs changes.

- [ ] **Step 7: Use the finishing workflow for integration choice**

Invoke `superpowers:finishing-a-development-branch`. Before any local merge, re-verify package main,
demo main, the locked Plan 6 worktree and the demo symlink. Do not push or delete the Plan 8 branch or
worktree without the user's explicit choice.
