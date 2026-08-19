# Security Floor Implementation Plan (Plan 2 of 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the authorization and tenancy floor under the package **before any HTTP route exists**, so the editor cannot be built on a surface that leaks across tenants or answers to nobody.

**Architecture:** Three independent pieces. A `nodeflow.tenancy` mode that disambiguates what a `null` current tenant means, failing closed when the host says it has tenancy. `tenant_id` on `nodeflow_flow_versions` plus the trait, with the two internal call sites that adding a scope would silently break. And a pair of policies that delegate every decision to a host-registered gate and deny when no gate exists.

**Tech Stack:** PHP 8.3, Laravel 12/13 (`Illuminate\Support\Facades\Gate`, Eloquent global scopes), Pest 4, Orchestra Testbench 10/11.

**Spec:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — **§4** is this plan, **E1** and **E2** are its decisions, **§3** is why it gates Plan 3. The foundation spec `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` §4 lists the four gates and §9 the three-layer tenancy model.

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP `^8.3`.** Dependencies stay at `illuminate/*: ^12.0|^13.0`.
- **The 203 existing tests must stay green with no edits to them.** This is the plan's hardest constraint and it is achievable — see "The one design decision that makes this plan safe" below. If you find yourself editing an existing test to accommodate a change, stop and report it: it means the change is wrong, not the test.
- **`nodeflow.tenancy` defaults to `disabled`.** The package's own default `TenantResolver` binding returns `null` (`src/NodeflowServiceProvider.php:33`), so any other default breaks the package out of the box.
- **A non-null tenant always scopes, in every mode.** The mode governs *only* what a `null` return means. This is what keeps `TenancyTest` — which binds a resolver returning `org-1` and asserts scoping — passing unchanged.
- **Default deny.** A policy method whose gate the host has not defined returns `false`. Foundation spec §4: "Default deny unless a gate exists."
- **`RunSubject` and `NodeExecution` get no `tenant_id` column** (E1, §4.2). They are the six-figure tables and are only reachable through a `Run`, which is already scoped. Their invariant is structural and enforced by an architecture test.
- **Do not import `Workflow\` outside `src/Engine/` and `src/Workflows/`.** `tests/Unit/ArchitectureTest.php` enforces it over every `.php` file in `src/`.
- **For every test, name the production change that would make it fail.** If you cannot name one, the test is not finished. Plan 1 shipped two tests that could not detect the failure they named; do not add a third.
- **Test command:** `vendor/bin/pest`. Filter with `vendor/bin/pest --filter='<pattern>'`.

---

## The one design decision that makes this plan safe

`null` from `currentTenantId()` is overloaded. It means both:

1. **"This application has no tenancy"** — a single-tenant host that never binds a resolver. Reading unscoped is correct.
2. **"Tenancy is unresolved right now"** — a queue worker, a console command, an unauthenticated request. Reading unscoped is a cross-tenant leak.

`nodeflow.tenancy` chooses which reading applies. `disabled` → meaning 1, read unscoped. `resolver` → meaning 2, throw.

**Why this does not break the package's own cross-tenant reads.** A fan-out trigger legitimately reads every tenant's flows, and a queue worker legitimately loads a run it has no ambient tenant for. Those reads would break under a naive fail-closed rule — except that **all eleven of them already opt out explicitly with `withoutTenancy()`**. Verified by grep:

```
src/Triggers/EventTriggerListener.php:21     src/Execution/StartRun.php:31, :83
src/Triggers/SubFlowStarter.php:23          src/Nodes/Core/StartFlowNode.php:38
src/Workflows/Activities/LoadGraphActivity.php:12    src/Console/PruneCommand.php:35
src/Workflows/Activities/RunNodeActivity.php:14
src/Workflows/Activities/CompleteRunActivity.php:12
```

That is the property that makes fail-closed cheap here, and it is worth knowing it was checked rather than assumed.

**Two places are not covered by that, and Task 2 fixes both.** They are the reason adding a scope to `FlowVersion` is not a one-line change:

- `src/Console/CheckNodeTypesResolver.php:20` does `FlowVersion::query()`. `FlowVersion` is unscoped today, so this deploy-gate command sees every tenant's versions. The moment the trait lands, it silently checks only the ambient tenant's — or throws in `resolver` mode, since it runs in a console context with no tenant.
- `src/Models/FlowVersion.php:29` `hasLiveRuns()` queries `$this->runs()`, and `Run` **is** scoped. Called from the console command above, it would throw in `resolver` mode.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `src/Models/TenancyUnresolvedException.php` | The typed failure when the host says it has tenancy and none is resolved |
| `src/Policies/DelegatesToGate.php` | The one place that knows "no gate means deny" |
| `src/Policies/FlowPolicy.php` | `viewAny`, `view`, `update`, `publish`, `runManually` on a flow |
| `src/Policies/RunPolicy.php` | `viewAny`, `view` on a run |
| `tests/Support/RequestContextScanner.php` | Test-only static scanner backing the architecture test, so the guard itself is testable |
| `tests/Feature/TenancyModeTest.php` | The three `nodeflow.tenancy` states |
| `tests/Feature/FlowVersionTenancyTest.php` | `FlowVersion` scoping and the two regression sites |
| `tests/Feature/PolicyTest.php` | Gate delegation and default deny |
| `tests/Unit/RequestContextScannerTest.php` | Proves the scanner detects a violation |

**Modified:**

| Path | Change |
|---|---|
| `config/nodeflow.php` | Add `tenancy` with its explanatory comment |
| `src/Models/Concerns/BelongsToTenant.php:13-25` | Route the scope's tenant lookup through a new `resolveTenantIdForScope()` |
| `database/migrations/2026_08_18_000001_create_nodeflow_tables.php` | `tenant_id` on `nodeflow_flow_versions` |
| `src/Models/FlowVersion.php` | Add `BelongsToTenant`; make `hasLiveRuns()` tenancy-independent |
| `src/Publishing/PublishFlow.php:24-31` | Stamp `tenant_id` from the flow, not from ambient |
| `src/Console/CheckNodeTypesResolver.php:20` | `FlowVersion::withoutTenancy()` |
| `src/NodeflowServiceProvider.php` | Register the two policies |
| `tests/Unit/ArchitectureTest.php` | Add the request-context assertion |
| `docs/02-integration.md` | Document the gates and the tenancy mode |

---

## Task 1: `nodeflow.tenancy` and the fail-closed null

**Files:**
- Create: `src/Models/TenancyUnresolvedException.php`
- Modify: `config/nodeflow.php`
- Modify: `src/Models/Concerns/BelongsToTenant.php:13-25`
- Test: `tests/Feature/TenancyModeTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `Nodeflow\Models\TenancyUnresolvedException::__construct(string $modelClass)`. `BelongsToTenant::resolveTenantIdForScope(): ?string` — `protected static`, called by the global scope; Task 2 relies on it being the single lookup point.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TenancyModeTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;

/** Binds a resolver returning $tenantId, which may be null. */
function bindTenant(?string $tenantId): void
{
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
}

function makeFlowFor(string $tenantId): void
{
    Flow::withoutTenancy()->create([
        'tenant_id' => $tenantId,
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]);
}

it('reads unscoped when tenancy is disabled and no tenant resolves', function () {
    // Meaning 1 of null: the application has no tenancy. This is the package's
    // out-of-the-box behaviour and the default TenantResolver returns null.
    config()->set('nodeflow.tenancy', 'disabled');
    bindTenant(null);

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(2);
});

it('throws when tenancy is resolver-managed and no tenant resolves', function () {
    // Meaning 2 of null: a queue worker or unauthenticated request. Reading
    // unscoped here is a cross-tenant leak, so it must fail loudly.
    // Counterfactual: delete the throw and this returns 2 instead of throwing.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    makeFlowFor('org-1');

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('names the model and the escape hatch when it throws', function () {
    // A fail-closed error the reader cannot act on just gets the mode switched
    // back off, which would defeat the whole change.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    expect(fn () => Flow::count())->toThrow(
        TenancyUnresolvedException::class,
        'Nodeflow\Models\Flow',
    );

    try {
        Flow::count();
    } catch (TenancyUnresolvedException $e) {
        expect($e->getMessage())
            ->toContain('withoutTenancy()')
            ->toContain('nodeflow.tenancy');
    }
});

it('scopes normally in resolver mode when a tenant does resolve', function () {
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant('org-1');

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(1);
});

it('scopes normally in disabled mode when a tenant does resolve', function () {
    // The mode governs only what NULL means. A non-null tenant scopes in both
    // modes — this is what keeps every existing tenancy test passing.
    // Counterfactual: make 'disabled' skip scoping entirely and this returns 2.
    config()->set('nodeflow.tenancy', 'disabled');
    bindTenant('org-1');

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::count())->toBe(1);
});

it('lets an explicit system read through in resolver mode', function () {
    // withoutTenancy() is how all eleven package-internal cross-tenant reads
    // work; if the throw fired before the scope was removed, every fan-out
    // trigger and queue activity would break.
    config()->set('nodeflow.tenancy', 'resolver');
    bindTenant(null);

    makeFlowFor('org-1');
    makeFlowFor('org-2');

    expect(Flow::withoutTenancy()->count())->toBe(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='TenancyMode'`

Expected: FAIL. `Class "Nodeflow\Models\TenancyUnresolvedException" not found`.

- [ ] **Step 3: Write the exception**

Create `src/Models/TenancyUnresolvedException.php`:

```php
<?php

namespace Nodeflow\Models;

use RuntimeException;

/**
 * Thrown when a tenant-scoped read happens with no resolvable tenant, and the
 * application has declared (via nodeflow.tenancy = 'resolver') that it has
 * tenancy — so a null tenant means "could not resolve", not "not applicable".
 *
 * The message has to be actionable. An error a reader cannot act on gets
 * answered by switching the mode back to 'disabled', which would defeat the
 * point of failing closed at all.
 */
class TenancyUnresolvedException extends RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "Tenancy is unresolved: reading {$modelClass} requires a current tenant because "
            ."nodeflow.tenancy is set to 'resolver' and TenantResolver::currentTenantId() returned null. "
            ."Either resolve a tenant for this context, or call {$modelClass}::withoutTenancy() if this "
            .'read is a deliberate system-wide operation such as a cross-tenant fan-out. Set '
            .'nodeflow.tenancy to "disabled" only if the application genuinely has no tenancy at all.'
        );
    }
}
```

- [ ] **Step 4: Add the config key**

In `config/nodeflow.php`, add this entry immediately after the `'limits' => [...]` line:

```php
    /*
     * What a null return from TenantResolver::currentTenantId() means.
     *
     * 'disabled' — the application has no tenancy, so a null tenant reads
     *   unscoped. This is the default because the package's own fallback
     *   TenantResolver returns null, and a single-tenant host that never binds
     *   a resolver must work out of the box.
     *
     * 'resolver' — the application has tenancy, so a null tenant means it could
     *   not be resolved: a queue worker, a console command, an unauthenticated
     *   request. Scoped reads throw rather than silently returning every
     *   tenant's rows. The package's own cross-tenant reads are unaffected —
     *   they opt out with withoutTenancy() explicitly.
     *
     * A non-null tenant always scopes, in both modes. This setting governs only
     * what null means.
     */
    'tenancy' => env('NODEFLOW_TENANCY', 'disabled'),
```

- [ ] **Step 5: Route the scope through one lookup point**

In `src/Models/Concerns/BelongsToTenant.php`, change the first line of the global scope closure from:

```php
            $tenantId = app(TenantResolver::class)->currentTenantId();
```

to:

```php
            $tenantId = static::resolveTenantIdForScope();
```

Then add this method to the trait, directly above `withoutTenancy()`:

```php
    /**
     * The ambient tenant for a scoped read, or null when reading unscoped is the
     * declared intent.
     *
     * Null from the resolver is overloaded: it means both "this application has
     * no tenancy" (a single-tenant host that never binds a resolver — including
     * the package's own fallback binding) and "tenancy is unresolved right now"
     * (a queue worker, a console command, an unauthenticated request). Reading
     * unscoped is correct for the first and a cross-tenant leak for the second,
     * and nothing in the null itself distinguishes them — so the host declares
     * which it means via nodeflow.tenancy.
     *
     * Package-internal reads that legitimately cross tenants never reach this:
     * every one of them opts out with withoutTenancy() before the scope applies.
     *
     * @throws \Nodeflow\Models\TenancyUnresolvedException
     */
    protected static function resolveTenantIdForScope(): ?string
    {
        $tenantId = app(TenantResolver::class)->currentTenantId();

        if ($tenantId === null && config('nodeflow.tenancy') === 'resolver') {
            throw new TenancyUnresolvedException(static::class);
        }

        return $tenantId;
    }
```

Add the import below `use Nodeflow\Models\CrossTenantWriteException;`:

```php
use Nodeflow\Models\TenancyUnresolvedException;
```

Note the `creating()` hook is deliberately **not** routed through this method. It does not read, so it cannot leak; and stamping a null `tenant_id` on a model created in a system context is existing, tested behaviour (`TenancyTest`'s "allows explicit tenant_id when no tenant is resolved").

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='TenancyMode'`

Expected: PASS, 6 tests.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 209 tests. **No existing test may be edited to achieve this.** If `TenancyTest`, `EventTriggerTest`, `StartRunTest` or `SubFlowStarterTest` fail, the default is wrong or the mode is being consulted for a non-null tenant — fix the implementation, not the tests.

- [ ] **Step 8: Commit**

```bash
git add src/Models/TenancyUnresolvedException.php config/nodeflow.php src/Models/Concerns/BelongsToTenant.php tests/Feature/TenancyModeTest.php
git commit -m "feat: disambiguate a null tenant with nodeflow.tenancy"
```

---

## Task 2: Scope `FlowVersion`

**Files:**
- Modify: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Modify: `src/Models/FlowVersion.php`
- Modify: `src/Publishing/PublishFlow.php:24-31`
- Modify: `src/Console/CheckNodeTypesResolver.php:20`
- Test: `tests/Feature/FlowVersionTenancyTest.php`

**Interfaces:**
- Consumes: Task 1's `resolveTenantIdForScope()` — inherited via the trait, no direct call.
- Produces: `FlowVersion` rows carry `tenant_id`, stamped from their flow. `FlowVersion::withoutTenancy()` becomes available (from the trait) and is required by any system-wide read.

**Why this task is not one line.** Adding the trait changes the behaviour of two existing call sites that currently rely on `FlowVersion` being unscoped. Both are fixed here, and both get a regression test that fails if the fix is reverted.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FlowVersionTenancyTest.php`:

```php
<?php

use Nodeflow\Console\CheckNodeTypesResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;

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
});

/**
 * A flow with one published version whose graph references $type, plus a live run.
 *
 * Wrapped in TenancyGuardSuspension because these tests seed rows for a tenant
 * other than the ambient one, and BelongsToTenant's creating() hook throws
 * CrossTenantWriteException on exactly that. Suspension disables only that
 * throw, never the read scope — which is the thing under test here. This is the
 * same mechanism StartRun uses for its own cross-tenant fan-out writes.
 */
function seedVersionWithLiveRun(string $tenantId, string $type): FlowVersion
{
    return \Nodeflow\Models\Concerns\TenancyGuardSuspension::run(function () use ($tenantId, $type) {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => 'A',
            'trigger_type' => 'manual',
            'status' => 'active',
        ]);

        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'tenant_id' => $tenantId,
            'version' => 1,
            'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => $type, 'config' => []]], 'edges' => []],
            'content_hash' => 'x',
            'published_at' => now(),
        ]);

        Run::withoutTenancy()->create([
            'flow_version_id' => $version->id,
            'tenant_id' => $tenantId,
            'strategy' => 'cohort',
            'status' => 'waiting',
        ]);

        return $version;
    });
}

it('hides another tenants flow versions', function () {
    // Counterfactual: remove BelongsToTenant from FlowVersion and this returns 2.
    // This is the read the handoff named: FlowVersion::find($request->version)
    // becomes a cross-tenant read the moment a route exists.
    seedVersionWithLiveRun('org-1', 'core.exit');
    seedVersionWithLiveRun('org-2', 'core.exit');

    expect(FlowVersion::count())->toBe(1);

    $this->tenant = 'org-2';

    expect(FlowVersion::count())->toBe(1);
});

it('stamps a version with its flows tenant, not the ambient one', function () {
    // Counterfactual: drop 'tenant_id' from PublishFlow's create() and the row
    // gets the ambient tenant — null in a console or queue publish, which would
    // then be invisible to every scoped read.
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = null;

    $version = app(\Nodeflow\Publishing\PublishFlow::class)->publish($flow, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    expect($version->tenant_id)->toBe('org-1');
});

it('checks node types across every tenant, not just the ambient one', function () {
    // The regression this task exists to prevent. check-node-types is a deploy
    // gate: it must see every tenant's live versions. Counterfactual: drop
    // withoutTenancy() from CheckNodeTypesResolver and this finds 1, not 2 —
    // or throws, in resolver mode with no ambient tenant.
    seedVersionWithLiveRun('org-1', 'gone.missing');
    seedVersionWithLiveRun('org-2', 'gone.missing');

    config()->set('nodeflow.tenancy', 'resolver');
    $this->tenant = null;

    $missing = CheckNodeTypesResolver::findMissingTypes(app(NodeRegistry::class));

    expect($missing)->toHaveCount(2);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='FlowVersionTenancy'`

Expected: FAIL. The first test returns 2 (no scope yet); the migration has no `tenant_id`, so `withoutTenancy()` does not exist on `FlowVersion` and the seed helper errors.

- [ ] **Step 3: Add the column**

In `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`, the `nodeflow_flow_versions` block currently begins:

```php
        Schema::create('nodeflow_flow_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('nodeflow_flows')->cascadeOnDelete();
```

Insert the tenant column immediately after `$t->id();`:

```php
        Schema::create('nodeflow_flow_versions', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->foreignId('flow_id')->constrained('nodeflow_flows')->cascadeOnDelete();
```

Nothing is installed anywhere, so this edits the existing migration rather than adding one — the same choice Plan 1 made for `draft_graph`.

- [ ] **Step 4: Add the trait and make `hasLiveRuns()` tenancy-independent**

In `src/Models/FlowVersion.php`, add the trait import and use, and change `hasLiveRuns()`. The class becomes:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Nodeflow\Models\Concerns\BelongsToTenant;

class FlowVersion extends Model
{
    use BelongsToTenant;

    protected $table = 'nodeflow_flow_versions';

    protected $guarded = [];

    protected $casts = ['graph' => 'array', 'published_at' => 'datetime'];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * Deliberately reads without tenancy. A version's own row is already scoped,
     * so reaching it at all proves the caller is entitled to it — and the
     * question "does anything still depend on this version" is a system
     * question, asked by the boot-time and deploy-time node type checks in a
     * console context with no ambient tenant. Scoping it there would answer
     * "no live runs" for every version in the fleet and silently disarm the
     * check.
     */
    public function hasLiveRuns(): bool
    {
        return Run::withoutTenancy()
            ->where('flow_version_id', $this->id)
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->exists();
    }
}
```

- [ ] **Step 5: Stamp the version from its flow**

In `src/Publishing/PublishFlow.php`, the `FlowVersion::create([...])` call currently begins:

```php
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
```

Change it to carry the flow's tenant explicitly:

```php
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
                // From the flow, never from the ambient tenant. The flow was
                // reached through a scoped read, so it is the authority on which
                // tenant this version belongs to — and a publish can legitimately
                // happen in a console or queue context with no ambient tenant,
                // where stamping ambient would write null and make the version
                // invisible to every scoped read afterwards.
                'tenant_id' => $flow->tenant_id,
```

- [ ] **Step 6: Keep the deploy gate cross-tenant**

In `src/Console/CheckNodeTypesResolver.php`, change:

```php
        FlowVersion::query()->with('flow')->chunk(100, function ($versions) use ($registry, &$missing) {
```

to:

```php
        // Explicitly cross-tenant. This is a deploy gate: a version belonging to
        // any tenant whose type no longer resolves is a run that will fail at
        // resume, possibly days into a wait. Scoping this to the ambient tenant —
        // which is null in the console context it runs in — would silently check
        // nothing.
        FlowVersion::withoutTenancy()->with('flow')->chunk(100, function ($versions) use ($registry, &$missing) {
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='FlowVersionTenancy'`

Expected: PASS, 3 tests.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 212 tests. `PublishFlowTest` and `NodeTypeResolutionTest` are the two most likely to surface a mistake here — both create `FlowVersion` rows. If either fails because a row now needs a `tenant_id`, the fix belongs in the production code path that creates it, not in the test.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_08_18_000001_create_nodeflow_tables.php src/Models/FlowVersion.php src/Publishing/PublishFlow.php src/Console/CheckNodeTypesResolver.php tests/Feature/FlowVersionTenancyTest.php
git commit -m "feat: tenant-scope FlowVersion without disarming the deploy gate"
```

---

## Task 3: The structural invariant for `RunSubject` and `NodeExecution`

**Files:**
- Create: `tests/Support/RequestContextScanner.php`
- Create: `tests/Unit/RequestContextScannerTest.php`
- Modify: `tests/Unit/ArchitectureTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `Tests\Support\RequestContextScanner::violations(string $root, array $allowedPathFragments): array` returning `string[]` of `"relative/path.php: ModelName"`. `RequestContextScanner::FORBIDDEN` is `['RunSubject', 'NodeExecution']`.

**Why a scanner rather than a bare test.** E1 and §4.2 give these two tables no `tenant_id`: they are the six-figure tables and are only reachable through a `Run`, which is scoped. The invariant is therefore structural — "never query them outside the execution internals" — and a test that merely greps an empty future directory would pass while proving nothing. Extracting the detector lets it be tested against a known violation, so the guard is armed rather than decorative.

- [ ] **Step 1: Write the failing scanner test**

Create `tests/Unit/RequestContextScannerTest.php`:

```php
<?php

use Tests\Support\RequestContextScanner;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/nodeflow-scan-'.bin2hex(random_bytes(6));
    mkdir($this->root.'/Http', 0777, true);
    mkdir($this->root.'/Execution', 0777, true);
});

afterEach(function () {
    foreach (['Http', 'Execution'] as $dir) {
        foreach (glob($this->root.'/'.$dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->root.'/'.$dir);
    }
    rmdir($this->root);
});

it('detects a forbidden model queried outside the allowed paths', function () {
    // The counterfactual for the whole scanner: if violations() returned [] here,
    // the architecture test built on it would be decorative.
    file_put_contents(
        $this->root.'/Http/FlowController.php',
        '<?php RunSubject::where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/FlowController.php: RunSubject']);
});

it('allows the same query inside an allowed path', function () {
    file_put_contents(
        $this->root.'/Execution/NodeRunner.php',
        '<?php RunSubject::where("run_id", 1)->get();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});

it('detects both forbidden models and reports each once per file', function () {
    file_put_contents(
        $this->root.'/Http/RunController.php',
        '<?php NodeExecution::sum("subject_count"); RunSubject::count(); NodeExecution::count();'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))
        ->toBe(['Http/RunController.php: NodeExecution', 'Http/RunController.php: RunSubject']);
});

it('ignores a mention that is not a static call', function () {
    // A docblock or a type import naming the model is not a query.
    file_put_contents(
        $this->root.'/Http/Fine.php',
        '<?php use Nodeflow\Models\RunSubject; /** returns RunSubject rows */'
    );

    expect(RequestContextScanner::violations($this->root, ['/Execution/']))->toBe([]);
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest --filter='RequestContextScanner'`

Expected: FAIL. `Class "Tests\Support\RequestContextScanner" not found`.

- [ ] **Step 3: Write the scanner**

Create `tests/Support/RequestContextScanner.php`:

```php
<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Finds queries against models that carry no tenant_id of their own.
 *
 * RunSubject and NodeExecution are the high-volume tables, so they were
 * deliberately given no tenant column (spec E1): they are only ever reachable
 * through a Run, which is tenant-scoped. That makes their isolation structural
 * rather than enforced by a scope — query them directly from a request-context
 * class and there is nothing between the caller and every tenant's rows.
 *
 * Matching on `Model::` catches a static query entry point while ignoring an
 * import or a docblock mention, which are not queries.
 */
class RequestContextScanner
{
    public const FORBIDDEN = ['RunSubject', 'NodeExecution'];

    /**
     * @param  string  $root  directory to scan
     * @param  string[]  $allowedPathFragments  path fragments exempt from the rule
     * @return string[] sorted "relative/path.php: ModelName", one per (file, model)
     */
    public static function violations(string $root, array $allowedPathFragments): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getPathname());

            foreach ($allowedPathFragments as $fragment) {
                if (str_contains($path, $fragment)) {
                    continue 2;
                }
            }

            $contents = file_get_contents($file->getPathname());
            $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

            foreach (self::FORBIDDEN as $model) {
                if (preg_match('/\b'.$model.'::/', $contents) === 1) {
                    $violations[] = "{$relative}: {$model}";
                }
            }
        }

        sort($violations);

        return $violations;
    }
}
```

- [ ] **Step 4: Run the scanner tests to verify they pass**

Run: `vendor/bin/pest --filter='RequestContextScanner'`

Expected: PASS, 4 tests.

- [ ] **Step 5: Arm the architecture test**

Append to `tests/Unit/ArchitectureTest.php`:

```php
it('keeps RunSubject and NodeExecution out of everything but the execution internals', function () {
    // Spec E1: these two carry no tenant_id — they are the six-figure tables and
    // are only reachable through a Run, which is scoped. So the isolation is
    // structural, and this is the thing that keeps it structural once Plan 3
    // adds controllers. The allowlist is the set of places that legitimately
    // query them today: the interpreter internals and the prune command, which
    // is explicitly a cross-tenant system operation.
    //
    // Counterfactual: add `RunSubject::where(...)` to any file in src/ outside
    // the allowlist and this fails, naming the file.
    $violations = Tests\Support\RequestContextScanner::violations(
        __DIR__.'/../../src',
        ['/src/Execution/', '/src/Console/PruneCommand.php'],
    );

    expect($violations)->toBe([]);
});
```

- [ ] **Step 6: Run the architecture test**

Run: `vendor/bin/pest --filter='Architecture'`

Expected: PASS, 2 tests. If it fails naming a file, that file is querying one of the two models from outside the allowlist — read it before widening the allowlist, because widening it is how this guard becomes decorative.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 217 tests.

- [ ] **Step 8: Commit**

```bash
git add tests/Support/RequestContextScanner.php tests/Unit/RequestContextScannerTest.php tests/Unit/ArchitectureTest.php
git commit -m "test: keep the untenanted tables out of request-context code"
```

---

## Task 4: Authorization gates and policies

**Files:**
- Create: `src/Policies/DelegatesToGate.php`
- Create: `src/Policies/FlowPolicy.php`
- Create: `src/Policies/RunPolicy.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Feature/PolicyTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: four gate names the host defines — `nodeflow.viewAny`, `nodeflow.update`, `nodeflow.publish`, `nodeflow.runManually`. `FlowPolicy` methods `viewAny`, `view`, `update`, `publish`, `runManually`; `RunPolicy` methods `viewAny`, `view`. Plan 3's controllers call `$this->authorize('update', $flow)` and `$this->authorize('viewAny', Run::class)` against these.

**Why `runManually` sits on `FlowPolicy`.** You manually run a *flow*; the `Run` is the result. Putting it on `RunPolicy` would require a `Run` that does not exist yet at the moment of the decision.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/PolicyTest.php`:

```php
<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    // Assigned rather than mass-assigned: Model's default $guarded is ['*'], so
    // new User(['id' => 1]) would silently leave id unset and the
    // "passes the user through" test below would assert against null.
    $this->user = new User;
    $this->user->id = 1;
});

it('denies every flow ability when the host has defined no gates', function (string $ability) {
    // Foundation spec §4: default deny unless a gate exists. Counterfactual:
    // return true when Gate::has() is false and every one of these passes.
    expect(Gate::forUser($this->user)->allows($ability, $this->flow))->toBeFalse();
})->with(['view', 'update', 'publish', 'runManually']);

it('denies run abilities when the host has defined no gates', function () {
    // A Run needs a real flow_version_id: nodeflow_runs.flow_version_id is a
    // non-nullable constrained foreign key, so passing null fails on insert
    // rather than testing anything about policies.
    $version = \Nodeflow\Models\FlowVersion::create([
        'flow_id' => $this->flow->id,
        'tenant_id' => 'org-1',
        'version' => 1,
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]], 'edges' => []],
        'content_hash' => 'x',
        'published_at' => now(),
    ]);

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    expect(Gate::forUser($this->user)->allows('view', $run))->toBeFalse();
});

it('allows an ability when the hosts gate allows it', function () {
    Gate::define('nodeflow.update', fn ($user, $flow) => true);

    expect(Gate::forUser($this->user)->allows('update', $this->flow))->toBeTrue();
});

it('denies an ability when the hosts gate denies it', function () {
    // Counterfactual: ignore the gate's return value and this passes while
    // every host authorization rule is silently bypassed.
    Gate::define('nodeflow.update', fn ($user, $flow) => false);

    expect(Gate::forUser($this->user)->allows('update', $this->flow))->toBeFalse();
});

it('passes the user and the model through to the hosts gate', function () {
    // The gate cannot make a real decision without both. Counterfactual: drop
    // the model from the forwarded arguments and $received stays null.
    $receivedUser = null;
    $receivedFlow = null;

    Gate::define('nodeflow.publish', function ($user, $flow) use (&$receivedUser, &$receivedFlow) {
        $receivedUser = $user;
        $receivedFlow = $flow;

        return true;
    });

    Gate::forUser($this->user)->allows('publish', $this->flow);

    expect($receivedUser?->id)->toBe(1)
        ->and($receivedFlow?->id)->toBe($this->flow->id);
});

it('maps viewing a flow to the viewAny gate rather than inventing a fifth', function () {
    // The spec names exactly four gates. Counterfactual: add a nodeflow.view
    // gate and this fails, catching the drift.
    Gate::define('nodeflow.viewAny', fn ($user, $flow = null) => true);

    expect(Gate::forUser($this->user)->allows('view', $this->flow))->toBeTrue();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='PolicyTest'`

Expected: FAIL. With no policy registered, `Gate::allows('update', $flow)` returns `false` for the deny tests (passing by accident) but the "allows when the host's gate allows" test fails — there is nothing routing `update` to `nodeflow.update`.

- [ ] **Step 3: Write the gate-delegating base**

Create `src/Policies/DelegatesToGate.php`:

```php
<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Every package policy decision is the host's to make.
 *
 * The package stores an opaque tenant_id and knows nothing about users, roles
 * or plans, so it cannot answer "may this person publish this flow". It asks,
 * via a named gate the host defines — and denies when the host has not defined
 * one, because the alternative is a package that ships open by default and
 * relies on every integrator noticing.
 *
 * A host wanting finer control replaces the policy class outright; these exist
 * so that the common case is one Gate::define() per ability.
 */
abstract class DelegatesToGate
{
    protected function decide(string $gate, ?Authenticatable $user, mixed $model = null): bool
    {
        if (! Gate::has($gate)) {
            return false;
        }

        return Gate::forUser($user)->allows($gate, $model === null ? [] : [$model]);
    }
}
```

- [ ] **Step 4: Write the two policies**

Create `src/Policies/FlowPolicy.php`:

```php
<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Nodeflow\Models\Flow;

class FlowPolicy extends DelegatesToGate
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->decide('nodeflow.viewAny', $user);
    }

    /**
     * Viewing one flow maps to the same gate as listing them. The spec names
     * four gates, and a fifth invented here would be a gate no host knows to
     * define — which, under default deny, reads as the package being broken.
     */
    public function view(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.viewAny', $user, $flow);
    }

    public function update(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.update', $user, $flow);
    }

    public function publish(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.publish', $user, $flow);
    }

    /**
     * On the flow, not the run: you manually start a flow, and the run is the
     * result. A RunPolicy method would need a Run that does not exist yet at
     * the moment the decision is made.
     */
    public function runManually(?Authenticatable $user, Flow $flow): bool
    {
        return $this->decide('nodeflow.runManually', $user, $flow);
    }
}
```

Create `src/Policies/RunPolicy.php`:

```php
<?php

namespace Nodeflow\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Nodeflow\Models\Run;

class RunPolicy extends DelegatesToGate
{
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->decide('nodeflow.viewAny', $user);
    }

    public function view(?Authenticatable $user, Run $run): bool
    {
        return $this->decide('nodeflow.viewAny', $user, $run);
    }
}
```

- [ ] **Step 5: Register the policies**

In `src/NodeflowServiceProvider.php`, add to the top of `boot()`, before the `runningInConsole()` block:

```php
        // Registered unconditionally: the run view and the editor both authorize
        // on every request, and a policy registered only in some contexts is a
        // policy that silently does not apply in the others.
        \Illuminate\Support\Facades\Gate::policy(
            \Nodeflow\Models\Flow::class,
            \Nodeflow\Policies\FlowPolicy::class,
        );

        \Illuminate\Support\Facades\Gate::policy(
            \Nodeflow\Models\Run::class,
            \Nodeflow\Policies\RunPolicy::class,
        );
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='PolicyTest'`

Expected: PASS, 9 tests (the four-case dataset counts as four).

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 226 tests.

- [ ] **Step 8: Commit**

```bash
git add src/Policies tests/Feature/PolicyTest.php src/NodeflowServiceProvider.php
git commit -m "feat: add default-deny policies delegating to host gates"
```

---

## Task 5: Document the floor

**Files:**
- Modify: `docs/02-integration.md`

**Interfaces:**
- Consumes: the four gate names and the `nodeflow.tenancy` values from Tasks 1 and 4.
- Produces: nothing consumed by code.

- [ ] **Step 1: Read the surrounding structure**

Run: `grep -n '^#\{2,3\} ' docs/02-integration.md`

Identify the section covering the `TenantResolver` contract, and the end of the wiring walkthrough. The tenancy-mode paragraph belongs with the resolver; the gates section belongs after the wiring, since it is a fifth thing to wire.

- [ ] **Step 2: Document the tenancy mode**

Add to the `TenantResolver` section, after its existing prose:

```markdown
### Which kind of null you mean

`currentTenantId()` returning `null` is ambiguous, and the package cannot guess:
it means either "this application has no tenancy" or "tenancy could not be
resolved here". Reading unscoped is correct for the first and a cross-tenant leak
for the second, so you declare which you mean:

```php
// config/nodeflow.php
'tenancy' => 'resolver',   // or 'disabled'
```

- **`disabled`** (the default) — no tenancy. A null tenant reads unscoped. Correct
  for a single-tenant application that never binds a resolver.
- **`resolver`** — you have tenancy, so a null tenant means it could not be
  resolved: a queue worker, a console command, an unauthenticated request. Scoped
  reads throw `Nodeflow\Models\TenancyUnresolvedException` instead of quietly
  returning every tenant's rows.

**If you implement `TenantResolver`, set this to `resolver`.** A non-null tenant
scopes identically in both modes; the setting governs only the null case.

System operations that genuinely span tenants opt out explicitly with
`Model::withoutTenancy()` — the package's own fan-out triggers and queue
activities all do, so `resolver` does not break them.
```

- [ ] **Step 3: Document the gates**

Add a new section after the wiring walkthrough:

```markdown
## Authorization: four gates

The package makes no authorization decisions. It ships policies for `Flow` and
`Run` that defer every question to a gate you define, and **deny when the gate
does not exist** — so a fresh install refuses everything until you say otherwise,
rather than shipping open and relying on you noticing.

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('nodeflow.viewAny', fn ($user) => $user->can('journeys.read'));
    Gate::define('nodeflow.update', fn ($user, $flow) => $user->organization_id === $flow->tenant_id);
    Gate::define('nodeflow.publish', fn ($user, $flow) => $user->isAdmin());
    Gate::define('nodeflow.runManually', fn ($user, $flow) => $user->isAdmin());
}
```

| Gate | Asked when |
|---|---|
| `nodeflow.viewAny` | Listing flows or runs, and viewing one of either |
| `nodeflow.update` | Editing a flow, saving a draft, resolving field options |
| `nodeflow.publish` | Freezing a new version |
| `nodeflow.runManually` | Starting a run by hand, including a test-mode run |

The second argument is the `Flow` or `Run` in question, absent for the list
case — so a gate signature should default it: `fn ($user, $flow = null) => ...`.

Tenant isolation is **not** your gate's job: the models are already scoped, so a
cross-tenant id is a 404 before any gate runs. Gates answer "may this person do
this", not "is this row theirs".

A host needing more than a gate can bind its own policy class over the
package's with `Gate::policy(Flow::class, YourPolicy::class)` in a provider that
boots after this one.
```

- [ ] **Step 4: Verify the diff is insertions only**

Run: `git diff --stat docs/02-integration.md`

Expected: insertions only, zero deletions. A non-zero deletion count means the insertion overwrote existing prose — revert and redo.

- [ ] **Step 5: Run the suite**

Run: `vendor/bin/pest`

Expected: PASS, 226 tests. No code changed, but confirm the tree is green before committing.

- [ ] **Step 6: Commit**

```bash
git add docs/02-integration.md
git commit -m "docs: document the tenancy mode and the four authorization gates"
```

---

## Definition of done

- `vendor/bin/pest` passes at **226 tests**, with all 203 pre-existing tests unedited.
- `nodeflow.tenancy` defaults to `disabled`; `resolver` + a null tenant throws `TenancyUnresolvedException` naming the model and the escape hatch.
- A non-null tenant scopes identically in both modes.
- `FlowVersion` is tenant-scoped, stamped from its flow rather than from ambient.
- `nodeflow:check-node-types` still sees every tenant's versions — with a test that fails if that regresses.
- Every `Flow` and `Run` ability denies when its gate is undefined, and forwards both the user and the model when it is defined.
- The architecture test names any file outside `src/Execution/` and `PruneCommand` that queries `RunSubject` or `NodeExecution`, and the scanner behind it has its own tests proving it detects a violation.

## Deliberately not in this plan

- **Routes, controllers, or anything HTTP.** That is Plan 3. This plan exists so Plan 3 has a floor to stand on.
- **`draft_graph` on `nodeflow_flows`** — Plan 3 (spec §5.1), even though it is a migration change like Task 2's.
- **A set-shaped `ownsSubject()` contract** — a carried-forward follow-up from the foundation work, not in the editor's path.
- **`runs.status` never reaching a failure state** — same.
- **Changing the mandatory audience ownership check (D12)** — it is already mandatory and non-disableable; this plan does not touch it.
- **The two residuals parked by Plan 1** (the `{{ outputs }}` channel in `--group`, and the `node.both.stub` drift blindness). Unrelated to the floor.
