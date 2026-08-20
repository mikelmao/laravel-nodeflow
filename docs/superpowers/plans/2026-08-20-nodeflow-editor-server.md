# Editor Server Surface Implementation Plan (Plan 3a of 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship the complete HTTP surface the editor needs — draft autosave, publish with per-node errors, and tenant-scoped field options — so that a React client has an API to talk to before any React exists.

**Architecture:** Opt-in routes registered by the host via `Nodeflow::routes()`, two thin controllers over three small action/contract units, all authorized by Plan 2's policies and scoped by Plan 2's models. Nothing here renders a component; Plan 3b is the client.

**Tech Stack:** PHP 8.3, Laravel 12/13, Pest 4, Orchestra Testbench 10/11, `inertiajs/inertia-laravel` as a dev/suggest dependency only.

**Spec:** `docs/superpowers/specs/2026-08-19-editor-and-node-tooling-design.md` — **§5.1–5.4** and **E3**, **E4**, **E6** are this plan; **E2a** is Task 1; **§4** is the floor it stands on, already delivered. Foundation spec `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md` §4 requires that an engine-only host works without the editor.

**Open issues this plan must respect:** `docs/superpowers/open-issues.md` — **G-3** (the FK invariant: controllers must never accept `current_version_id` or `flow_version_id` from request input) and **G-1** (the scanner misses aliased raw-table forms).

## Global Constraints

Every task's requirements implicitly include this section.

- **PHP `^8.3`.** Dependencies stay at `illuminate/*: ^12.0|^13.0`.
- **The 259 existing tests must pass with no edits to any of them — with exactly one carve-out, named in Task 4.** If you find yourself editing any other test, stop and report: it means the change is wrong. Adding cases to an existing test *file* is fine.
  - The carve-out: `tests/Unit/FieldTest.php:20` and `:50` assert that `Field::toArray()` emits `options_source`, and spec E6 requires that key to stop existing. That is a test asserting a contract this plan deliberately changes, not a test catching a mistake, so it is amended rather than worked around. Task 4 says exactly how.
- **`inertiajs/inertia-laravel` is never a hard requirement.** Foundation spec §4: "A host that wants only the engine — running flows with no editor — must be able to have that." Routes are opt-in, so an engine-only host never loads a controller. Inertia goes in `require-dev` and `suggest`.
- **Controllers never accept `current_version_id` or `flow_version_id` from request input.** Open issue G-3: Plan 2's unscoped `Run::flowVersion()` / `Flow::currentVersion()` / `Flow::versions()` relations rest on those FKs staying inside the parent's tenant, and nothing enforces it. This is the whole mitigation.
- **The options endpoint never accepts a class name from the client.** Spec E6. It takes `(node type, field key)` and resolves the source from the node's own `definition()`. A client-supplied class name would be "instantiate any class in this application and call `options()` on it".
- **`options_source` must not appear in the palette JSON as a class name.** The browser needs to know only *that* a field is dynamic.
- **A cross-tenant id is a 404, never a 403.** A 403 confirms the row exists.
- **Draft saves do not validate the graph.** A draft is allowed to be broken mid-edit; that is why it is not a version (E3).
- **Do not query `RunSubject` or `NodeExecution` from anything under `src/Http/`.** `tests/Unit/ArchitectureTest.php` enforces it and will name your file.
- **Do not import `Workflow\` outside `src/Engine/` and `src/Workflows/`.**
- **For every test, name the production change that would make it fail.** If you cannot, the test is not finished.
- **Test command:** `vendor/bin/pest`. Filter with `vendor/bin/pest --filter='<pattern>'`.

---

## Two decisions this plan makes that the spec left open

**1. Inertia is a dev-and-suggest dependency, not a requirement.** The spec's E4 has the package own controllers that render an Inertia page, and `composer.json`'s own description says "Laravel + Inertia + React apps" — but Inertia is not in `require`, and the foundation spec §4 promises an engine-only host works. Both hold only if the editor routes are opt-in and Inertia is needed solely by a host that opts in. So: `require-dev` for our tests, `suggest` for discoverability, and `Nodeflow::routes()` is the opt-in switch. An engine-only host that never calls it never loads a controller and never needs Inertia.

**2. Structured publish errors are added alongside the existing strings, not in place of them.** §5.3 needs errors renderable beside the offending node, and today `GraphValidationResult::errors()` returns flat strings that embed the node id. Changing that return shape would break `GraphValidatorTest` and `PublishFlowTest`, which the Global Constraints forbid — and rightly, because the string form is a perfectly good human-readable summary. So `errors()` keeps returning `string[]` and a new `nodeErrors(): array` returns `[['node' => 'send1', 'field' => 'template', 'message' => '...'], ...]`.

---

## File Structure

**Created:**

| Path | Responsibility |
|---|---|
| `src/Tenancy/NoTenancyResolver.php` | The package's own fallback resolver, named so `auto` mode can recognise it |
| `src/Editor/SaveDraft.php` | Last-write-wins draft persistence with stale detection |
| `src/Editor/StaleDraftException.php` | Carries the newer graph and timestamp for the 409 |
| `src/Schema/OptionSource.php` | The contract a host's dynamic option provider implements |
| `src/Schema/UnknownOptionSourceException.php` | Thrown when a declared source does not implement the contract |
| `src/Http/Controllers/FlowEditorController.php` | `edit`, `draft`, `publish` |
| `src/Http/Controllers/FieldOptionsController.php` | Resolves one field's options for the current tenant |
| `src/Http/routes.php` | The route definitions `Nodeflow::routes()` loads |
| `tests/Support/FakeOptionSource.php` | A host-style option source returning fixed options |
| `tests/Support/NotAnOptionSource.php` | A class that does not implement the contract |
| `tests/Feature/TenancyAutoModeTest.php` | `auto` inference |
| `tests/Feature/SaveDraftTest.php` | The action, without HTTP |
| `tests/Feature/StructuredPublishErrorsTest.php` | `nodeErrors()` |
| `tests/Feature/EditorRoutesTest.php` | `edit`, `draft`, `publish` over HTTP |
| `tests/Feature/FieldOptionsRouteTest.php` | The options endpoint, including its refusals |
| `tests/Unit/FieldCustomTest.php` | `Field::custom()` |

**Modified:**

| Path | Change |
|---|---|
| `config/nodeflow.php` | `tenancy` default becomes `auto`; comment documents all three |
| `src/Models/Concerns/BelongsToTenant.php` | `auto` arm in `resolveTenantIdForScope()` |
| `src/NodeflowServiceProvider.php` | Bind `NoTenancyResolver` instead of the anonymous class |
| `database/migrations/2026_08_18_000001_create_nodeflow_tables.php` | `draft_graph`, `draft_updated_at` on `nodeflow_flows` |
| `src/Models/Flow.php` | Cast the two new columns |
| `src/Graph/GraphValidationResult.php` | Add `nodeErrors()` |
| `src/Graph/GraphValidator.php` | Record node/field alongside each message |
| `src/Publishing/GraphInvalidException.php` | Expose `nodeErrors()` |
| `src/Publishing/PublishFlow.php` | Clear the draft on publish; pass structured errors |
| `src/Schema/Field.php` | Add `custom()`; stop emitting `options_source` as a class name |
| `src/Nodeflow.php` | Add `routes()` |
| `composer.json` | `inertiajs/inertia-laravel` in `require-dev` and `suggest` |
| `docs/02-integration.md` | Routes, option sources, the engine-only note |

---

## Task 1: Infer the tenancy mode (E2a)

**Files:**
- Create: `src/Tenancy/NoTenancyResolver.php`
- Modify: `config/nodeflow.php`
- Modify: `src/Models/Concerns/BelongsToTenant.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Feature/TenancyAutoModeTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `Nodeflow\Tenancy\NoTenancyResolver` implementing `Nodeflow\Contracts\TenantResolver`, bound by the provider via `bindIf`. `nodeflow.tenancy` accepts `auto` (default), `disabled`, `resolver`.

**Measured before planning:** running the whole suite with every null return throwing leaves 259/259 green, so no existing test performs a tenant-scoped read with a null tenant. This change therefore costs no test churn. That was control-probed with a bogus mode, which correctly fails 9 tests — so the clean result is real and not an env var that never reached config.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/TenancyAutoModeTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\TenancyUnresolvedException;
use Nodeflow\Tenancy\NoTenancyResolver;

function seedFlowFor(string $tenantId): void
{
    TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => $tenantId,
        'name' => 'A',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]));
}

function bindNullResolver(): void
{
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
}

it('defaults to auto', function () {
    // Counterfactual: leave the default at 'disabled' and this fails.
    expect(config('nodeflow.tenancy'))->toBe('auto');
});

it('reads unscoped under auto when the package fallback resolver is in play', function () {
    // The engine-only host: never binds a resolver, so ours answers. A null from
    // it means "this application has no tenancy", not "we could not resolve".
    // Counterfactual: make auto throw unconditionally and this fails.
    seedFlowFor('org-1');
    seedFlowFor('org-2');

    expect(app(TenantResolver::class))->toBeInstanceOf(NoTenancyResolver::class)
        ->and(Flow::count())->toBe(2);
});

it('throws under auto when the host bound its own resolver and it returned null', function () {
    // The multi-tenant host on a queue job. Under the old 'disabled' default this
    // silently returned every tenant's rows — the hole E2a closes.
    // Counterfactual: treat any resolver as "no tenancy" and this returns 2.
    seedFlowFor('org-1');
    bindNullResolver();

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('still scopes under auto when the host resolver returns a tenant', function () {
    seedFlowFor('org-1');
    seedFlowFor('org-2');

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

    expect(Flow::count())->toBe(1);
});

it('lets an explicit disabled override auto for a host with its own resolver', function () {
    // The escape hatch: a host that binds a resolver and genuinely wants unscoped
    // reads says so. Counterfactual: drop the 'disabled' arm and this throws.
    config()->set('nodeflow.tenancy', 'disabled');
    seedFlowFor('org-1');
    seedFlowFor('org-2');
    bindNullResolver();

    expect(Flow::count())->toBe(2);
});

it('lets an explicit resolver override auto for the fallback resolver', function () {
    // The inverse escape hatch, and the one that proves auto is inference rather
    // than a rename of 'disabled'.
    config()->set('nodeflow.tenancy', 'resolver');
    seedFlowFor('org-1');

    expect(fn () => Flow::count())->toThrow(TenancyUnresolvedException::class);
});

it('still refuses an unrecognised mode', function () {
    config()->set('nodeflow.tenancy', 'Auto');

    expect(fn () => Flow::count())->toThrow(InvalidArgumentException::class, 'Auto');
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='TenancyAutoMode'`

Expected: FAIL. `Class "Nodeflow\Tenancy\NoTenancyResolver" not found`, and the default is still `disabled`.

- [ ] **Step 3: Extract the fallback resolver**

Create `src/Tenancy/NoTenancyResolver.php`:

```php
<?php

namespace Nodeflow\Tenancy;

use Nodeflow\Contracts\TenantResolver;

/**
 * The resolver a host gets when it binds none of its own.
 *
 * This class exists to be *recognisable*. `nodeflow.tenancy` has to decide what a
 * null current tenant means — "this application has no tenancy" or "tenancy could
 * not be resolved here" — and those want opposite handling: read unscoped, or
 * refuse. Under the `auto` mode the package answers that question by asking which
 * resolver is in the container: if it is this one, the host never expressed an
 * opinion about tenancy and a null means the first thing. If the host bound its
 * own, a null means the second.
 *
 * `ownsSubject()` returns false, not true. It is the mandatory audience isolation
 * check, and a resolver that knows nothing about tenants must not be the reason a
 * subject is admitted to a run.
 */
class NoTenancyResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        return null;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        return false;
    }
}
```

- [ ] **Step 4: Bind it**

In `src/NodeflowServiceProvider.php`, replace the whole `bindIf(TenantResolver::class, ...)` call — the one whose closure returns `new class implements TenantResolver` — with:

```php
        // bindIf, so a host binding its own resolver wins. Which of the two is in
        // the container is exactly what `nodeflow.tenancy = auto` reads to decide
        // what a null tenant means, so this must stay a bindIf and must stay this
        // class rather than an anonymous one.
        $this->app->bindIf(TenantResolver::class, fn () => new \Nodeflow\Tenancy\NoTenancyResolver);
```

- [ ] **Step 5: Add the `auto` arm**

In `src/Models/Concerns/BelongsToTenant.php`, replace the `match ($mode)` block inside `resolveTenantIdForScope()` with:

```php
        return match ($mode) {
            // The host never expressed an opinion, so a null means "no tenancy".
            'auto' => app(TenantResolver::class) instanceof NoTenancyResolver
                ? $tenantId
                : $tenantId ?? throw new TenancyUnresolvedException(static::class),
            'disabled' => $tenantId,
            'resolver' => $tenantId ?? throw new TenancyUnresolvedException(static::class),
            default => throw new InvalidArgumentException(
                'Unrecognised nodeflow.tenancy mode '.static::describeTenancyMode($mode)
                ."; the only valid values are 'auto', 'resolver' and 'disabled'. All are matched "
                ."exactly, so 'Auto', 'AUTO' and true are all invalid. Reading is refused rather "
                .'than falling back to unscoped, which on a null tenant would return every '
                .'tenant\'s rows. Check NODEFLOW_TENANCY in the environment, and run '
                .'`php artisan config:clear` if a cached config predates the key existing.'
            ),
        };
```

Add the import below the existing `use Nodeflow\Models\TenancyUnresolvedException;`:

```php
use Nodeflow\Tenancy\NoTenancyResolver;
```

- [ ] **Step 6: Change the default and document all three modes**

In `config/nodeflow.php`, replace the `tenancy` entry and its comment block with:

```php
    /*
     * What a null return from TenantResolver::currentTenantId() means.
     *
     * 'auto' (default) — infer it. If the container holds the package's own
     *   NoTenancyResolver, the host never expressed an opinion about tenancy and a
     *   null means "this application has no tenancy": read unscoped. If the host
     *   bound its own resolver, a null means it could not be resolved — a queue
     *   worker, a console command, an unauthenticated request — and a scoped read
     *   throws rather than quietly returning every tenant's rows.
     *
     * 'disabled' — always treat null as "no tenancy" and read unscoped. The escape
     *   hatch for a host that binds a resolver and genuinely wants that.
     *
     * 'resolver' — always treat null as unresolved and throw.
     *
     * A non-null tenant always scopes, in every mode. This setting governs only
     * what null means. An unrecognised value throws rather than degrading.
     */
    'tenancy' => env('NODEFLOW_TENANCY', 'auto'),
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='TenancyAutoMode'`

Expected: PASS, 7 tests.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 266 tests. No existing test may be edited. `TenancyModeTest` is the one most likely to surface a mistake — it asserts `disabled` and `resolver` behaviour explicitly and must be unaffected.

- [ ] **Step 9: Commit**

```bash
git add src/Tenancy/NoTenancyResolver.php config/nodeflow.php src/Models/Concerns/BelongsToTenant.php src/NodeflowServiceProvider.php tests/Feature/TenancyAutoModeTest.php
git commit -m "feat: infer the tenancy mode from which resolver is bound"
```

---

## Task 2: Draft storage

**Files:**
- Create: `src/Editor/SaveDraft.php`
- Create: `src/Editor/StaleDraftException.php`
- Modify: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Modify: `src/Models/Flow.php`
- Modify: `src/Publishing/PublishFlow.php`
- Test: `tests/Feature/SaveDraftTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces:
  - `Nodeflow\Editor\SaveDraft::save(Flow $flow, array $graph, ?string $lastSeen): string` — returns the new `draft_updated_at` as an ISO-8601 string; throws `StaleDraftException` when `$lastSeen` does not match the stored value.
  - `Nodeflow\Editor\StaleDraftException::graph(): array` and `::updatedAt(): string` — the newer draft, for the 409 body.
  - `Flow` casts `draft_graph` to `array` and `draft_updated_at` to `datetime`.
  - `PublishFlow::publish()` clears `draft_graph` and `draft_updated_at`.

**Why an action rather than controller code.** The 409 logic is the only genuinely stateful decision in this plan and it is worth testing without an HTTP round trip. It also keeps the controller thin enough to read in one screen.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/SaveDraftTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Editor\SaveDraft;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Models\Flow;

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
});

function graphWith(string $nodeId): array
{
    return [
        'start' => $nodeId,
        'nodes' => [['id' => $nodeId, 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ];
}

it('saves a first draft when nothing has been saved yet', function () {
    $at = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n1'))
        ->and($at)->not->toBeEmpty();
});

it('accepts a graph that could never publish', function () {
    // E3: a draft is not a version. Mid-edit it is allowed to be broken, which is
    // the whole reason it is not stored as one.
    // Counterfactual: validate in save() and this throws.
    $broken = ['start' => 'nope', 'nodes' => [], 'edges' => []];

    app(SaveDraft::class)->save($this->flow, $broken, null);

    expect($this->flow->fresh()->draft_graph)->toBe($broken);
});

it('overwrites when the caller saw the current timestamp', function () {
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n2'))
        ->and($second)->not->toBe($first);
});

it('refuses when the caller saw a stale timestamp, and keeps the newer draft', function () {
    // Two authors on one flow. Counterfactual: drop the comparison and the second
    // save silently destroys the first author's work.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    expect(fn () => app(SaveDraft::class)->save($this->flow, graphWith('n3'), $first))
        ->toThrow(StaleDraftException::class);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n2'));
});

it('hands the newer draft to the caller so the editor can show the conflict', function () {
    // Without this the 409 is useless: the client knows it lost but not to what.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);
    $second = app(SaveDraft::class)->save($this->flow, graphWith('n2'), $first);

    try {
        app(SaveDraft::class)->save($this->flow, graphWith('n3'), $first);
        $this->fail('expected StaleDraftException');
    } catch (StaleDraftException $e) {
        expect($e->graph())->toBe(graphWith('n2'))
            ->and($e->updatedAt())->toBe($second);
    }
});

it('refuses a null last-seen once a draft exists', function () {
    // A client that has never loaded the flow must not be able to blow away a
    // draft by omitting the token.
    $first = app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    expect(fn () => app(SaveDraft::class)->save($this->flow, graphWith('n2'), null))
        ->toThrow(StaleDraftException::class);

    expect($this->flow->fresh()->draft_graph)->toBe(graphWith('n1'))
        ->and($first)->not->toBeEmpty();
});

it('clears the draft when the flow publishes', function () {
    // Counterfactual: leave the draft behind and the editor reopens showing an
    // already-published graph as unsaved work.
    app(SaveDraft::class)->save($this->flow, graphWith('n1'), null);

    app(\Nodeflow\Publishing\PublishFlow::class)->publish($this->flow, graphWith('n1'));

    expect($this->flow->fresh()->draft_graph)->toBeNull()
        ->and($this->flow->fresh()->draft_updated_at)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='SaveDraft'`

Expected: FAIL. `Class "Nodeflow\Editor\SaveDraft" not found`.

- [ ] **Step 3: Add the columns**

In `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`, the `nodeflow_flows` block contains:

```php
            $t->foreignId('current_version_id')->nullable();
```

Insert the two draft columns immediately after it:

```php
            $t->foreignId('current_version_id')->nullable();
            $t->json('draft_graph')->nullable();
            $t->timestamp('draft_updated_at')->nullable();
```

- [ ] **Step 4: Cast them**

In `src/Models/Flow.php`, the casts line reads:

```php
    protected $casts = ['trigger_config' => 'array'];
```

Replace it with:

```php
    protected $casts = [
        'trigger_config' => 'array',
        'draft_graph' => 'array',
        'draft_updated_at' => 'datetime',
    ];
```

- [ ] **Step 5: Write the exception**

Create `src/Editor/StaleDraftException.php`:

```php
<?php

namespace Nodeflow\Editor;

use RuntimeException;

/**
 * Two authors edited one flow and this save lost the race.
 *
 * Carries the winning draft, because a conflict the client cannot see is a
 * conflict it can only resolve by discarding someone's work. The editor shows
 * "someone else edited this" and has the newer graph in hand to offer.
 */
class StaleDraftException extends RuntimeException
{
    public function __construct(
        private array $graph,
        private string $updatedAt,
    ) {
        parent::__construct(
            'This flow\'s draft changed since it was loaded. The save was refused rather than '
            .'overwriting the newer draft.'
        );
    }

    public function graph(): array
    {
        return $this->graph;
    }

    public function updatedAt(): string
    {
        return $this->updatedAt;
    }
}
```

- [ ] **Step 6: Write the action**

Create `src/Editor/SaveDraft.php`:

```php
<?php

namespace Nodeflow\Editor;

use Nodeflow\Models\Flow;

/**
 * Persists an editor draft, last-write-wins with stale detection.
 *
 * A draft is deliberately not a version (spec E3): versions are immutable and
 * numbered, and a graph mid-edit is neither. So this writes a single column on the
 * flow and does no validation at all — a half-connected graph is the normal state
 * of a canvas someone is working on, and refusing to store it would make autosave
 * useless exactly when it matters.
 *
 * Concurrency is last-write-wins *with* a check rather than without one. The
 * caller sends the draft_updated_at it last saw; a mismatch is refused instead of
 * silently discarding whichever author saved second.
 */
class SaveDraft
{
    /**
     * @return string the new draft_updated_at, to be echoed back on the next save
     *
     * @throws StaleDraftException
     */
    public function save(Flow $flow, array $graph, ?string $lastSeen): string
    {
        $current = $flow->draft_updated_at?->toIso8601String();

        if ($current !== $lastSeen) {
            throw new StaleDraftException($flow->draft_graph ?? [], $current ?? '');
        }

        // now() rather than a database default: the value is a concurrency token the
        // client round-trips, so it has to be readable back at exactly the precision
        // it was written at.
        $flow->update([
            'draft_graph' => $graph,
            'draft_updated_at' => now(),
        ]);

        return $flow->fresh()->draft_updated_at->toIso8601String();
    }
}
```

- [ ] **Step 7: Clear the draft on publish**

In `src/Publishing/PublishFlow.php`, the transaction body updates the flow:

```php
            $flow->update(['current_version_id' => $version->id, 'status' => 'active']);
```

Replace that line with:

```php
            // The draft became this version, so it is no longer pending work. Left
            // behind, the editor reopens showing an already-published graph as
            // unsaved changes.
            $flow->update([
                'current_version_id' => $version->id,
                'status' => 'active',
                'draft_graph' => null,
                'draft_updated_at' => null,
            ]);
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='SaveDraft'`

Expected: PASS, 7 tests.

- [ ] **Step 9: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 273 tests. `PublishFlowTest` is the one most likely to surface a mistake, since publish now writes two more columns.

- [ ] **Step 10: Commit**

```bash
git add src/Editor database/migrations/2026_08_18_000001_create_nodeflow_tables.php src/Models/Flow.php src/Publishing/PublishFlow.php tests/Feature/SaveDraftTest.php
git commit -m "feat: store editor drafts with stale-write detection"
```

---

## Task 3: Per-node publish errors

**Files:**
- Modify: `src/Graph/GraphValidationResult.php`
- Modify: `src/Graph/GraphValidator.php`
- Modify: `src/Publishing/GraphInvalidException.php`
- Modify: `src/Publishing/PublishFlow.php`
- Test: `tests/Feature/StructuredPublishErrorsTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `GraphValidationResult::__construct(array $errors, array $warnings, array $nodeErrors = [])` and `nodeErrors(): array`, each entry `['node' => ?string, 'field' => ?string, 'message' => string]`.
  - `GraphInvalidException::nodeErrors(): array` with the same shape.
  - `errors(): array` on both **keeps returning `string[]`** — Task 5's controller returns both.

**The constraint that shapes this task.** `GraphValidatorTest` and `PublishFlowTest` assert on the existing flat strings and may not be edited. So this is purely additive: every message that already names a node gains a structured twin, and the strings stay byte-identical.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/StructuredPublishErrorsTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;

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
});

it('names the node whose field failed validation', function () {
    // §5.3: the editor renders an error beside its node. Parsing it back out of
    // "Node [w1] field [duration]: ..." is brittle, so the structure is carried.
    // Counterfactual: return only strings and there is nothing to key on.
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->passes())->toBeFalse()
        ->and($result->nodeErrors())->toContain([
            'node' => 'w1',
            'field' => 'duration',
            'message' => 'The duration field is required.',
        ]);
});

it('keeps the flat strings byte-identical alongside the structure', function () {
    // The existing suite asserts on these. Counterfactual: reshape errors() and
    // GraphValidatorTest and PublishFlowTest break.
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->errors())->toContain('Node [w1] field [duration]: The duration field is required.');
});

it('names the node for an unknown type', function () {
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => 'x1',
        'nodes' => [['id' => 'x1', 'type' => 'nope.missing', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->nodeErrors())->toContain([
        'node' => 'x1',
        'field' => null,
        'message' => 'Node [x1] uses unknown type [nope.missing].',
    ]);
});

it('leaves the node null for a graph-level failure', function () {
    // A cycle or a missing start belongs to no node, and the editor must not try
    // to pin it to one. Counterfactual: default node to the first id and a cycle
    // error lands on an innocent node's card.
    $result = app(GraphValidator::class)->validate(Graph::fromArray([
        'start' => '',
        'nodes' => [],
        'edges' => [],
    ]));

    expect($result->nodeErrors())->toContain([
        'node' => null,
        'field' => null,
        'message' => 'The flow has no start node set. Choose a starting node before publishing.',
    ]);
});

it('carries the structure through the publish exception', function () {
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    try {
        app(PublishFlow::class)->publish($flow, [
            'start' => 'w1',
            'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
            'edges' => [],
        ]);
        $this->fail('expected GraphInvalidException');
    } catch (GraphInvalidException $e) {
        expect($e->nodeErrors())->toContain([
            'node' => 'w1',
            'field' => 'duration',
            'message' => 'The duration field is required.',
        ])->and($e->errors())->toBeArray();
    }
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='StructuredPublishErrors'`

Expected: FAIL. `Call to undefined method Nodeflow\Graph\GraphValidationResult::nodeErrors()`.

- [ ] **Step 3: Extend the result object**

Replace `src/Graph/GraphValidationResult.php` entirely:

```php
<?php

namespace Nodeflow\Graph;

class GraphValidationResult
{
    /**
     * @param  string[]  $errors  human-readable summaries, one per problem
     * @param  string[]  $warnings
     * @param  array<int, array{node: ?string, field: ?string, message: string}>  $nodeErrors
     *                                                                                       the same problems, keyed so an editor can render each beside its node
     */
    public function __construct(
        private array $errors = [],
        private array $warnings = [],
        private array $nodeErrors = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The same failures as errors(), structured.
     *
     * Kept alongside rather than replacing the strings: the strings are a fine
     * human-readable summary and the existing suite asserts on them, while an
     * editor needs to pin a message to a node card without parsing prose out of
     * "Node [w1] field [duration]: ...". `node` is null for a graph-level problem —
     * a cycle or a missing start node belongs to no single node, and attributing it
     * to one would put a red badge on an innocent card.
     *
     * @return array<int, array{node: ?string, field: ?string, message: string}>
     */
    public function nodeErrors(): array
    {
        return $this->nodeErrors;
    }
}
```

- [ ] **Step 4: Record the structure as the validator finds each problem**

In `src/Graph/GraphValidator.php`, `validate()` currently opens with `$errors = [];` and `$warnings = [];` and ends by returning `new GraphValidationResult($errors, $warnings);`.

Add a third accumulator beside the first two:

```php
        $errors = [];
        $warnings = [];
        $nodeErrors = [];
```

Then, for **every** place that appends to `$errors`, append a matching entry to `$nodeErrors` naming the node and field where one is known. Concretely — each of these is an existing `$errors[] = ...` line, and the second line is what you add after it:

```php
// no start node set
$nodeErrors[] = ['node' => null, 'field' => null, 'message' => 'The flow has no start node set. Choose a starting node before publishing.'];

// start node missing from the graph
$nodeErrors[] = ['node' => $graph->startNodeId(), 'field' => null, 'message' => end($errors)];

// duplicate node id
$nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];

// unknown type
$nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];

// neither cardinality interface
$nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];

// per-field validation failure — note the message is the raw field message, not
// the prefixed string, because the editor already knows the node and field
$nodeErrors[] = ['node' => $id, 'field' => $field, 'message' => implode(' ', $messages)];

// edge to a missing node
$nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];

// output the node does not declare
$nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];

// output with more than one outgoing edge
$nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];

// cycle
$nodeErrors[] = ['node' => null, 'field' => null, 'message' => end($errors)];
```

`end($errors)` reuses the string just appended so the two can never drift apart. The per-field case is the one exception: it carries the bare field message, since prefixing it again would make the editor strip its own node id back off.

Return all three:

```php
        return new GraphValidationResult($errors, $warnings, $nodeErrors);
```

- [ ] **Step 5: Carry it through the exception**

Replace `src/Publishing/GraphInvalidException.php` entirely:

```php
<?php

namespace Nodeflow\Publishing;

use RuntimeException;

class GraphInvalidException extends RuntimeException
{
    /**
     * @param  string[]  $errors
     * @param  array<int, array{node: ?string, field: ?string, message: string}>  $nodeErrors
     */
    public function __construct(
        private array $errors,
        private array $nodeErrors = [],
    ) {
        parent::__construct('The flow could not be published: '.implode(' ', $errors));
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The same failures, each pinned to its node where one is known, so an editor
     * can render them on the canvas instead of as one wall of text.
     *
     * @return array<int, array{node: ?string, field: ?string, message: string}>
     */
    public function nodeErrors(): array
    {
        return $this->nodeErrors;
    }
}
```

In `src/Publishing/PublishFlow.php`, the throw currently reads:

```php
            throw new GraphInvalidException($result->errors());
```

Replace it with:

```php
            throw new GraphInvalidException($result->errors(), $result->nodeErrors());
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='StructuredPublishErrors'`

Expected: PASS, 5 tests.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 278 tests. `GraphValidatorTest` and `PublishFlowTest` must pass **unedited** — they are the check that the strings did not drift.

- [ ] **Step 8: Commit**

```bash
git add src/Graph src/Publishing tests/Feature/StructuredPublishErrorsTest.php
git commit -m "feat: pin publish errors to the node that caused them"
```

---

## Task 4: The option-source contract and `Field::custom()`

**Files:**
- Create: `src/Schema/OptionSource.php`
- Create: `src/Schema/UnknownOptionSourceException.php`
- Create: `tests/Support/FakeOptionSource.php`
- Create: `tests/Support/NotAnOptionSource.php`
- Modify: `src/Schema/Field.php`
- Test: `tests/Unit/FieldCustomTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `Nodeflow\Schema\OptionSource` with `options(): array` (value => label).
  - `Nodeflow\Schema\UnknownOptionSourceException::notAnOptionSource(string $class): self`.
  - `Field::custom(string $key, string $type, string $baseRule = 'string'): self`.
  - `Field::optionsSourceClass(): ?string` — the declared class, for Task 6's server-side lookup.
  - `Field::toArray()` emits `'dynamic_options' => bool` and **no longer emits `options_source`**.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/FieldCustomTest.php`:

```php
<?php

use Nodeflow\Schema\Field;
use Tests\Support\FakeOptionSource;

it('compiles a custom field type through to the editor payload', function () {
    // FieldType is an enum a host cannot add a case to, so a bespoke control needs
    // a type string that bypasses it. Counterfactual: drop custom() and a host
    // cannot declare a town picker at all.
    $field = Field::custom('destination', 'town')->label('Destination')->required();

    expect($field->toArray())->toMatchArray([
        'key' => 'destination',
        'type' => 'town',
        'label' => 'Destination',
        'required' => true,
    ]);
});

it('validates a custom field with the base rule it was given', function () {
    // Publish-time validation has to work for a type the package has never heard
    // of. Counterfactual: hard-code 'string' and a numeric custom field accepts
    // anything.
    $rules = Field::custom('altitude', 'elevation', 'numeric')->required()->rules();

    expect($rules['altitude'])->toBe(['required', 'numeric']);
});

it('defaults a custom field to a string rule', function () {
    $rules = Field::custom('destination', 'town')->rules();

    expect($rules['destination'])->toBe(['nullable', 'string']);
});

it('tells the editor a field is dynamic without naming the class behind it', function () {
    // Spec E6: the browser needs to know THAT a field is dynamic, never what PHP
    // class backs it. Leaking the name buys nothing and invites an endpoint that
    // accepts it. Counterfactual: keep emitting options_source and the class name
    // is in every palette payload.
    $field = Field::select('template')->optionsFrom(FakeOptionSource::class);

    expect($field->toArray())
        ->toHaveKey('dynamic_options', true)
        ->and($field->toArray())->not->toHaveKey('options_source');
});

it('keeps the declared source reachable server-side', function () {
    // The options endpoint resolves it from the node's own definition. Without an
    // accessor there is no way to reach it except the payload we just removed it
    // from.
    $field = Field::select('template')->optionsFrom(FakeOptionSource::class);

    expect($field->optionsSourceClass())->toBe(FakeOptionSource::class);
});

it('reports a static-optioned field as not dynamic', function () {
    $field = Field::select('channel')->options(['sms' => 'SMS']);

    expect($field->toArray())->toHaveKey('dynamic_options', false)
        ->and($field->optionsSourceClass())->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/pest --filter='FieldCustom'`

Expected: FAIL. `Call to undefined method Nodeflow\Schema\Field::custom()`.

- [ ] **Step 3: Write the contract**

Create `src/Schema/OptionSource.php`:

```php
<?php

namespace Nodeflow\Schema;

/**
 * Supplies a field's options at edit time, scoped to the current tenant.
 *
 * A select whose choices are host data — this FSP's message templates, this
 * organisation's towns — cannot have them baked into the node definition, because
 * the definition is one class shared by every tenant. So the field names a class
 * and the package asks it, inside the request, with the tenancy resolver already
 * in play.
 *
 * An interface rather than a duck-typed `options()` method: a class that does not
 * implement this fails with its own name in the message, where duck typing
 * degrades to an empty option list — indistinguishable to the author from "this
 * tenant has no templates yet".
 */
interface OptionSource
{
    /** @return array<string, string> value => label */
    public function options(): array;
}
```

Create `src/Schema/UnknownOptionSourceException.php`:

```php
<?php

namespace Nodeflow\Schema;

use RuntimeException;

class UnknownOptionSourceException extends RuntimeException
{
    public static function notAnOptionSource(string $class): self
    {
        return new self(
            "[{$class}] is declared as a field's option source but does not implement "
            .OptionSource::class.'. Implement it and return an array of value => label. '
            .'This is refused rather than treated as an empty option list, because an '
            .'empty list looks identical to a tenant that genuinely has no options yet.'
        );
    }
}
```

- [ ] **Step 4: Write the test doubles**

Create `tests/Support/FakeOptionSource.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Schema\OptionSource;

class FakeOptionSource implements OptionSource
{
    public function options(): array
    {
        return ['welcome' => 'Welcome message', 'reminder' => 'Reminder'];
    }
}
```

Create `tests/Support/NotAnOptionSource.php`:

```php
<?php

namespace Tests\Support;

/**
 * Deliberately implements nothing. Used to prove a field declaring a class that is
 * not an OptionSource fails loudly rather than yielding an empty select.
 */
class NotAnOptionSource
{
    public function options(): array
    {
        return ['sneaky' => 'Should never be reached'];
    }
}
```

Note it *has* an `options()` method: that is the point. Duck typing would accept it.

- [ ] **Step 5: Extend `Field`**

In `src/Schema/Field.php`, add a property beside the existing `private ?string $optionsSource = null;`:

```php
    private ?string $customType = null;

    private string $customBaseRule = 'string';
```

Add the factory below `duration()`:

```php
    /**
     * A field type the package does not know about.
     *
     * FieldType is an enum, so a host cannot add a case to it — but the field-type
     * to control mapping is deliberately extensible (spec E5), and a host with a
     * town picker needs a type string to key it on. The base rule travels with it
     * because publish-time validation must still work for a type the package has
     * never heard of; without it a numeric custom field would accept anything.
     */
    public static function custom(string $key, string $type, string $baseRule = 'string'): self
    {
        $field = new self($key, FieldType::Text);
        $field->customType = $type;
        $field->customBaseRule = $baseRule;

        return $field;
    }
```

Add the accessor below `optionsFrom()`:

```php
    /**
     * The declared option source, for server-side resolution only.
     *
     * Deliberately not in toArray(): the browser learns that a field is dynamic,
     * never which class backs it (spec E6). The options endpoint reads this from
     * the node's own definition, so a client-supplied class name is never part of
     * the lookup.
     */
    public function optionsSourceClass(): ?string
    {
        return $this->optionsSource;
    }
```

Replace `toArray()`'s `'type'` and `'options_source'` entries so the method reads:

```php
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->customType ?? $this->type->value,
            'label' => $this->label ?? Str::ucfirst(str_replace('_', ' ', Str::snake($this->key))),
            'help' => $this->help,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'dynamic_options' => $this->optionsSource !== null,
        ];
    }
```

Finally, in `rules()`, replace `$this->type->baseRule()` with the custom-aware form:

```php
        $rules = [$this->required ? 'required' : 'nullable', $this->customType !== null
            ? $this->customBaseRule
            : $this->type->baseRule()];
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='FieldCustom'`

Expected: PASS, 6 tests.

- [ ] **Step 7: Amend the two assertions that pin the old contract**

This is the plan's one sanctioned edit to an existing test, and it is sanctioned because those assertions describe a contract E6 deliberately changes — not because they caught a mistake.

`tests/Unit/FieldTest.php:20` currently includes this line in an expected-array literal:

```php
        'options_source' => null,
```

Replace it with:

```php
        'dynamic_options' => false,
```

`tests/Unit/FieldTest.php:50` currently reads:

```php
        ->and($field->toArray()['options_source'])->toBe('App\\Nodeflow\\YayaTemplates')
```

Replace it with two assertions of equal strength — one that the payload advertises dynamism, one that it does *not* leak the class:

```php
        ->and($field->toArray()['dynamic_options'])->toBeTrue()
        ->and($field->toArray())->not->toHaveKey('options_source')
```

Do not weaken this to only the first line. The second is the one that fails if a future change reintroduces the class name into the payload, which is the property E6 exists to protect.

Change nothing else in that file, and no other existing test file at all.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 284 tests. `NodeDefinitionTest` and `SchemaTest` also exercise `toArray()` — if either fails, the fix belongs in `Field`, not in them.

- [ ] **Step 9: Commit**

```bash
git add src/Schema tests/Support/FakeOptionSource.php tests/Support/NotAnOptionSource.php tests/Unit/FieldCustomTest.php
git commit -m "feat: add the option-source contract and custom field types"
```

---

## Task 5: Routes, and the editor controller

**Files:**
- Create: `src/Http/routes.php`
- Create: `src/Http/Controllers/FlowEditorController.php`
- Modify: `src/Nodeflow.php`
- Modify: `composer.json`
- Test: `tests/Feature/EditorRoutesTest.php`

**Interfaces:**
- Consumes: `SaveDraft::save()` and `StaleDraftException` (Task 2); `GraphInvalidException::nodeErrors()` (Task 3).
- Produces:
  - `Nodeflow::routes(): void` — registers the editor routes on the current router, to be called inside the host's own `Route::group`.
  - Route names `nodeflow.flows.edit`, `nodeflow.flows.draft`, `nodeflow.flows.publish`.
  - Task 6 adds `nodeflow.fields.options` to the same file.

**How the tests register routes.** No routes exist in the package today, so there is no precedent. Register them per-test inside a group, which is also exactly how a host does it:

```php
Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());
```

**Authorization comes from Plan 2 and is not re-implemented.** `Flow` has a `FlowPolicy` whose every method denies unless the host defined the matching gate. So a test that wants a request to succeed must define the gate; a test that wants a 403 simply does not.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/EditorRoutesTest.php`:

```php
<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;

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

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());

    $this->user = new User;
    $this->user->id = 1;

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);
});

function exitGraph(): array
{
    return [
        'start' => 'e1',
        'nodes' => [['id' => 'e1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ];
}

function allowEverything(): void
{
    foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
        Gate::define("nodeflow.{$ability}", fn ($user, $flow = null) => true);
    }
}

it('denies editing when the host has defined no gates', function () {
    // Plan 2's floor, reached over HTTP for the first time. Counterfactual: skip
    // authorize() in the controller and this returns 200.
    $this->actingAs($this->user)
        ->get("/nodeflow/flows/{$this->flow->id}/edit")
        ->assertForbidden();
});

it('four-oh-fours another tenants flow rather than forbidding it', function () {
    // 403 would confirm the row exists. Counterfactual: look the flow up
    // unscoped and this returns 200 or 403.
    allowEverything();

    $theirs = TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => 'org-2',
        'name' => 'Theirs',
        'trigger_type' => 'manual',
        'status' => 'draft',
    ]));

    $this->actingAs($this->user)
        ->get("/nodeflow/flows/{$theirs->id}/edit")
        ->assertNotFound();
});

it('saves a draft and returns the new token', function () {
    allowEverything();

    $response = $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => exitGraph(),
            'draft_updated_at' => null,
        ]);

    $response->assertOk()->assertJsonStructure(['draft_updated_at']);

    expect($this->flow->fresh()->draft_graph)->toBe(exitGraph());
});

it('returns 409 and the newer draft when the token is stale', function () {
    // Counterfactual: let StaleDraftException bubble and this is a 500 with no
    // graph for the client to show.
    allowEverything();

    $first = $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_updated_at' => null])
        ->json('draft_updated_at');

    $newer = exitGraph();
    $newer['nodes'][0]['id'] = 'e2';
    $newer['start'] = 'e2';

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => $newer, 'draft_updated_at' => $first]);

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", ['graph' => exitGraph(), 'draft_updated_at' => $first])
        ->assertStatus(409)
        ->assertJsonPath('graph.start', 'e2');
});

it('accepts a draft that could never publish', function () {
    // E3 again, over HTTP: the endpoint must not validate.
    allowEverything();

    $this->actingAs($this->user)
        ->putJson("/nodeflow/flows/{$this->flow->id}/draft", [
            'graph' => ['start' => 'nope', 'nodes' => [], 'edges' => []],
            'draft_updated_at' => null,
        ])
        ->assertOk();
});

it('publishes a valid graph and freezes a version', function () {
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertOk()
        ->assertJsonPath('version', 1);

    expect($this->flow->fresh()->current_version_id)->not->toBeNull();
});

it('returns per-node errors when publish is rejected', function () {
    // The payoff of Task 3, over HTTP. Counterfactual: return only the flat
    // strings and the editor has to parse prose to find the node.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => [
            'start' => 'w1',
            'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => []]],
            'edges' => [],
        ]])
        ->assertStatus(422)
        ->assertJsonPath('node_errors.0.node', 'w1')
        ->assertJsonPath('node_errors.0.field', 'duration');
});

it('denies publishing to someone who may edit but not publish', function () {
    // The two gates are separate for a reason. Counterfactual: authorize publish
    // against nodeflow.update and this returns 200.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => true);
    Gate::define('nodeflow.publish', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", ['graph' => exitGraph()])
        ->assertForbidden();
});

it('ignores a version id smuggled into the publish payload', function () {
    // Open issue G-3: the unscoped Flow::currentVersion() relation is safe only
    // while current_version_id stays inside the tenant. Counterfactual: pass the
    // request through to update() and a caller repoints the flow at another
    // tenant's version.
    allowEverything();

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$this->flow->id}/publish", [
            'graph' => exitGraph(),
            'current_version_id' => 99999,
        ])
        ->assertOk();

    expect($this->flow->fresh()->current_version_id)->not->toBe(99999);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='EditorRoutes'`

Expected: FAIL. `Call to undefined method Nodeflow\Nodeflow::routes()`.

- [ ] **Step 3: Add the dev dependency**

In `composer.json`, add to `require-dev` (keeping the existing entries):

```json
        "inertiajs/inertia-laravel": "^2.0",
```

And add a `suggest` block after `require-dev`:

```json
    "suggest": {
        "inertiajs/inertia-laravel": "Required only if you use the editor routes; the engine works without it."
    },
```

Then run:

```bash
composer update inertiajs/inertia-laravel --no-interaction
```

Inertia is deliberately **not** in `require`: the foundation spec promises an engine-only host works, routes are opt-in, and a host that never calls `Nodeflow::routes()` never loads a controller that mentions Inertia.

- [ ] **Step 4: Write the routes file**

Create `src/Http/routes.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Nodeflow\Http\Controllers\FlowEditorController;

/*
 * Loaded by Nodeflow::routes(), which a host calls inside its own Route::group —
 * so prefix, middleware and domain are the host's choice, not ours. Nothing here
 * declares middleware for that reason.
 *
 * {flow} binds through the tenant-scoped Flow model, so a cross-tenant id is a 404
 * before any controller code runs. That is deliberate: a 403 would confirm the row
 * exists.
 */

Route::get('flows/{flow}/edit', [FlowEditorController::class, 'edit'])->name('nodeflow.flows.edit');
Route::put('flows/{flow}/draft', [FlowEditorController::class, 'draft'])->name('nodeflow.flows.draft');
Route::post('flows/{flow}/publish', [FlowEditorController::class, 'publish'])->name('nodeflow.flows.publish');
```

- [ ] **Step 5: Add the loader**

In `src/Nodeflow.php`, add:

```php
    /**
     * Register the editor's routes.
     *
     * Called by the host from its own routes file, inside whatever group it wants:
     *
     *     Route::middleware(['web', 'auth'])->prefix('admin')->group(
     *         fn () => Nodeflow::routes()
     *     );
     *
     * Opt-in rather than automatic, because a host running flows with no editor
     * must not be made to depend on Inertia — and because prefix and middleware
     * are decisions only the host can make.
     */
    public static function routes(): void
    {
        require __DIR__.'/Http/routes.php';
    }
```

- [ ] **Step 6: Write the controller**

Create `src/Http/Controllers/FlowEditorController.php`:

```php
<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Nodeflow\Editor\SaveDraft;
use Nodeflow\Editor\StaleDraftException;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\TriggerRegistry;

/**
 * The editor's server half.
 *
 * Three deliberate shapes here. The draft endpoint does not validate, because a
 * graph mid-edit is allowed to be broken and refusing to store it would make
 * autosave useless. Publish returns per-node errors so the canvas can render each
 * beside its node. And nothing reads a foreign key out of the request: open issue
 * G-3 records that Flow::currentVersion() is deliberately unscoped, which is safe
 * only while current_version_id stays inside the tenant — so it is set from a
 * version this code just created, never from input.
 */
class FlowEditorController extends Controller
{
    // Illuminate\Routing\Controller is a bare abstract class — verified — so the
    // trait is required for $this->authorize() to exist at all. Without it every
    // endpoint here fatals rather than authorizing.
    use AuthorizesRequests;

    public function edit(Flow $flow): \Inertia\Response
    {
        $this->authorize('update', $flow);

        return Inertia::render('nodeflow/editor', [
            'flow' => [
                'id' => $flow->id,
                'name' => $flow->name,
                'trigger_type' => $flow->trigger_type,
                'status' => $flow->status,
                'version' => $flow->currentVersion?->version,
                'draft_updated_at' => $flow->draft_updated_at?->toIso8601String(),
            ],
            // The draft wins when there is one: it is the author's unsaved work.
            'graph' => $flow->draft_graph
                ?? $flow->currentVersion?->graph
                ?? ['start' => '', 'nodes' => [], 'edges' => []],
            'palette' => app(NodeRegistry::class)->palette(),
            'triggers' => app(TriggerRegistry::class)->palette(),
        ]);
    }

    public function draft(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('update', $flow);

        $validated = $request->validate([
            'graph' => ['required', 'array'],
            'draft_updated_at' => ['nullable', 'string'],
        ]);

        try {
            $updatedAt = app(SaveDraft::class)->save(
                $flow,
                $validated['graph'],
                $validated['draft_updated_at'] ?? null,
            );
        } catch (StaleDraftException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'graph' => $e->graph(),
                'draft_updated_at' => $e->updatedAt(),
            ], 409);
        }

        return response()->json(['draft_updated_at' => $updatedAt]);
    }

    public function publish(Request $request, Flow $flow): JsonResponse
    {
        $this->authorize('publish', $flow);

        $validated = $request->validate(['graph' => ['required', 'array']]);

        try {
            $version = app(PublishFlow::class)->publish(
                $flow,
                $validated['graph'],
                (string) ($request->user()?->getAuthIdentifier() ?? ''),
            );
        } catch (GraphInvalidException $e) {
            return response()->json([
                'message' => 'The flow could not be published.',
                'errors' => $e->errors(),
                'node_errors' => $e->nodeErrors(),
            ], 422);
        }

        return response()->json(['version' => $version->version]);
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='EditorRoutes'`

Expected: PASS, 9 tests.

- [ ] **Step 8: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 293 tests. `tests/Unit/ArchitectureTest.php` must stay green — it now scans `src/Http/` and will name your controller if it queries `RunSubject` or `NodeExecution`.

- [ ] **Step 9: Commit**

```bash
git add src/Http src/Nodeflow.php composer.json composer.lock tests/Feature/EditorRoutesTest.php
git commit -m "feat: add opt-in editor routes with draft and publish endpoints"
```

---

## Task 6: The field options endpoint

**Files:**
- Create: `src/Http/Controllers/FieldOptionsController.php`
- Modify: `src/Http/routes.php`
- Test: `tests/Feature/FieldOptionsRouteTest.php`

**Interfaces:**
- Consumes: `OptionSource`, `UnknownOptionSourceException`, `Field::optionsSourceClass()` (Task 4); the route file and `Nodeflow::routes()` (Task 5).
- Produces: route `nodeflow.fields.options` at `flows/{flow}/nodes/{type}/fields/{field}/options`.

**The security property this task exists to preserve.** The endpoint takes a node type and a field key. It resolves the option source from **the node's own `definition()`**. It never accepts a class name, because an endpoint that did would be "instantiate any class in this application and call `options()` on it". One of the tests below sends a hostile class name and asserts it is ignored — that test is the point of the task, not a nicety.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/FieldOptionsRouteTest.php`:

```php
<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\BadSourceNode;
use Tests\Support\DynamicOptionNode;
use Tests\Support\NotAnOptionSource;

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

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());

    Gate::define('nodeflow.update', fn ($user, $flow = null) => true);

    $this->user = new User;
    $this->user->id = 1;

    $this->flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(NodeRegistry::class)->register(DynamicOptionNode::class, BadSourceNode::class);
});

it('resolves options declared by the node', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message');
});

it('ignores a class name smuggled in the query string', function () {
    // THE test for this task. Counterfactual: read the class from the request and
    // this endpoint instantiates arbitrary application classes.
    $this->actingAs($this->user)
        ->getJson(
            "/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options"
            .'?options_source='.urlencode(NotAnOptionSource::class)
        )
        ->assertOk()
        ->assertJsonPath('options.welcome', 'Welcome message')
        ->assertJsonMissingPath('options.sneaky');
});

it('four-oh-fours an unknown node type', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/nope.missing/fields/template/options")
        ->assertNotFound();
});

it('four-oh-fours a field the node does not declare', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/nope/options")
        ->assertNotFound();
});

it('four-oh-fours a field that has no dynamic source', function () {
    // A static-optioned field's options are already in the palette payload; asking
    // for them here means the client is confused, and answering would imply the
    // endpoint is the place to get them.
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/channel/options")
        ->assertNotFound();
});

it('fails loudly when the declared source is not an OptionSource', function () {
    // Counterfactual: duck-type on method_exists and this returns the sneaky
    // options — or, worse, an empty list indistinguishable from "no templates".
    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.bad_source/fields/template/options")
        ->assertStatus(500);
});

it('denies when the host has not defined the update gate', function () {
    // Options are edit-time data about the tenant's own records, so they sit
    // behind the same gate as editing.
    Gate::define('nodeflow.update', fn ($user, $flow = null) => false);

    $this->actingAs($this->user)
        ->getJson("/nodeflow/flows/{$this->flow->id}/nodes/test.dynamic_options/fields/template/options")
        ->assertForbidden();
});
```

- [ ] **Step 2: Write the two test nodes**

They go in `tests/Support/` alongside the codebase's other node doubles, not inline in the Pest file — a class declared in a test file is legal but collides the moment a second file wants the same name, and `tests/Support/` is where `FakeSendNode` and its siblings already live.

Create `tests/Support/DynamicOptionNode.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

/** One field with a dynamic source, one with static options, so both paths are reachable. */
class DynamicOptionNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.dynamic_options';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Dynamic')->fields([
            Field::select('template')->optionsFrom(FakeOptionSource::class),
            Field::select('channel')->options(['sms' => 'SMS']),
        ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        return $context->continue();
    }
}
```

Create `tests/Support/BadSourceNode.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

/** Declares a source that does not implement OptionSource, to prove that fails loudly. */
class BadSourceNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.bad_source';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Bad')->fields([
            Field::select('template')->optionsFrom(NotAnOptionSource::class),
        ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        return $context->continue();
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `vendor/bin/pest --filter='FieldOptionsRoute'`

Expected: FAIL — the route does not exist, so every case 404s including the ones expecting 200.

- [ ] **Step 4: Add the route**

Append to `src/Http/routes.php`:

```php
/*
 * Keyed by node type and field key, never by a class name. The source is read from
 * the node's own definition() — an endpoint that accepted the class from the client
 * would be "instantiate any class in this application and call options() on it".
 */
Route::get('flows/{flow}/nodes/{type}/fields/{field}/options', FieldOptionsController::class)
    ->name('nodeflow.fields.options');
```

And add its import at the top beside the existing controller import:

```php
use Nodeflow\Http\Controllers\FieldOptionsController;
```

- [ ] **Step 5: Write the controller**

Create `src/Http/Controllers/FieldOptionsController.php`:

```php
<?php

namespace Nodeflow\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Nodeflow\Models\Flow;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\OptionSource;
use Nodeflow\Schema\UnknownOptionSourceException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Resolves one field's options for the current tenant, at edit time.
 *
 * Options are resolved lazily, per field, rather than baked into the palette:
 * eager resolution would run every option source of every registered node on every
 * editor page load, including nodes the author never places — a dozen domain nodes
 * would mean a dozen tenant-scoped lookups to draw a sidebar.
 *
 * The route carries a node type and a field key. It does NOT carry the source
 * class, and this controller never reads one from the request. The class comes from
 * the node's own definition(), so the set of instantiable classes is exactly the
 * set some node declared — not "anything in the application".
 */
class FieldOptionsController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Flow $flow, string $type, string $field): JsonResponse
    {
        $this->authorize('update', $flow);

        $registry = app(NodeRegistry::class);

        if (! $registry->has($type)) {
            throw new NotFoundHttpException("Unknown node type [{$type}].");
        }

        $declared = $this->field($registry->resolve($type)->definition()->fieldObjects(), $field);

        if ($declared === null) {
            throw new NotFoundHttpException("Node type [{$type}] declares no field [{$field}].");
        }

        $sourceClass = $declared->optionsSourceClass();

        if ($sourceClass === null) {
            // Static options already travel in the palette payload. Answering here
            // would imply this endpoint is where they come from.
            throw new NotFoundHttpException("Field [{$field}] on [{$type}] has no dynamic option source.");
        }

        $source = app($sourceClass);

        if (! $source instanceof OptionSource) {
            throw UnknownOptionSourceException::notAnOptionSource($sourceClass);
        }

        return response()->json(['options' => $source->options()]);
    }

    /** @param  Field[]  $fields */
    private function field(array $fields, string $key): ?Field
    {
        foreach ($fields as $candidate) {
            if ($candidate->key === $key) {
                return $candidate;
            }
        }

        return null;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `vendor/bin/pest --filter='FieldOptionsRoute'`

Expected: PASS, 7 tests.

- [ ] **Step 7: Run the whole suite**

Run: `vendor/bin/pest`

Expected: PASS, 300 tests.

- [ ] **Step 8: Commit**

```bash
git add src/Http tests/Support/DynamicOptionNode.php tests/Support/BadSourceNode.php tests/Feature/FieldOptionsRouteTest.php
git commit -m "feat: resolve field options by node type and field key"
```

---

## Task 7: Document the server surface

**Files:**
- Modify: `docs/02-integration.md`

**Interfaces:**
- Consumes: the route names from Tasks 5 and 6, the `OptionSource` contract from Task 4, the `auto` mode from Task 1.
- Produces: nothing consumed by code.

- [ ] **Step 1: Read the current structure**

Run: `grep -n '^#\{2,3\} ' docs/02-integration.md`

The tenancy section already documents `disabled` and `resolver`; the gates section already documents the four gates. Your additions are the `auto` mode inside the former, and a new editor-routes section after the latter.

- [ ] **Step 2: Update the tenancy section for `auto`**

The tenancy section currently presents two modes and tells a host implementing `TenantResolver` to set `resolver`. Replace that advice, because it is now the default behaviour rather than something to configure:

```markdown
- **`auto`** (the default) — the package infers what a null tenant means. If you
  never bound a `TenantResolver`, ours answers, and a null means "this application
  has no tenancy": reads are unscoped. If you bound your own, a null means it could
  not be resolved — a queue worker, a console command, an unauthenticated request —
  and a scoped read throws `Nodeflow\Models\TenancyUnresolvedException` instead of
  quietly returning every tenant's rows.
- **`disabled`** — always treat null as "no tenancy" and read unscoped. The escape
  hatch if you bind a resolver and genuinely want that.
- **`resolver`** — always treat null as unresolved and throw.

**You should not normally need to set this.** `auto` is right for both the
single-tenant host and the multi-tenant one; the two explicit modes exist for the
cases where inference is wrong. An unrecognised value throws rather than degrading
to unscoped.
```

- [ ] **Step 3: Document the routes**

Add a new section after the gates section:

````markdown
## The editor's routes

The editor's server endpoints are **opt-in**. Register them inside your own group,
so prefix, middleware and domain stay your decisions:

```php
// routes/web.php
use Nodeflow\Nodeflow;

Route::middleware(['web', 'auth'])->prefix('admin')->group(
    fn () => Nodeflow::routes()
);
```

| Method | URI | Name | Gate |
|---|---|---|---|
| `GET` | `flows/{flow}/edit` | `nodeflow.flows.edit` | `nodeflow.update` |
| `PUT` | `flows/{flow}/draft` | `nodeflow.flows.draft` | `nodeflow.update` |
| `POST` | `flows/{flow}/publish` | `nodeflow.flows.publish` | `nodeflow.publish` |
| `GET` | `flows/{flow}/nodes/{type}/fields/{field}/options` | `nodeflow.fields.options` | `nodeflow.update` |

`{flow}` binds through the tenant-scoped model, so another tenant's id is a **404**,
not a 403 — a 403 would confirm the row exists.

**If you never call `Nodeflow::routes()`, none of this loads.** That is the
engine-only setup: run flows from triggers and code, with no editor and no Inertia
dependency.

**The editor page needs Inertia.** `inertiajs/inertia-laravel` is a *suggested*
dependency, not a required one, precisely so the engine-only host does not carry it.
Install it if you use these routes.

### Drafts

`PUT .../draft` takes `{graph, draft_updated_at}` and returns the new
`draft_updated_at`. Echo that value back on the next save: if it does not match what
the server holds, you get **409** with the newer `graph` and token, so the editor can
say "someone else edited this" rather than silently discarding a colleague's work.

**Draft saves are not validated.** A graph mid-edit is allowed to be broken — that is
why a draft is not a version. Validation happens at publish.

### Publish

`POST .../publish` takes `{graph}` and returns `{version}`. On rejection it returns
**422** with both shapes of the same failures:

- `errors` — flat strings, fine for a summary banner
- `node_errors` — `[{node, field, message}]`, so each message can be rendered on its
  own node. `node` is `null` for a graph-level problem such as a cycle, which belongs
  to no single node.

Publishing clears the draft, since the draft became the version.
````

- [ ] **Step 4: Document option sources**

Add after the routes section:

````markdown
## Tenant-scoped field options

A field whose choices are your data — this organisation's message templates, its
towns — cannot have them baked into the node class, because one class serves every
tenant. Declare a source instead:

```php
Field::select('template')->optionsFrom(YayaTemplates::class)
```

and implement the contract:

```php
use Nodeflow\Schema\OptionSource;

class YayaTemplates implements OptionSource
{
    public function options(): array
    {
        // Runs inside the request, with your tenancy resolver already in play.
        return Template::pluck('name', 'id')->all();
    }
}
```

Options resolve **lazily**, when the editor renders that field, not when it builds
the palette — otherwise every option source of every registered node would run on
every page load, including nodes the author never places.

Two things worth knowing:

- **The endpoint is keyed by node type and field key, never by class name.** The
  class comes from the node's own `definition()`. An endpoint that accepted it from
  the client would instantiate arbitrary application classes.
- **A class that does not implement `OptionSource` is an error, not an empty list.**
  An empty select looks identical to a tenant that genuinely has no templates yet,
  which is the harder bug to find.

### Custom field types

For a control the package does not ship — a town picker on a map — declare a custom
type and give it a validation rule, since publish-time validation must work for a
type the package has never seen:

```php
Field::custom('destination', 'town')            // validates as a string
Field::custom('altitude', 'elevation', 'numeric')
```

The matching React control is registered in the editor; see the editor's own docs.
````

- [ ] **Step 5: Verify insertions only where intended**

Run: `git diff --stat docs/02-integration.md`

Expected: mostly insertions. The tenancy-mode replacement in Step 2 is the one place deletions are correct — verify each deleted line is part of the two-mode text you replaced, and nothing else.

- [ ] **Step 6: Run the suite**

Run: `vendor/bin/pest`

Expected: PASS, 300 tests. No code changed, but confirm the tree is green before committing.

- [ ] **Step 7: Commit**

```bash
git add docs/02-integration.md
git commit -m "docs: document the editor routes, drafts and option sources"
```

---

## Definition of done

- `vendor/bin/pest` passes at **300 tests**, with all 259 pre-existing unedited.
- `nodeflow.tenancy` defaults to `auto` and infers from which resolver is bound; `disabled` and `resolver` still override; an unrecognised value still throws.
- A draft round-trips, refuses a stale write with 409 and the newer graph, accepts an invalid graph, and is cleared on publish.
- Publish returns per-node errors, and `GraphValidationResult::errors()` still returns the same strings it did before.
- The options endpoint resolves from the node's definition, ignores a client-supplied class name, 404s on unknown type / unknown field / static field, and errors on a non-`OptionSource`.
- Another tenant's flow is a 404 on every route.
- No route accepts `current_version_id` or `flow_version_id` from input.
- An engine-only host — one that never calls `Nodeflow::routes()` — needs no Inertia.

## Deliberately not in this plan

- **All React, all JS.** `resources/js`, the six field controls, the controls prop merge, autosave, `package.json`, Vitest, host wiring — that is Plan 3b.
- **The run view** — `FlowRun`, overlay queries, subject drill-down, polling. Plan 4.
- **`nodeflow:install`**, `make-trigger`, `make-subject-attribute` — Plan 5. Note the provider it generates must contain `NodeRegistrationWriter::ANCHOR`.
- **Templates** — the table exists; the fork-on-install path is later.
- **Open issues G-1, G-2, C-1 to C-4** — logged in `docs/superpowers/open-issues.md`, none in this plan's path.
