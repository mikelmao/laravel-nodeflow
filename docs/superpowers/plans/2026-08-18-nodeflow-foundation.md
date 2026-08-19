# laravel-nodeflow Foundation & Engine — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the headless core of laravel-nodeflow — storage, immutable graph versioning, the node contract, the durable interpreter, triggers, and tenant isolation — such that a stored graph executes end to end on Laravel queues with no UI whatsoever.

**Architecture:** Graphs are stored as immutable versions; a run pins one version at start. A single interpreter workflow class does only control flow (cursor over nodes, timers, waits) and never touches the database directly, because the engine's boot-time guardrail scan rejects `DB::` in workflow code. All node bodies execute inside one generic activity. Node authors write single-subject code; the runtime chunks it across an audience and partitions subjects by the output each returned. Per-user runs are a cohort of one on the same code path.

**Tech Stack:** PHP 8.3, Laravel 12|13, `durable-workflow/workflow` ^2.0 (currently 2.0.0-rc.32), Pest 4, Orchestra Testbench, SQLite in-memory for tests.

**Spec:** `docs/superpowers/specs/2026-08-18-laravel-nodeflow-design.md`

## Global Constraints

- PHP `^8.3`. Laravel `^12.0|^13.0`. Package namespace `Nodeflow\`, composer name `atram/laravel-nodeflow`.
- All table names are prefixed `nodeflow_`. All models live under `Nodeflow\Models`.
- **The package must never reference a host model class by name** — no `Organization`, no `User`. Tenancy goes through `TenantResolver`; subjects go through `SubjectResolver`.
- **Workflow classes may not call `DB::`, `Http::`, `Carbon::now()`, `Auth::`, or `random_int()`.** The engine scans for these at boot and refuses to start. Use activities, or `Workflow\V2\now()`.
- **Every engine call goes through `Nodeflow\Engine\WorkflowEngine`.** No direct `Workflow\V2\...` usage outside `src/Engine/`. The engine is at release-candidate stability; this facade is what makes an upgrade one file.
- Engine structural limits that bound the design: 1,000 items per `all()` fan-out, 1,000 pending child workflows, 2,000 pending timers, **5,000 pending signals**, 2 MiB per argument payload, 5,000 history events per workflow task.
- **Audience is passed by handle (a `run_id`), never by value.** A six-figure subject list cannot fit in a 2 MiB payload.
- **The tenant-ownership check on materialised audiences is mandatory and non-disableable.** Cross-tenant leakage here means one bank's customers receive another bank's loan offers.
- Node `type()` returns a stable string decoupled from the class name. The registry maps string → class.
- Tests use SQLite in-memory via Testbench. No test may require a running queue worker; the engine facade has a synchronous fake.

---

## File Structure

```
src/
  NodeflowServiceProvider.php        registration, config, migrations, boot-time checks
  Nodeflow.php                       facade-ish entry: register nodes/triggers/attributes
  Contracts/
    TenantResolver.php               host supplies: currentTenantId(), tenantForRun()
    SubjectResolver.php              host supplies: how to load subjects by id
    AudienceResolver.php             host supplies: trigger payload -> subject id query
  Schema/
    Field.php                        one config field: type, key, label, rules, options
    FieldType.php                    enum of built-in field types
    NodeDefinition.php               label, group, outputs, fields
    TriggerDefinition.php            label, fields
  Nodes/
    Node.php                         abstract base: type(), definition(), defaultConfig(), validate()
    HandlesSubject.php               interface: forSubject(SubjectContext): NodeResult
    HandlesAudience.php              interface: forAudience(AudienceContext): NodeResult
    NodeRegistry.php                 type string -> class, alias map, resolution checks
    Core/WaitNode.php                Core/ConditionNode.php  Core/SplitNode.php
    Core/StartFlowNode.php           Core/ExitNode.php
  Execution/
    SubjectContext.php               subject(), config(), isTest(), continue()
    AudienceContext.php              subjects(), config(), isTest(), partition()
    NodeResult.php                   output name + per-subject outputs + failures
    AudienceMaterialiser.php         resolver -> run_subjects, with tenant check
    Audience.php                     read model over run_subjects for one run + node
  Engine/
    WorkflowEngine.php               interface: start, signal, cancel, isRunning
    DurableWorkflowEngine.php        the only file that imports Workflow\V2\
    FakeWorkflowEngine.php           synchronous, for tests
  Workflows/
    FlowInterpreter.php              THE workflow. Control flow only.
    Activities/LoadGraphActivity.php
    Activities/RunNodeActivity.php
    Activities/ResolveAudienceActivity.php
  Graph/
    Graph.php                        nodes + edges value object, from/to array
    GraphValidator.php               acyclicity, orphans, unknown types, unreachable
  Triggers/
    Trigger.php                      abstract base
    TriggerRegistry.php
    TriggerMatch.php                 per-tenant audience queries
    EventTriggerListener.php
  Publishing/
    PublishFlow.php                  validate + freeze immutable version
  Models/
    Flow.php  FlowVersion.php  Run.php  RunSubject.php  NodeExecution.php  Template.php
    Concerns/BelongsToTenant.php     global scope bound to TenantResolver
config/nodeflow.php
database/migrations/*.php
tests/
```

**Responsibility boundaries that matter:** `Workflows/` is the only place with determinism constraints and must stay tiny. `Engine/` is the only place that knows the engine exists. `Nodes/` and `Schema/` are the public authoring surface and must stay pleasant. `Execution/` is where cohort-vs-subject complexity is absorbed so nodes never see it.

---

## Task 1: Package skeleton, test harness, service provider

**Files:**
- Create: `composer.json`, `src/NodeflowServiceProvider.php`, `config/nodeflow.php`, `phpunit.xml`, `tests/TestCase.php`, `tests/Pest.php`, `tests/Feature/ServiceProviderTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Nodeflow\NodeflowServiceProvider`; a Testbench-backed `Tests\TestCase` every later test extends; config key `nodeflow`.

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "atram/laravel-nodeflow",
    "description": "Visual workflow builder and durable execution engine for Laravel + Inertia + React apps",
    "license": "MIT",
    "require": {
        "php": "^8.3",
        "illuminate/support": "^12.0|^13.0",
        "illuminate/database": "^12.0|^13.0",
        "durable-workflow/workflow": "^2.0@rc"
    },
    "require-dev": {
        "orchestra/testbench": "^10.0|^11.0",
        "pestphp/pest": "^4.0"
    },
    "minimum-stability": "dev",
    "prefer-stable": true,
    "autoload": { "psr-4": { "Nodeflow\\": "src/" } },
    "autoload-dev": { "psr-4": { "Tests\\": "tests/" } },
    "extra": {
        "laravel": { "providers": ["Nodeflow\\NodeflowServiceProvider"] }
    },
    "config": { "allow-plugins": { "pestphp/pest-plugin": true } }
}
```

Run: `composer install`

- [ ] **Step 2: Write the failing test**

`tests/Feature/ServiceProviderTest.php`:

```php
<?php

it('boots and publishes config', function () {
    expect(config('nodeflow.tables.prefix'))->toBe('nodeflow_');
});
```

`tests/TestCase.php`:

```php
<?php

namespace Tests;

use Nodeflow\NodeflowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [NodeflowServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
```

`tests/Pest.php`:

```php
<?php

uses(Tests\TestCase::class)->in('Feature', 'Unit');
```

`phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         colors="true">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ServiceProviderTest.php`
Expected: FAIL — class `Nodeflow\NodeflowServiceProvider` not found.

- [ ] **Step 4: Write minimal implementation**

`config/nodeflow.php`:

```php
<?php

return [
    'tables' => ['prefix' => 'nodeflow_'],
    'retention' => ['runs_days' => 90, 'node_executions_days' => 90],
    'limits' => ['max_steps_per_run' => 1000, 'subject_chunk' => 500],
];
```

`src/NodeflowServiceProvider.php`:

```php
<?php

namespace Nodeflow;

use Illuminate\Support\ServiceProvider;

class NodeflowServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/nodeflow.php', 'nodeflow');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/nodeflow.php' => config_path('nodeflow.php'),
            ], 'nodeflow-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'nodeflow-migrations');
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest`
Expected: PASS, 1 test.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock config src tests phpunit.xml
git commit -m "feat: package skeleton with testbench harness"
```

---

## Task 2: Migrations and models

**Files:**
- Create: `database/migrations/2026_08_18_000001_create_nodeflow_tables.php`
- Create: `src/Models/{Flow,FlowVersion,Run,RunSubject,NodeExecution,Template}.php`
- Test: `tests/Feature/SchemaTest.php`

**Interfaces:**
- Consumes: `NodeflowServiceProvider` (Task 1).
- Produces: Eloquent models. `Flow` has `versions()`, `currentVersion()`, `runs()`. `Run` has `subjects()`, `nodeExecutions()`, `flowVersion()`. `RunSubject` columns: `run_id`, `subject_type`, `subject_id`, `current_node_id`, `status`, `last_error`, `exited_at`. `NodeExecution` columns: `run_id`, `node_id`, `output`, `subject_count`, `duration_ms`, `error`. Run statuses: `pending|running|waiting|completed|failed|cancelled|blocked`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/SchemaTest.php`:

```php
<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

it('persists a flow with an immutable version and a run', function () {
    $flow = Flow::create([
        'tenant_id' => 'org-1',
        'name' => 'Flood alert journey',
        'trigger_type' => 'rada.alert',
        'status' => 'draft',
    ]);

    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 1,
        'graph' => ['nodes' => [], 'edges' => []],
        'content_hash' => 'abc123',
    ]);

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    RunSubject::create([
        'run_id' => $run->id,
        'subject_type' => 'user',
        'subject_id' => '42',
        'status' => 'active',
    ]);

    expect($flow->versions)->toHaveCount(1)
        ->and($run->flowVersion->id)->toBe($version->id)
        ->and($run->subjects)->toHaveCount(1)
        ->and($version->graph)->toBe(['nodes' => [], 'edges' => []]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/SchemaTest.php`
Expected: FAIL — class `Nodeflow\Models\Flow` not found.

- [ ] **Step 3: Write the migration**

`database/migrations/2026_08_18_000001_create_nodeflow_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nodeflow_flows', function (Blueprint $t) {
            $t->id();
            $t->string('tenant_id')->index();
            $t->string('name');
            $t->string('trigger_type');
            $t->json('trigger_config')->nullable();
            $t->string('status')->default('draft');
            $t->string('reentry_policy')->default('reenter');
            $t->foreignId('current_version_id')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'trigger_type', 'status']);
        });

        Schema::create('nodeflow_flow_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('nodeflow_flows')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->json('graph');
            $t->string('content_hash');
            $t->timestamp('published_at')->nullable();
            $t->string('published_by')->nullable();
            $t->timestamps();
            $t->unique(['flow_id', 'version']);
        });

        Schema::create('nodeflow_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_version_id')->constrained('nodeflow_flow_versions');
            $t->string('tenant_id')->index();
            $t->string('correlation_id')->nullable()->index();
            $t->string('engine_workflow_id')->nullable()->index();
            $t->string('strategy');
            $t->string('status')->default('pending');
            $t->boolean('is_test')->default(false);
            $t->string('idempotency_key')->nullable();
            $t->unsignedInteger('steps_taken')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->unique(['flow_version_id', 'idempotency_key']);
        });

        Schema::create('nodeflow_run_subjects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('nodeflow_runs')->cascadeOnDelete();
            $t->string('subject_type');
            $t->string('subject_id');
            $t->string('current_node_id')->nullable();
            $t->string('status')->default('active');
            $t->text('last_error')->nullable();
            $t->timestamp('exited_at')->nullable();
            $t->unique(['run_id', 'subject_type', 'subject_id']);
            $t->index(['run_id', 'current_node_id', 'status']);
        });

        Schema::create('nodeflow_node_executions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('run_id')->constrained('nodeflow_runs')->cascadeOnDelete();
            $t->string('node_id');
            $t->string('output')->nullable();
            $t->unsignedInteger('subject_count')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['run_id', 'node_id']);
        });

        Schema::create('nodeflow_templates', function (Blueprint $t) {
            $t->id();
            $t->string('scope');
            $t->string('tenant_id')->nullable()->index();
            $t->string('name');
            $t->text('description')->nullable();
            $t->json('graph');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'nodeflow_templates', 'nodeflow_node_executions', 'nodeflow_run_subjects',
            'nodeflow_runs', 'nodeflow_flow_versions', 'nodeflow_flows',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
```

- [ ] **Step 4: Write the models**

`src/Models/Flow.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Flow extends Model
{
    protected $table = 'nodeflow_flows';

    protected $guarded = [];

    protected $casts = ['trigger_config' => 'array'];

    public function versions(): HasMany
    {
        return $this->hasMany(FlowVersion::class);
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class, 'current_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasManyThrough(Run::class, FlowVersion::class);
    }
}
```

`src/Models/FlowVersion.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowVersion extends Model
{
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

    public function hasLiveRuns(): bool
    {
        return $this->runs()
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->exists();
    }
}
```

`src/Models/Run.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    protected $table = 'nodeflow_runs';

    protected $guarded = [];

    protected $casts = [
        'is_test' => 'boolean',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(RunSubject::class);
    }

    public function nodeExecutions(): HasMany
    {
        return $this->hasMany(NodeExecution::class);
    }

    public function activeSubjectCount(): int
    {
        return $this->subjects()->where('status', 'active')->count();
    }
}
```

`src/Models/RunSubject.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RunSubject extends Model
{
    protected $table = 'nodeflow_run_subjects';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['exited_at' => 'datetime'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
```

`src/Models/NodeExecution.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeExecution extends Model
{
    protected $table = 'nodeflow_node_executions';

    protected $guarded = [];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }
}
```

`src/Models/Template.php`:

```php
<?php

namespace Nodeflow\Models;

use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    protected $table = 'nodeflow_templates';

    protected $guarded = [];

    protected $casts = ['graph' => 'array'];
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/SchemaTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database src/Models tests/Feature/SchemaTest.php
git commit -m "feat: nodeflow tables and eloquent models"
```

---

## Task 3: Tenancy resolver and global scope

**Files:**
- Create: `src/Contracts/TenantResolver.php`, `src/Models/Concerns/BelongsToTenant.php`
- Modify: `src/Models/Flow.php`, `src/Models/Run.php`, `src/Models/Template.php` (add the trait)
- Modify: `src/NodeflowServiceProvider.php` (bind a null-object default)
- Test: `tests/Feature/TenancyTest.php`

**Interfaces:**
- Consumes: models from Task 2.
- Produces: `Nodeflow\Contracts\TenantResolver` with `currentTenantId(): ?string` and `ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool`. Models using `BelongsToTenant` are automatically scoped to `currentTenantId()` and stamped on create.

- [ ] **Step 1: Write the failing test**

`tests/Feature/TenancyTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver {
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

it('stamps the tenant id on create', function () {
    $flow = Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect($flow->tenant_id)->toBe('org-1');
});

it('hides other tenants rows', function () {
    Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(Flow::count())->toBe(0);

    $this->tenant = 'org-1';

    expect(Flow::count())->toBe(1);
});

it('can be escaped explicitly for system operations', function () {
    Flow::create(['name' => 'A', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->tenant = 'org-2';

    expect(Flow::withoutTenancy()->count())->toBe(1);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/TenancyTest.php`
Expected: FAIL — interface `Nodeflow\Contracts\TenantResolver` not found.

- [ ] **Step 3: Write the contract and trait**

`src/Contracts/TenantResolver.php`:

```php
<?php

namespace Nodeflow\Contracts;

interface TenantResolver
{
    public function currentTenantId(): ?string;

    /**
     * MANDATORY isolation check. Called for every subject before it is
     * materialised into an audience. Returning true for a subject the tenant
     * does not own is a cross-tenant data breach, not a bug.
     */
    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool;
}
```

`src/Models/Concerns/BelongsToTenant.php`:

```php
<?php

namespace Nodeflow\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Nodeflow\Contracts\TenantResolver;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('nodeflow_tenant', function (Builder $query) {
            $tenantId = app(TenantResolver::class)->currentTenantId();

            if ($tenantId !== null) {
                $query->where($query->getModel()->getTable().'.tenant_id', $tenantId);
            }
        });

        static::creating(function ($model) {
            $model->tenant_id ??= app(TenantResolver::class)->currentTenantId();
        });
    }

    public static function withoutTenancy(): Builder
    {
        return static::query()->withoutGlobalScope('nodeflow_tenant');
    }
}
```

- [ ] **Step 4: Apply the trait and bind a default**

Add `use Nodeflow\Models\Concerns\BelongsToTenant;` and `use BelongsToTenant;` to `Flow`, `Run`, and `Template`.

In `NodeflowServiceProvider::register()`, add:

```php
$this->app->bindIf(TenantResolver::class, fn () => new class implements TenantResolver {
    public function currentTenantId(): ?string
    {
        return null;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        return false;
    }
});
```

The default denies subject ownership. A host that forgets to bind a resolver gets empty audiences, not leaked ones.

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/TenancyTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 6: Commit**

```bash
git add src tests/Feature/TenancyTest.php
git commit -m "feat: tenant resolver contract and global scoping"
```

---

## Task 4: Field schema

**Files:**
- Create: `src/Schema/FieldType.php`, `src/Schema/Field.php`
- Test: `tests/Unit/FieldTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Field::text(string $key)`, `Field::select($key)`, `Field::multiselect($key)`, `Field::number($key)`, `Field::duration($key)`, `Field::boolean($key)`. Fluent: `->label(string)`, `->required()`, `->options(array)`, `->optionsFrom(string $class)`, `->help(string)`, `->default(mixed)`. Terminal: `->toArray(): array` and `->rules(): array` returning `[key => 'required|string']` style rules.

- [ ] **Step 1: Write the failing test**

`tests/Unit/FieldTest.php`:

```php
<?php

use Nodeflow\Schema\Field;

it('serialises a select field for the editor', function () {
    $field = Field::select('channel')
        ->label('Channel')
        ->options(['sms' => 'SMS', 'whatsapp' => 'WhatsApp'])
        ->default('sms')
        ->required();

    expect($field->toArray())->toBe([
        'key' => 'channel',
        'type' => 'select',
        'label' => 'Channel',
        'help' => null,
        'required' => true,
        'default' => 'sms',
        'options' => ['sms' => 'SMS', 'whatsapp' => 'WhatsApp'],
        'options_source' => null,
    ]);
});

it('derives a label from the key when not given', function () {
    expect(Field::text('template_key')->toArray()['label'])->toBe('Template key');
});

it('produces validation rules', function () {
    expect(Field::select('channel')->options(['sms' => 'SMS'])->required()->rules())
        ->toBe(['channel' => ['required', 'string', 'in:sms']]);

    expect(Field::number('attempts')->rules())
        ->toBe(['attempts' => ['nullable', 'numeric']]);

    expect(Field::duration('delay')->required()->rules())
        ->toBe(['delay' => ['required', 'string']]);
});

it('records a dynamic options source instead of inline options', function () {
    $field = Field::select('template')->optionsFrom('App\\Nodeflow\\YayaTemplates');

    expect($field->toArray()['options'])->toBe([])
        ->and($field->toArray()['options_source'])->toBe('App\\Nodeflow\\YayaTemplates')
        ->and($field->rules())->toBe(['template' => ['nullable', 'string']]);
});
```

Note the last assertion: a field with a dynamic options source cannot use an `in:` rule, because the valid set is only known at request time against the tenant.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/FieldTest.php`
Expected: FAIL — class `Nodeflow\Schema\Field` not found.

- [ ] **Step 3: Write the implementation**

`src/Schema/FieldType.php`:

```php
<?php

namespace Nodeflow\Schema;

enum FieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Select = 'select';
    case Multiselect = 'multiselect';
    case Duration = 'duration';

    public function baseRule(): string
    {
        return match ($this) {
            self::Number => 'numeric',
            self::Boolean => 'boolean',
            self::Multiselect => 'array',
            default => 'string',
        };
    }
}
```

`src/Schema/Field.php`:

```php
<?php

namespace Nodeflow\Schema;

use Illuminate\Support\Str;

class Field
{
    private ?string $label = null;

    private ?string $help = null;

    private bool $required = false;

    private mixed $default = null;

    private array $options = [];

    private ?string $optionsSource = null;

    private function __construct(
        public readonly string $key,
        public readonly FieldType $type,
    ) {}

    public static function text(string $key): self
    {
        return new self($key, FieldType::Text);
    }

    public static function number(string $key): self
    {
        return new self($key, FieldType::Number);
    }

    public static function boolean(string $key): self
    {
        return new self($key, FieldType::Boolean);
    }

    public static function select(string $key): self
    {
        return new self($key, FieldType::Select);
    }

    public static function multiselect(string $key): self
    {
        return new self($key, FieldType::Multiselect);
    }

    public static function duration(string $key): self
    {
        return new self($key, FieldType::Duration);
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function help(string $help): self
    {
        $this->help = $help;

        return $this;
    }

    public function required(bool $required = true): self
    {
        $this->required = $required;

        return $this;
    }

    public function default(mixed $default): self
    {
        $this->default = $default;

        return $this;
    }

    public function options(array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function optionsFrom(string $sourceClass): self
    {
        $this->optionsSource = $sourceClass;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type->value,
            'label' => $this->label ?? Str::ucfirst(str_replace('_', ' ', Str::snake($this->key))),
            'help' => $this->help,
            'required' => $this->required,
            'default' => $this->default,
            'options' => $this->options,
            'options_source' => $this->optionsSource,
        ];
    }

    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable', $this->type->baseRule()];

        if ($this->options !== [] && $this->optionsSource === null) {
            $rules[] = 'in:'.implode(',', array_keys($this->options));
        }

        return [$this->key => $rules];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/FieldTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Schema tests/Unit/FieldTest.php
git commit -m "feat: declarative config field schema"
```

---

## Task 5: Node base class, definition, and registry

**Files:**
- Create: `src/Schema/NodeDefinition.php`, `src/Nodes/Node.php`, `src/Nodes/HandlesSubject.php`, `src/Nodes/HandlesAudience.php`, `src/Nodes/NodeRegistry.php`, `src/Nodeflow.php`
- Modify: `src/NodeflowServiceProvider.php` (register the singleton)
- Test: `tests/Unit/NodeRegistryTest.php`, `tests/Unit/NodeDefinitionTest.php`

**Interfaces:**
- Consumes: `Field` (Task 4).
- Produces: `NodeDefinition::make(string $label)` with `->group(string)`, `->outputs(array)`, `->fields(array)`, `->toArray()`. `Node` abstract with `abstract public static function type(): string`, `abstract public function definition(): NodeDefinition`, `public function defaultConfig(): array`, `public function validate(array $config): array` (returns error messages keyed by field). `NodeRegistry` with `register(string ...$classes)`, `alias(string $old, string $new)`, `resolve(string $type): Node`, `has(string $type): bool`, `all(): array`, `palette(): array`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/NodeDefinitionTest.php`:

```php
<?php

use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

it('serialises a definition for the editor palette', function () {
    $definition = NodeDefinition::make('Send Message')
        ->group('Messaging')
        ->outputs(['sent', 'failed'])
        ->fields([Field::select('channel')->options(['sms' => 'SMS'])]);

    $array = $definition->toArray();

    expect($array['label'])->toBe('Send Message')
        ->and($array['group'])->toBe('Messaging')
        ->and($array['outputs'])->toBe(['sent', 'failed'])
        ->and($array['fields'][0]['key'])->toBe('channel');
});

it('defaults to a single output named default', function () {
    expect(NodeDefinition::make('Thing')->toArray()['outputs'])->toBe(['default']);
});
```

Shared test doubles live in `tests/Support/` and are autoloaded by the `Tests\` PSR-4 rule
from Task 1. Pest does **not** autoload classes declared inside other test files, so every
fake used by more than one test must live here.

`tests/Support/FakeSendNode.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FakeSendNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.send';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send')
            ->outputs(['sent', 'failed'])
            ->fields([Field::select('channel')->options(['sms' => 'SMS'])->required()]);
    }

    public function defaultConfig(): array
    {
        return ['channel' => 'sms'];
    }

    public function forSubject(SubjectContext $c): NodeResult
    {
        return $c->continue('sent');
    }
}
```

`tests/Support/FakeWaitNode.php` and `tests/Support/FakeExitNode.php` — stand-ins so the
graph validator can be tested before the real core nodes exist in Task 10. Both declare the
same `type()` strings the real ones will:

```php
<?php

namespace Tests\Support;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FakeWaitNode extends Node
{
    public static function type(): string
    {
        return 'core.wait';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Wait')
            ->outputs(['default'])
            ->fields([Field::duration('duration')->required()]);
    }
}

class FakeExitNode extends Node
{
    public static function type(): string
    {
        return 'core.exit';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Exit')->outputs([]);
    }
}
```

Put each class in its own file. `tests/Unit/NodeRegistryTest.php`:

```php
<?php

use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeSendNode;

it('resolves a node by its stable type string', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);

    expect($registry->resolve('test.send'))->toBeInstanceOf(FakeSendNode::class)
        ->and($registry->has('test.send'))->toBeTrue();
});

it('resolves a renamed type through an alias', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);
    $registry->alias('test.old_send', 'test.send');

    expect($registry->resolve('test.old_send'))->toBeInstanceOf(FakeSendNode::class);
});

it('throws a typed error for an unknown type', function () {
    $registry = new NodeRegistry;

    expect(fn () => $registry->resolve('test.missing'))
        ->toThrow(Nodeflow\Nodes\UnknownNodeTypeException::class, 'test.missing');
});

it('validates config against the definition', function () {
    $node = new FakeSendNode;

    expect($node->validate(['channel' => 'sms']))->toBe([])
        ->and($node->validate([]))->toHaveKey('channel')
        ->and($node->validate(['channel' => 'carrier-pigeon']))->toHaveKey('channel');
});

it('builds a palette grouped for the editor', function () {
    $registry = new NodeRegistry;
    $registry->register(FakeSendNode::class);

    $palette = $registry->palette();

    expect($palette)->toHaveCount(1)
        ->and($palette[0]['type'])->toBe('test.send')
        ->and($palette[0]['cardinality'])->toBe(['subject']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/NodeRegistryTest.php tests/Unit/NodeDefinitionTest.php`
Expected: FAIL — class `Nodeflow\Schema\NodeDefinition` not found.

- [ ] **Step 3: Write NodeDefinition**

`src/Schema/NodeDefinition.php`:

```php
<?php

namespace Nodeflow\Schema;

class NodeDefinition
{
    private string $group = 'General';

    private array $outputs = ['default'];

    /** @var Field[] */
    private array $fields = [];

    private ?string $icon = null;

    private ?string $description = null;

    private function __construct(public readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function group(string $group): self
    {
        $this->group = $group;

        return $this;
    }

    public function icon(string $icon): self
    {
        $this->icon = $icon;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function outputs(array $outputs): self
    {
        $this->outputs = $outputs;

        return $this;
    }

    /** @param  Field[]  $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /** @return Field[] */
    public function fieldObjects(): array
    {
        return $this->fields;
    }

    public function outputNames(): array
    {
        return $this->outputs;
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'group' => $this->group,
            'icon' => $this->icon,
            'description' => $this->description,
            'outputs' => $this->outputs,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
        ];
    }

    public function rules(): array
    {
        return array_merge(...array_map(fn (Field $f) => $f->rules(), $this->fields)) ?: [];
    }
}
```

- [ ] **Step 4: Write Node, the cardinality interfaces, and the registry**

`src/Nodes/HandlesSubject.php`:

```php
<?php

namespace Nodeflow\Nodes;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;

interface HandlesSubject
{
    public function forSubject(SubjectContext $context): NodeResult;
}
```

`src/Nodes/HandlesAudience.php`:

```php
<?php

namespace Nodeflow\Nodes;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;

interface HandlesAudience
{
    public function forAudience(AudienceContext $context): NodeResult;
}
```

`src/Nodes/Node.php`:

```php
<?php

namespace Nodeflow\Nodes;

use Illuminate\Support\Facades\Validator;
use Nodeflow\Schema\NodeDefinition;

abstract class Node
{
    /** Stable identifier. Never derive this from the class name. */
    abstract public static function type(): string;

    abstract public function definition(): NodeDefinition;

    public function defaultConfig(): array
    {
        return [];
    }

    /** Activity retry attempts for this node's body. */
    public int $tries = 3;

    /** @return array<string, array<string>> field key => messages */
    public function validate(array $config): array
    {
        return Validator::make($config, $this->definition()->rules())
            ->errors()
            ->toArray();
    }
}
```

`src/Nodes/UnknownNodeTypeException.php`:

```php
<?php

namespace Nodeflow\Nodes;

use RuntimeException;

class UnknownNodeTypeException extends RuntimeException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Unknown nodeflow node type [{$type}]. It is not registered and has no alias.");
    }
}
```

`src/Nodes/NodeRegistry.php`:

```php
<?php

namespace Nodeflow\Nodes;

class NodeRegistry
{
    /** @var array<string, class-string<Node>> */
    private array $types = [];

    /** @var array<string, string> */
    private array $aliases = [];

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->types[$class::type()] = $class;
        }

        return $this;
    }

    public function alias(string $oldType, string $newType): self
    {
        $this->aliases[$oldType] = $newType;

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$this->canonical($type)]);
    }

    public function resolve(string $type): Node
    {
        $canonical = $this->canonical($type);

        if (! isset($this->types[$canonical])) {
            throw new UnknownNodeTypeException($type);
        }

        return app($this->types[$canonical]);
    }

    /** @return array<string, class-string<Node>> */
    public function all(): array
    {
        return $this->types;
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type) {
            $node = app($class);

            $cardinality = [];

            if ($node instanceof HandlesSubject) {
                $cardinality[] = 'subject';
            }

            if ($node instanceof HandlesAudience) {
                $cardinality[] = 'audience';
            }

            return array_merge($node->definition()->toArray(), [
                'type' => $type,
                'default_config' => $node->defaultConfig(),
                'cardinality' => $cardinality,
            ]);
        }, $this->types, array_keys($this->types)));
    }

    private function canonical(string $type): string
    {
        return $this->aliases[$type] ?? $type;
    }
}
```

`src/Nodeflow.php`:

```php
<?php

namespace Nodeflow;

use Nodeflow\Nodes\NodeRegistry;

class Nodeflow
{
    public static function nodes(): NodeRegistry
    {
        return app(NodeRegistry::class);
    }

    public static function register(array $nodeClasses): void
    {
        static::nodes()->register(...$nodeClasses);
    }
}
```

In `NodeflowServiceProvider::register()`, add `$this->app->singleton(NodeRegistry::class);`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src tests/Unit
git commit -m "feat: node contract, definition, and registry with alias support"
```

---

## Task 6: Execution contexts and NodeResult

**Files:**
- Create: `src/Contracts/SubjectResolver.php`, `src/Execution/NodeResult.php`, `src/Execution/SubjectContext.php`, `src/Execution/AudienceContext.php`
- Test: `tests/Unit/NodeResultTest.php`

**Interfaces:**
- Consumes: models (Task 2), `Node` (Task 5).
- Produces: `NodeResult` with `NodeResult::forSubject(string $subjectId, string $output)`, `NodeResult::partition(array $outputToSubjectIds)`, `NodeResult::merge(NodeResult ...$results)`, `->outputs(): array<string, string[]>`, `->failures(): array<string, string>`. `SubjectContext` with `subject(): mixed`, `subjectId(): string`, `config(string $key, mixed $default = null)`, `isTest(): bool`, `continue(string $output = 'default'): NodeResult`, `fail(string $message): NodeResult`. `AudienceContext` with `subjects(): iterable`, `subjectIds(): array`, `config()`, `isTest()`, `partition(array): NodeResult`.
- `SubjectResolver`: `resolve(string $subjectType, array $subjectIds): array` returning `[subjectId => model]`.

**Why `NodeResult` carries subject IDs rather than a bare output name:** the runtime needs to know *which* subjects took *which* branch so it can partition the audience (D7). A `forSubject()` node produces a single-entry result; the activity merges hundreds of them.

- [ ] **Step 1: Write the failing test**

`tests/Unit/NodeResultTest.php`:

```php
<?php

use Nodeflow\Execution\NodeResult;

it('records one subject taking one output', function () {
    expect(NodeResult::forSubject('42', 'sent')->outputs())->toBe(['sent' => ['42']]);
});

it('merges many single-subject results into a partition', function () {
    $merged = NodeResult::merge(
        NodeResult::forSubject('1', 'yes'),
        NodeResult::forSubject('2', 'no'),
        NodeResult::forSubject('3', 'yes'),
    );

    expect($merged->outputs())->toBe(['yes' => ['1', '3'], 'no' => ['2']]);
});

it('accepts a bulk partition directly', function () {
    $result = NodeResult::partition(['sent' => ['1', '2'], 'failed' => ['3']]);

    expect($result->outputs())->toBe(['sent' => ['1', '2'], 'failed' => ['3']])
        ->and($result->subjectCount())->toBe(3);
});

it('records failures separately from outputs', function () {
    $result = NodeResult::failed('7', 'gateway timeout');

    expect($result->outputs())->toBe([])
        ->and($result->failures())->toBe(['7' => 'gateway timeout']);
});

it('merges failures alongside outputs', function () {
    $merged = NodeResult::merge(
        NodeResult::forSubject('1', 'sent'),
        NodeResult::failed('2', 'no channel'),
    );

    expect($merged->outputs())->toBe(['sent' => ['1']])
        ->and($merged->failures())->toBe(['2' => 'no channel']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/NodeResultTest.php`
Expected: FAIL — class `Nodeflow\Execution\NodeResult` not found.

- [ ] **Step 3: Write NodeResult**

`src/Execution/NodeResult.php`:

```php
<?php

namespace Nodeflow\Execution;

class NodeResult
{
    private function __construct(
        private array $outputs = [],
        private array $failures = [],
    ) {}

    public static function forSubject(string $subjectId, string $output = 'default'): self
    {
        return new self([$output => [$subjectId]]);
    }

    public static function partition(array $outputToSubjectIds): self
    {
        return new self(array_map(
            fn (array $ids) => array_values(array_map('strval', $ids)),
            $outputToSubjectIds,
        ));
    }

    public static function failed(string $subjectId, string $message): self
    {
        return new self([], [$subjectId => $message]);
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function merge(self ...$results): self
    {
        $outputs = [];
        $failures = [];

        foreach ($results as $result) {
            foreach ($result->outputs as $output => $ids) {
                $outputs[$output] = array_merge($outputs[$output] ?? [], $ids);
            }

            $failures += $result->failures;
        }

        return new self($outputs, $failures);
    }

    /** @return array<string, string[]> */
    public function outputs(): array
    {
        return $this->outputs;
    }

    /** @return array<string, string> */
    public function failures(): array
    {
        return $this->failures;
    }

    public function subjectCount(): int
    {
        return array_sum(array_map('count', $this->outputs));
    }
}
```

- [ ] **Step 4: Write the contexts and the subject resolver contract**

`src/Contracts/SubjectResolver.php`:

```php
<?php

namespace Nodeflow\Contracts;

interface SubjectResolver
{
    /**
     * @param  string[]  $subjectIds
     * @return array<string, mixed> subjectId => the host's model
     */
    public function resolve(string $subjectType, array $subjectIds): array;
}
```

`src/Execution/SubjectContext.php`:

```php
<?php

namespace Nodeflow\Execution;

use Nodeflow\Models\Run;

class SubjectContext
{
    public function __construct(
        private Run $run,
        private string $nodeId,
        private array $config,
        private string $subjectId,
        private mixed $subject,
    ) {}

    public function run(): Run
    {
        return $this->run;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    public function subject(): mixed
    {
        return $this->subject;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }

    /**
     * True when this run must not cause externally visible side effects.
     * Every node that sends, charges, or writes to a third party MUST honour this.
     */
    public function isTest(): bool
    {
        return $this->run->is_test;
    }

    public function continue(string $output = 'default'): NodeResult
    {
        return NodeResult::forSubject($this->subjectId, $output);
    }

    public function fail(string $message): NodeResult
    {
        return NodeResult::failed($this->subjectId, $message);
    }
}
```

`src/Execution/AudienceContext.php`:

```php
<?php

namespace Nodeflow\Execution;

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Models\Run;

class AudienceContext
{
    public function __construct(
        private Run $run,
        private string $nodeId,
        private array $config,
        private string $subjectType,
        private array $subjectIds,
    ) {}

    public function run(): Run
    {
        return $this->run;
    }

    public function nodeId(): string
    {
        return $this->nodeId;
    }

    /** @return string[] */
    public function subjectIds(): array
    {
        return $this->subjectIds;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    /** @return array<string, mixed> subjectId => model */
    public function subjects(): array
    {
        return app(SubjectResolver::class)->resolve($this->subjectType, $this->subjectIds);
    }

    public function config(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->config : ($this->config[$key] ?? $default);
    }

    public function isTest(): bool
    {
        return $this->run->is_test;
    }

    public function partition(array $outputToSubjectIds): NodeResult
    {
        return NodeResult::partition($outputToSubjectIds);
    }

    public function all(string $output = 'default'): NodeResult
    {
        return NodeResult::partition([$output => $this->subjectIds]);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/NodeResultTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Execution src/Contracts tests/Unit/NodeResultTest.php
git commit -m "feat: execution contexts and NodeResult partitioning"
```

---

## Task 7: Audience materialisation with the mandatory tenant check

**Files:**
- Create: `src/Contracts/AudienceResolver.php`, `src/Execution/AudienceMaterialiser.php`, `src/Execution/CrossTenantSubjectException.php`
- Test: `tests/Feature/AudienceMaterialiserTest.php`

**Interfaces:**
- Consumes: `TenantResolver` (Task 3), `Run`/`RunSubject` (Task 2).
- Produces: `AudienceMaterialiser::materialise(Run $run, string $subjectType, iterable $subjectIds): int` returning the number of subjects written. Throws `CrossTenantSubjectException` on the first subject the tenant does not own.

**This is the highest-consequence task in the plan.** A defect here means one FSP's customers receive another FSP's messages.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AudienceMaterialiserTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceMaterialiser;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;

beforeEach(function () {
    $this->owned = ['1', '2', '3'];

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return in_array($subjectId, $this->test->owned, true);
        }
    });

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'pending',
    ]);
});

it('materialises owned subjects into run_subjects', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2']);

    expect($count)->toBe(2)
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1', '2'])
        ->and($this->run->subjects()->first()->status)->toBe('active');
});

it('refuses a subject the tenant does not own', function () {
    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '999']))
        ->toThrow(CrossTenantSubjectException::class, '999');
});

it('writes nothing at all when any subject fails the check', function () {
    try {
        app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '999']);
    } catch (CrossTenantSubjectException) {
        // expected
    }

    expect($this->run->subjects()->count())->toBe(0);
});

it('deduplicates repeated subject ids', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '1', '2']);

    expect($count)->toBe(2);
});
```

The third test matters: a partial write would leave a run half-populated with a breach already committed. Materialisation is all-or-nothing.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/AudienceMaterialiserTest.php`
Expected: FAIL — class `Nodeflow\Execution\AudienceMaterialiser` not found.

- [ ] **Step 3: Write the implementation**

`src/Contracts/AudienceResolver.php`:

```php
<?php

namespace Nodeflow\Contracts;

interface AudienceResolver
{
    /**
     * The subject type this resolver produces, e.g. 'user'.
     */
    public function subjectType(): string;

    /**
     * Subject ids for one tenant. May return a lazy iterable; it will be chunked.
     *
     * @return iterable<string>
     */
    public function subjectIds(string $tenantId, array $payload): iterable;
}
```

`src/Execution/CrossTenantSubjectException.php`:

```php
<?php

namespace Nodeflow\Execution;

use RuntimeException;

class CrossTenantSubjectException extends RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $subjectType,
        public readonly string $subjectId,
    ) {
        parent::__construct(
            "Tenant [{$tenantId}] does not own {$subjectType} [{$subjectId}]. ".
            'Audience materialisation aborted; no subjects were written.'
        );
    }
}
```

`src/Execution/AudienceMaterialiser.php`:

```php
<?php

namespace Nodeflow\Execution;

use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Run;

class AudienceMaterialiser
{
    public function __construct(private TenantResolver $tenants) {}

    public function materialise(Run $run, string $subjectType, iterable $subjectIds, ?string $startNodeId = null): int
    {
        $seen = [];

        foreach ($subjectIds as $subjectId) {
            $subjectId = (string) $subjectId;

            if (isset($seen[$subjectId])) {
                continue;
            }

            if (! $this->tenants->ownsSubject($run->tenant_id, $subjectType, $subjectId)) {
                throw new CrossTenantSubjectException($run->tenant_id, $subjectType, $subjectId);
            }

            $seen[$subjectId] = true;
        }

        $rows = array_map(fn (string $id) => [
            'run_id' => $run->id,
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'current_node_id' => $startNodeId,
            'status' => 'active',
        ], array_keys($seen));

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('nodeflow_run_subjects')->insert($chunk);
            }
        });

        return count($rows);
    }
}
```

The ownership pass completes fully before a single row is written. That is what makes the all-or-nothing test pass, and it is deliberate: validating lazily while inserting would commit rows before discovering the breach.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Feature/AudienceMaterialiserTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src tests/Feature/AudienceMaterialiserTest.php
git commit -m "feat: audience materialisation with mandatory tenant ownership check"
```

---

## Task 8: Engine facade

**Files:**
- Create: `src/Engine/WorkflowEngine.php`, `src/Engine/FakeWorkflowEngine.php`, `src/Engine/DurableWorkflowEngine.php`
- Modify: `src/NodeflowServiceProvider.php`
- Test: `tests/Unit/FakeWorkflowEngineTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `WorkflowEngine` with `start(string $workflowClass, array $args): string` (returns engine workflow id), `signal(string $workflowId, string $method, array $args): void`, `cancel(string $workflowId): void`, `isRunning(string $workflowId): bool`. `FakeWorkflowEngine` records calls and exposes `started(): array`, `signals(): array`, `cancelled(): array`.

**`DurableWorkflowEngine` is the only file in the package permitted to import `Workflow\V2\`.** Add a test that enforces this in Task 16.

- [ ] **Step 1: Write the failing test**

`tests/Unit/FakeWorkflowEngineTest.php`:

```php
<?php

use Nodeflow\Engine\FakeWorkflowEngine;

it('records starts, signals, and cancellations', function () {
    $engine = new FakeWorkflowEngine;

    $id = $engine->start('SomeWorkflow', ['run_id' => 1]);

    $engine->signal($id, 'subjectExited', [['7']]);
    $engine->cancel($id);

    expect($engine->started())->toBe([['workflow' => 'SomeWorkflow', 'args' => ['run_id' => 1], 'id' => $id]])
        ->and($engine->signals())->toBe([['id' => $id, 'method' => 'subjectExited', 'args' => [['7']]]])
        ->and($engine->cancelled())->toBe([$id])
        ->and($engine->isRunning($id))->toBeFalse();
});

it('reports a started workflow as running until cancelled', function () {
    $engine = new FakeWorkflowEngine;

    $id = $engine->start('SomeWorkflow', []);

    expect($engine->isRunning($id))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/FakeWorkflowEngineTest.php`
Expected: FAIL — class `Nodeflow\Engine\FakeWorkflowEngine` not found.

- [ ] **Step 3: Write the interface and the fake**

`src/Engine/WorkflowEngine.php`:

```php
<?php

namespace Nodeflow\Engine;

interface WorkflowEngine
{
    /** @return string the engine's workflow id */
    public function start(string $workflowClass, array $args): string;

    public function signal(string $workflowId, string $method, array $args = []): void;

    public function cancel(string $workflowId): void;

    public function isRunning(string $workflowId): bool;
}
```

`src/Engine/FakeWorkflowEngine.php`:

```php
<?php

namespace Nodeflow\Engine;

class FakeWorkflowEngine implements WorkflowEngine
{
    private array $started = [];

    private array $signals = [];

    private array $cancelled = [];

    private int $nextId = 1;

    public function start(string $workflowClass, array $args): string
    {
        $id = 'fake-'.$this->nextId++;

        $this->started[] = ['workflow' => $workflowClass, 'args' => $args, 'id' => $id];

        return $id;
    }

    public function signal(string $workflowId, string $method, array $args = []): void
    {
        $this->signals[] = ['id' => $workflowId, 'method' => $method, 'args' => $args];
    }

    public function cancel(string $workflowId): void
    {
        $this->cancelled[] = $workflowId;
    }

    public function isRunning(string $workflowId): bool
    {
        $wasStarted = collect($this->started)->contains(fn ($s) => $s['id'] === $workflowId);

        return $wasStarted && ! in_array($workflowId, $this->cancelled, true);
    }

    public function started(): array
    {
        return $this->started;
    }

    public function signals(): array
    {
        return $this->signals;
    }

    public function cancelled(): array
    {
        return $this->cancelled;
    }
}
```

- [ ] **Step 4: Write the real adapter**

`src/Engine/DurableWorkflowEngine.php`:

```php
<?php

namespace Nodeflow\Engine;

use Workflow\WorkflowStub;

class DurableWorkflowEngine implements WorkflowEngine
{
    public function start(string $workflowClass, array $args): string
    {
        $stub = WorkflowStub::make($workflowClass);

        $stub->start(...array_values($args));

        return (string) $stub->id();
    }

    public function signal(string $workflowId, string $method, array $args = []): void
    {
        WorkflowStub::load($workflowId)->{$method}(...$args);
    }

    public function cancel(string $workflowId): void
    {
        WorkflowStub::load($workflowId)->cancel();
    }

    public function isRunning(string $workflowId): bool
    {
        return WorkflowStub::load($workflowId)->running();
    }
}
```

**Verify these four calls against the installed engine version before moving on** — the package is at `2.0.0-rc.32` and the v2 API lives under `Workflow\V2\`. Run `composer show durable-workflow/workflow` and read `vendor/durable-workflow/workflow/src/WorkflowStub.php` to confirm the class path, the `id()`/`running()`/`cancel()` method names, and whether v2 requires the `Workflow\V2\` namespace instead. Adjust this file only — nothing else in the package touches the engine.

Bind in `NodeflowServiceProvider::register()`:

```php
$this->app->bind(WorkflowEngine::class, DurableWorkflowEngine::class);
```

And in `tests/TestCase.php`, override it so no test needs a queue worker:

```php
protected function defineEnvironment($app): void
{
    // ...existing database config...
    $app->singleton(\Nodeflow\Engine\WorkflowEngine::class, \Nodeflow\Engine\FakeWorkflowEngine::class);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/FakeWorkflowEngineTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 6: Commit**

```bash
git add src/Engine src/NodeflowServiceProvider.php tests
git commit -m "feat: engine facade isolating the durable-workflow dependency"
```

---

## Task 9: Graph value object and validator

**Files:**
- Create: `src/Graph/Graph.php`, `src/Graph/GraphValidator.php`, `src/Graph/GraphValidationResult.php`
- Test: `tests/Unit/GraphTest.php`, `tests/Unit/GraphValidatorTest.php`

**Interfaces:**
- Consumes: `NodeRegistry` (Task 5).
- Produces: `Graph::fromArray(array $graph)` with `->node(string $id): array`, `->startNodeId(): string`, `->targetsFor(string $nodeId, string $output): string[]`, `->nodeIds(): string[]`, `->toArray()`. `GraphValidator::validate(Graph $g): GraphValidationResult` with `->passes(): bool`, `->errors(): array<string>`, `->warnings(): array<string>`.

Graph JSON shape (this is the contract the React editor will produce):

```json
{
  "start": "n1",
  "nodes": [
    {"id": "n1", "type": "yaya.send_message", "config": {"channel": "sms"}, "position": {"x": 0, "y": 0}}
  ],
  "edges": [
    {"from": "n1", "output": "sent", "to": "n2"}
  ]
}
```

- [ ] **Step 1: Write the failing test**

`tests/Unit/GraphTest.php`:

```php
<?php

use Nodeflow\Graph\Graph;

function sampleGraph(): array
{
    return [
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'failed', 'to' => 'n3'],
        ],
    ];
}

it('reads nodes, start, and edges', function () {
    $graph = Graph::fromArray(sampleGraph());

    expect($graph->startNodeId())->toBe('n1')
        ->and($graph->node('n1')['type'])->toBe('test.send')
        ->and($graph->nodeIds())->toBe(['n1', 'n2', 'n3']);
});

it('resolves edge targets by output name', function () {
    $graph = Graph::fromArray(sampleGraph());

    expect($graph->targetsFor('n1', 'sent'))->toBe(['n2'])
        ->and($graph->targetsFor('n1', 'failed'))->toBe(['n3'])
        ->and($graph->targetsFor('n1', 'nonexistent'))->toBe([])
        ->and($graph->targetsFor('n2', 'default'))->toBe([]);
});

it('round trips through toArray', function () {
    expect(Graph::fromArray(sampleGraph())->toArray())->toBe(sampleGraph());
});
```

`tests/Unit/GraphValidatorTest.php`:

```php
<?php

use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Nodes\NodeRegistry;
use Tests\Support\FakeExitNode;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeWaitNode;

beforeEach(function () {
    $this->registry = new NodeRegistry;
    $this->registry->register(FakeSendNode::class, FakeExitNode::class, FakeWaitNode::class);
    $this->validator = new GraphValidator($this->registry);
});

it('passes a well formed graph', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]));

    expect($result->passes())->toBeTrue();
});

it('rejects an unknown node type', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'nope.missing', 'config' => []]],
        'edges' => [],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('nope.missing');
});

it('rejects a cycle', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n2', 'output' => 'sent', 'to' => 'n1'],
        ],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('cycle');
});

it('rejects invalid node config', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'pigeon']]],
        'edges' => [],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('channel');
});

it('rejects an edge pointing at a missing node', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']]],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'ghost']],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('ghost');
});

it('rejects an edge on an output the node does not declare', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'exploded', 'to' => 'n2']],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('exploded');
});

it('warns when two branches of a split both contain waits', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
            ['id' => 'w2', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'w1'],
            ['from' => 'n1', 'output' => 'failed', 'to' => 'w2'],
        ],
    ]));

    expect($result->passes())->toBeTrue()
        ->and(implode(' ', $result->warnings()))->toContain('sequentially');
});
```

That last warning documents a real v1 limitation — see Task 11.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/GraphTest.php tests/Unit/GraphValidatorTest.php`
Expected: FAIL — class `Nodeflow\Graph\Graph` not found.

- [ ] **Step 3: Write Graph**

`src/Graph/Graph.php`:

```php
<?php

namespace Nodeflow\Graph;

class Graph
{
    private function __construct(
        private string $start,
        private array $nodes,
        private array $edges,
    ) {}

    public static function fromArray(array $graph): self
    {
        $nodes = [];

        foreach ($graph['nodes'] ?? [] as $node) {
            $nodes[$node['id']] = $node;
        }

        return new self($graph['start'] ?? '', $nodes, $graph['edges'] ?? []);
    }

    public function startNodeId(): string
    {
        return $this->start;
    }

    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function nodeIds(): array
    {
        return array_keys($this->nodes);
    }

    public function edges(): array
    {
        return $this->edges;
    }

    /** @return string[] */
    public function targetsFor(string $nodeId, string $output): array
    {
        return array_values(array_map(
            fn (array $e) => $e['to'],
            array_filter(
                $this->edges,
                fn (array $e) => $e['from'] === $nodeId && $e['output'] === $output,
            ),
        ));
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'nodes' => array_values($this->nodes),
            'edges' => $this->edges,
        ];
    }
}
```

- [ ] **Step 4: Write the validator**

`src/Graph/GraphValidationResult.php`:

```php
<?php

namespace Nodeflow\Graph;

class GraphValidationResult
{
    public function __construct(
        private array $errors = [],
        private array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function warnings(): array
    {
        return $this->warnings;
    }
}
```

`src/Graph/GraphValidator.php`:

```php
<?php

namespace Nodeflow\Graph;

use Nodeflow\Nodes\NodeRegistry;

class GraphValidator
{
    public function __construct(private NodeRegistry $registry) {}

    public function validate(Graph $graph): GraphValidationResult
    {
        $errors = [];
        $warnings = [];

        if ($graph->startNodeId() === '' || $graph->node($graph->startNodeId()) === null) {
            $errors[] = 'The flow has no valid start node.';
        }

        foreach ($graph->nodeIds() as $id) {
            $node = $graph->node($id);
            $type = $node['type'] ?? '';

            if (! $this->registry->has($type)) {
                $errors[] = "Node [{$id}] uses unknown type [{$type}].";

                continue;
            }

            $instance = $this->registry->resolve($type);

            foreach ($instance->validate($node['config'] ?? []) as $field => $messages) {
                $errors[] = "Node [{$id}] field [{$field}]: ".implode(' ', $messages);
            }
        }

        foreach ($graph->edges() as $edge) {
            if ($graph->node($edge['to']) === null) {
                $errors[] = "Edge from [{$edge['from']}] points at missing node [{$edge['to']}].";
            }

            $from = $graph->node($edge['from']);

            if ($from !== null && $this->registry->has($from['type'] ?? '')) {
                $outputs = $this->registry->resolve($from['type'])->definition()->outputNames();

                if (! in_array($edge['output'], $outputs, true)) {
                    $errors[] = "Node [{$edge['from']}] has no output [{$edge['output']}].";
                }
            }
        }

        if ($this->hasCycle($graph)) {
            $errors[] = 'The flow contains a cycle. Flows must be acyclic.';
        }

        if ($this->hasConcurrentWaits($graph)) {
            $warnings[] = 'Two or more branches contain waits. In this version, branch waits '.
                'run sequentially rather than concurrently, so total elapsed time is the sum, not the maximum.';
        }

        return new GraphValidationResult($errors, $warnings);
    }

    private function hasCycle(Graph $graph): bool
    {
        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $graph): bool {
            if (($state[$id] ?? 'new') === 'visiting') {
                return true;
            }

            if (($state[$id] ?? 'new') === 'done') {
                return false;
            }

            $state[$id] = 'visiting';

            foreach ($graph->edges() as $edge) {
                if ($edge['from'] === $id && $graph->node($edge['to']) !== null && $visit($edge['to'])) {
                    return true;
                }
            }

            $state[$id] = 'done';

            return false;
        };

        foreach ($graph->nodeIds() as $id) {
            if ($visit($id)) {
                return true;
            }
        }

        return false;
    }

    private function hasConcurrentWaits(Graph $graph): bool
    {
        $branching = [];

        foreach ($graph->edges() as $edge) {
            $branching[$edge['from']][$edge['output']] = $edge['to'];
        }

        foreach ($branching as $outputs) {
            if (count($outputs) < 2) {
                continue;
            }

            $withWaits = array_filter(
                $outputs,
                fn (string $target) => ($graph->node($target)['type'] ?? '') === 'core.wait',
            );

            if (count($withWaits) >= 2) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/GraphTest.php tests/Unit/GraphValidatorTest.php`
Expected: PASS. The validator tests use the `Tests\Support` stand-ins, so this task is green on its own; Task 10 swaps in the real core nodes without changing the assertions.

- [ ] **Step 6: Commit**

```bash
git add src/Graph tests/Unit/Graph*
git commit -m "feat: graph value object and publish-time validator"
```

---

## Task 10: Core nodes and the subject attribute registry

**Files:**
- Create: `src/Nodes/Core/{ExitNode,WaitNode,ConditionNode,SplitNode}.php`
- Create: `src/Schema/SubjectAttribute.php`, `src/Schema/SubjectAttributeRegistry.php`
- Modify: `src/NodeflowServiceProvider.php` (register the core nodes and the attribute singleton)
- Test: `tests/Feature/CoreNodesTest.php`

**Interfaces:**
- Consumes: `Node`, `NodeDefinition`, `Field`, contexts, `NodeResult`.
- Produces: node types `core.exit`, `core.wait`, `core.condition`, `core.split`. (`core.start_flow` is Task 14.) `SubjectAttribute::make(string $key, string $label, string $type, callable $resolver)`; `SubjectAttributeRegistry::register(SubjectAttribute ...)`, `->options(): array` for the editor, `->value(string $key, mixed $subject): mixed`.

**Why conditions read from a registry rather than an expression language (D13):** the host declares which attributes are legible to FSP authors. Nothing else is reachable.

- [ ] **Step 1: Write the failing test**

`tests/Feature/CoreNodesTest.php`:

```php
<?php

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\Core\ConditionNode;
use Nodeflow\Nodes\Core\ExitNode;
use Nodeflow\Nodes\Core\WaitNode;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;

it('exit node declares no outputs', function () {
    expect(ExitNode::type())->toBe('core.exit')
        ->and((new ExitNode)->definition()->outputNames())->toBe([]);
});

it('wait node requires a duration and passes everyone through', function () {
    $node = new WaitNode;

    expect($node->validate([]))->toHaveKey('duration')
        ->and($node->validate(['duration' => '1 day']))->toBe([]);

    $run = new Run(['is_test' => false]);
    $context = new AudienceContext($run, 'w1', ['duration' => '1 day'], 'user', ['1', '2']);

    expect($node->forAudience($context)->outputs())->toBe(['default' => ['1', '2']]);
});

it('condition node branches per subject from registered attributes', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean', fn ($s) => $s['clicked']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);
    $config = ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null];

    $clicked = new SubjectContext($run, 'c1', $config, '1', ['clicked' => true]);
    $notClicked = new SubjectContext($run, 'c1', $config, '2', ['clicked' => false]);

    expect($node->forSubject($clicked)->outputs())->toBe(['yes' => ['1']])
        ->and($node->forSubject($notClicked)->outputs())->toBe(['no' => ['2']]);
});

it('condition node supports equals and in operators', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('severity', 'Severity', 'text', fn ($s) => $s['severity']),
    );

    $node = new ConditionNode;
    $run = new Run(['is_test' => false]);

    $equals = new SubjectContext($run, 'c1', ['attribute' => 'severity', 'operator' => 'equals', 'value' => 'red'], '1', ['severity' => 'red']);
    $in = new SubjectContext($run, 'c1', ['attribute' => 'severity', 'operator' => 'in', 'value' => ['orange', 'red']], '2', ['severity' => 'orange']);

    expect($node->forSubject($equals)->outputs())->toBe(['yes' => ['1']])
        ->and($node->forSubject($in)->outputs())->toBe(['yes' => ['2']]);
});

it('exposes registered attributes as editor options', function () {
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean', fn ($s) => true),
    );

    expect(app(SubjectAttributeRegistry::class)->options())->toBe(['clicked' => 'Has clicked']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/CoreNodesTest.php`
Expected: FAIL — class `Nodeflow\Nodes\Core\ExitNode` not found.

- [ ] **Step 3: Write the subject attribute registry**

`src/Schema/SubjectAttribute.php`:

```php
<?php

namespace Nodeflow\Schema;

use Closure;

class SubjectAttribute
{
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        private Closure $resolver,
    ) {}

    public static function make(string $key, string $label, string $type, callable $resolver): self
    {
        return new self($key, $label, $type, Closure::fromCallable($resolver));
    }

    public function value(mixed $subject): mixed
    {
        return ($this->resolver)($subject);
    }
}
```

`src/Schema/SubjectAttributeRegistry.php`:

```php
<?php

namespace Nodeflow\Schema;

use RuntimeException;

class SubjectAttributeRegistry
{
    /** @var array<string, SubjectAttribute> */
    private array $attributes = [];

    public function register(SubjectAttribute ...$attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->key] = $attribute;
        }

        return $this;
    }

    public function options(): array
    {
        return array_map(fn (SubjectAttribute $a) => $a->label, $this->attributes);
    }

    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function value(string $key, mixed $subject): mixed
    {
        if (! isset($this->attributes[$key])) {
            throw new RuntimeException("Unknown subject attribute [{$key}].");
        }

        return $this->attributes[$key]->value($subject);
    }
}
```

Register as a singleton in the service provider.

- [ ] **Step 4: Write the core nodes**

`src/Nodes/Core/ExitNode.php`:

```php
<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class ExitNode extends Node
{
    public static function type(): string
    {
        return 'core.exit';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Exit')
            ->group('Flow')
            ->description('Subjects reaching this node leave the flow successfully.')
            ->outputs([]);
    }
}
```

`src/Nodes/Core/WaitNode.php`:

```php
<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class WaitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.wait';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Wait')
            ->group('Flow')
            ->description('Pause. Subjects that exit the flow during the wait do not continue.')
            ->outputs(['default'])
            ->fields([
                Field::duration('duration')
                    ->label('Wait for')
                    ->help('A relative duration such as "5 minutes", "1 day", "2 weeks".')
                    ->required(),
            ]);
    }

    /**
     * The timer itself is the interpreter's business. By the time this runs, the
     * wait has already elapsed and the audience has already been re-resolved, so
     * everyone still active simply moves on.
     */
    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->all('default');
    }
}
```

`src/Nodes/Core/ConditionNode.php`:

```php
<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Schema\SubjectAttributeRegistry;

class ConditionNode extends Node implements HandlesSubject
{
    public const OPERATORS = [
        'is_true' => 'is true',
        'is_false' => 'is false',
        'equals' => 'equals',
        'not_equals' => 'does not equal',
        'in' => 'is one of',
        'greater_than' => 'is greater than',
        'less_than' => 'is less than',
    ];

    public static function type(): string
    {
        return 'core.condition';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Condition')
            ->group('Flow')
            ->outputs(['yes', 'no'])
            ->fields([
                Field::select('attribute')
                    ->label('Attribute')
                    ->optionsFrom(SubjectAttributeRegistry::class)
                    ->required(),
                Field::select('operator')->options(self::OPERATORS)->required(),
                Field::text('value')->label('Value'),
            ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        $actual = app(SubjectAttributeRegistry::class)
            ->value($context->config('attribute'), $context->subject());

        $expected = $context->config('value');

        $matches = match ($context->config('operator')) {
            'is_true' => (bool) $actual === true,
            'is_false' => (bool) $actual === false,
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'in' => in_array($actual, (array) $expected),
            'greater_than' => $actual > $expected,
            'less_than' => $actual < $expected,
            default => false,
        };

        return $context->continue($matches ? 'yes' : 'no');
    }
}
```

`src/Nodes/Core/SplitNode.php`:

```php
<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class SplitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.split';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Split')
            ->group('Flow')
            ->description('Send every subject down all connected branches.')
            ->outputs(['a', 'b']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->partition([
            'a' => $context->subjectIds(),
            'b' => $context->subjectIds(),
        ]);
    }
}
```

Register these four in `NodeflowServiceProvider::boot()` via `Nodeflow::register([...])`.
`StartFlowNode` is the fifth core node but depends on `SubFlowStarter`, so it is built in
Task 14 alongside it rather than left as a forward reference here.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/CoreNodesTest.php tests/Unit/GraphValidatorTest.php`
Expected: PASS, including the two validator tests that were previously blocked.

- [ ] **Step 6: Commit**

```bash
git add src tests/Feature/CoreNodesTest.php
git commit -m "feat: core flow nodes and subject attribute registry"
```

---

## Task 11: NodeRunner — executing a node across an audience

**Files:**
- Create: `src/Execution/NodeRunner.php`
- Modify: `src/Graph/GraphValidator.php` (reject an output with more than one edge)
- Test: `tests/Feature/NodeRunnerTest.php`

**Interfaces:**
- Consumes: everything from Tasks 5–10.
- Produces: `NodeRunner::run(Run $run, Graph $graph, string $nodeId): array` — executes the node for every active subject currently sitting at `$nodeId`, advances each subject's `current_node_id` according to the output it took, writes `node_executions` rows, and returns the deduplicated list of node ids that now hold subjects.

**This is the heart of D7.** A `forSubject()` node is called once per subject; the runner merges the results and performs the partition. A `forAudience()` node is called once per chunk.

- [ ] **Step 1: Add the single-edge-per-output rule to the validator**

Add to `GraphValidator::validate()`, inside the edge loop section:

```php
$seenOutputs = [];

foreach ($graph->edges() as $edge) {
    $key = $edge['from'].':'.$edge['output'];

    if (isset($seenOutputs[$key])) {
        $errors[] = "Node [{$edge['from']}] output [{$edge['output']}] has more than one outgoing edge. ".
            'Use a Split node to send subjects down multiple branches.';
    }

    $seenOutputs[$key] = true;
}
```

Add to `tests/Unit/GraphValidatorTest.php`:

```php
it('rejects two edges from the same output', function () {
    $result = $this->validator->validate(Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'sent', 'to' => 'n3'],
        ],
    ]));

    expect($result->passes())->toBeFalse()
        ->and(implode(' ', $result->errors()))->toContain('more than one outgoing edge');
});
```

A subject occupies exactly one node at a time, so an output fanning to two nodes is ambiguous. Split exists for that.

- [ ] **Step 2: Write the failing test**

`tests/Feature/NodeRunnerTest.php`:

```php
<?php

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Tests\Support\FakeSendNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    app()->bind(SubjectResolver::class, fn () => new class implements SubjectResolver {
        public function resolve(string $subjectType, array $subjectIds): array
        {
            return collect($subjectIds)
                ->mapWithKeys(fn ($id) => [$id => ['id' => $id, 'clicked' => $id === '1']])
                ->all();
        }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'core.condition', 'config' => ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null]],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'yes', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'no', 'to' => 'n3'],
        ],
    ]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => $this->graph->toArray(), 'content_hash' => 'h']);
    $this->run = Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'running']);

    foreach (['1', '2', '3'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(
        \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn ($s) => $s['clicked']),
    );
});

it('partitions subjects across outputs and advances each to its target node', function () {
    $next = app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    expect($next)->toEqualCanonicalizing(['n2', 'n3']);

    $atN2 = RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n2')->pluck('subject_id')->all();
    $atN3 = RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n3')->pluck('subject_id')->all();

    expect($atN2)->toBe(['1'])
        ->and($atN3)->toEqualCanonicalizing(['2', '3']);
});

it('writes one node execution row per output with counts, not per subject', function () {
    app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    $rows = $this->run->nodeExecutions()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('output', 'yes')->subject_count)->toBe(1)
        ->and($rows->firstWhere('output', 'no')->subject_count)->toBe(2);
});

it('completes subjects whose output has no outgoing edge', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.condition', 'config' => ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null]]],
        'edges' => [],
    ]);

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect($next)->toBe([])
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->count())->toBe(3);
});

it('ignores subjects that have exited', function () {
    RunSubject::where('run_id', $this->run->id)
        ->where('subject_id', '2')
        ->update(['status' => 'exited', 'exited_at' => now()]);

    app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    expect(RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n3')->pluck('subject_id')->all())
        ->toBe(['3']);
});
```

The last test is the cohort cancellation semantic (D10) proved at the runner level: an exited subject is simply not there any more.

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/NodeRunnerTest.php`
Expected: FAIL — class `Nodeflow\Execution\NodeRunner` not found.

- [ ] **Step 4: Write NodeRunner**

`src/Execution/NodeRunner.php`:

```php
<?php

namespace Nodeflow\Execution;

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;
use RuntimeException;

class NodeRunner
{
    public function __construct(
        private NodeRegistry $registry,
        private SubjectResolver $subjects,
    ) {}

    /** @return string[] node ids that now hold subjects */
    public function run(Run $run, Graph $graph, string $nodeId): array
    {
        $definition = $graph->node($nodeId);

        if ($definition === null) {
            throw new RuntimeException("Node [{$nodeId}] is not present in the pinned graph.");
        }

        $node = $this->registry->resolve($definition['type']);
        $config = $definition['config'] ?? [];
        $startedAt = microtime(true);

        $results = [];

        $query = RunSubject::where('run_id', $run->id)
            ->where('current_node_id', $nodeId)
            ->where('status', 'active');

        $chunkSize = $node instanceof HandlesAudience
            ? config('nodeflow.limits.audience_chunk', 5000)
            : config('nodeflow.limits.subject_chunk', 500);

        $query->orderBy('id')->chunk($chunkSize, function ($rows) use (&$results, $node, $run, $nodeId, $config) {
            $subjectType = $rows->first()->subject_type;
            $ids = $rows->pluck('subject_id')->map('strval')->all();

            if ($node instanceof HandlesAudience) {
                $results[] = $node->forAudience(
                    new AudienceContext($run, $nodeId, $config, $subjectType, $ids)
                );

                return;
            }

            if (! $node instanceof HandlesSubject) {
                throw new RuntimeException(
                    'Node ['.$node::type().'] implements neither HandlesSubject nor HandlesAudience.'
                );
            }

            $models = $this->subjects->resolve($subjectType, $ids);

            foreach ($ids as $id) {
                $results[] = $node->forSubject(
                    new SubjectContext($run, $nodeId, $config, $id, $models[$id] ?? null)
                );
            }
        });

        $merged = $results === [] ? NodeResult::empty() : NodeResult::merge(...$results);

        return $this->advance($run, $graph, $nodeId, $merged, (int) ((microtime(true) - $startedAt) * 1000));
    }

    private function advance(Run $run, Graph $graph, string $nodeId, NodeResult $result, int $durationMs): array
    {
        $next = [];

        foreach ($result->outputs() as $output => $subjectIds) {
            $targets = $graph->targetsFor($nodeId, $output);
            $target = $targets[0] ?? null;

            $run->nodeExecutions()->create([
                'node_id' => $nodeId,
                'output' => $output,
                'subject_count' => count($subjectIds),
                'duration_ms' => $durationMs,
            ]);

            foreach (array_chunk($subjectIds, 1000) as $chunk) {
                RunSubject::where('run_id', $run->id)
                    ->whereIn('subject_id', $chunk)
                    ->where('status', 'active')
                    ->update($target === null
                        ? ['status' => 'completed', 'current_node_id' => null]
                        : ['current_node_id' => $target]);
            }

            if ($target !== null) {
                $next[] = $target;
            }
        }

        if ($result->failures() !== []) {
            $run->nodeExecutions()->create([
                'node_id' => $nodeId,
                'output' => null,
                'subject_count' => count($result->failures()),
                'duration_ms' => $durationMs,
                'error' => implode('; ', array_slice(array_unique(array_values($result->failures())), 0, 5)),
            ]);

            foreach ($result->failures() as $subjectId => $message) {
                RunSubject::where('run_id', $run->id)
                    ->where('subject_id', (string) $subjectId)
                    ->update(['status' => 'failed', 'last_error' => $message, 'current_node_id' => null]);
            }
        }

        return array_values(array_unique($next));
    }
}
```

Add `'audience_chunk' => 5000` to the `limits` array in `config/nodeflow.php`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/NodeRunnerTest.php tests/Unit/GraphValidatorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src tests
git commit -m "feat: node runner with automatic audience partitioning"
```

---

## Task 12: Interpreter loop, the workflow adapter, and the unified wait

**Files:**
- Create: `src/Execution/InterpreterLoop.php`, `src/Execution/Steps/{RunNodeStep,WaitStep}.php`
- Create: `src/Workflows/FlowInterpreter.php`, `src/Workflows/Activities/{LoadGraphActivity,RunNodeActivity,CompleteRunActivity}.php`
- Create: `src/Execution/SubjectExiter.php`
- Test: `tests/Feature/InterpreterLoopTest.php`, `tests/Feature/SubjectExiterTest.php`

**Interfaces:**
- Consumes: `NodeRunner` (Task 11), `Graph` (Task 9), `WorkflowEngine` (Task 8).
- Produces: `InterpreterLoop::steps(Graph $graph, int $maxSteps): Generator` yielding `WaitStep` (with `duration`) and `RunNodeStep` (with `nodeId`), and receiving back the array of next node ids from each `RunNodeStep`. `SubjectExiter::exit(Run $run, array $subjectIds): void` marks subjects exited and signals the workflow when the run's active count reaches zero.

**Why the loop is a separate class from the workflow:** the workflow body is subject to the engine's determinism guardrails and cannot be unit tested without the engine. `InterpreterLoop` is a pure generator with no I/O, so the control flow — cursor advancement, wait placement, step guard — is testable directly. `FlowInterpreter` is a thin adapter that translates yielded steps into engine calls.

**Known v1 limitation, deliberately accepted:** the loop processes the cursor sequentially, so two branches that both contain waits elapse in sequence rather than concurrently. `GraphValidator` warns at publish time (Task 9). Concurrent branch waits require `all()` over nested generators and are deferred to a later plan.

- [ ] **Step 1: Write the failing test**

`tests/Feature/InterpreterLoopTest.php`:

```php
<?php

use Nodeflow\Execution\InterpreterLoop;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;

function drive(Graph $graph, array $responses, int $maxSteps = 100): array
{
    $loop = (new InterpreterLoop)->steps($graph, $maxSteps);
    $seen = [];

    while ($loop->valid()) {
        $step = $loop->current();
        $seen[] = $step;

        $loop->send($step instanceof RunNodeStep ? array_shift($responses) : null);
    }

    return $seen;
}

it('walks a linear graph node by node', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $steps = drive($graph, [['n2'], []]);

    expect($steps)->toHaveCount(2)
        ->and($steps[0])->toBeInstanceOf(RunNodeStep::class)
        ->and($steps[0]->nodeId)->toBe('n1')
        ->and($steps[1]->nodeId)->toBe('n2');
});

it('emits a wait step before running a wait node', function () {
    $graph = Graph::fromArray([
        'start' => 'w1',
        'nodes' => [['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']]],
        'edges' => [],
    ]);

    $steps = drive($graph, [[]]);

    expect($steps[0])->toBeInstanceOf(WaitStep::class)
        ->and($steps[0]->duration)->toBe('1 day')
        ->and($steps[1])->toBeInstanceOf(RunNodeStep::class)
        ->and($steps[1]->nodeId)->toBe('w1');
});

it('stops when no node holds subjects', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => []]],
        'edges' => [],
    ]);

    expect(drive($graph, [[]]))->toHaveCount(1);
});

it('deduplicates a cursor where two branches converge', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'core.condition', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'yes', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'no', 'to' => 'n2'],
        ],
    ]);

    $steps = drive($graph, [['n2', 'n2'], []]);

    expect($steps)->toHaveCount(2);
});

it('stops at the max step guard', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.send', 'config' => []]],
        'edges' => [],
    ]);

    $steps = drive($graph, array_fill(0, 10, ['n1']), maxSteps: 3);

    expect($steps)->toHaveCount(3);
});
```

`tests/Feature/SubjectExiterTest.php`:

```php
<?php

use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'waiting', 'engine_workflow_id' => 'wf-1',
    ]);

    foreach (['1', '2'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'w1', 'status' => 'active']);
    }
});

it('marks a subject exited without signalling while others remain', function () {
    app(SubjectExiter::class)->exit($this->run, ['1']);

    expect(RunSubject::where('subject_id', '1')->first()->status)->toBe('exited')
        ->and(app(WorkflowEngine::class)->signals())->toBe([]);
});

it('signals the workflow exactly once when the last subject exits', function () {
    app(SubjectExiter::class)->exit($this->run, ['1']);
    app(SubjectExiter::class)->exit($this->run, ['2']);

    $signals = app(WorkflowEngine::class)->signals();

    expect($signals)->toHaveCount(1)
        ->and($signals[0]['id'])->toBe('wf-1')
        ->and($signals[0]['method'])->toBe('audienceEmptied');
});
```

That second test is D10 proved: one signal per wait, never one per subject, so the engine's 5,000 pending-signal ceiling is structurally unreachable.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/InterpreterLoopTest.php tests/Feature/SubjectExiterTest.php`
Expected: FAIL — class `Nodeflow\Execution\InterpreterLoop` not found.

- [ ] **Step 3: Write the steps and the loop**

`src/Execution/Steps/RunNodeStep.php`:

```php
<?php

namespace Nodeflow\Execution\Steps;

class RunNodeStep
{
    public function __construct(public readonly string $nodeId) {}
}
```

`src/Execution/Steps/WaitStep.php`:

```php
<?php

namespace Nodeflow\Execution\Steps;

class WaitStep
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $duration,
    ) {}
}
```

`src/Execution/InterpreterLoop.php`:

```php
<?php

namespace Nodeflow\Execution;

use Generator;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;

class InterpreterLoop
{
    /**
     * Yields WaitStep and RunNodeStep. The caller sends back, for each
     * RunNodeStep, the array of node ids that now hold subjects.
     */
    public function steps(Graph $graph, int $maxSteps): Generator
    {
        $cursor = [$graph->startNodeId()];
        $steps = 0;

        while ($cursor !== [] && $steps < $maxSteps) {
            $next = [];

            foreach ($cursor as $nodeId) {
                $node = $graph->node($nodeId);

                if ($node === null) {
                    continue;
                }

                if (($node['type'] ?? '') === 'core.wait') {
                    yield new WaitStep($nodeId, (string) ($node['config']['duration'] ?? '1 minute'));
                }

                $produced = yield new RunNodeStep($nodeId);

                $next = array_merge($next, $produced ?? []);

                $steps++;

                if ($steps >= $maxSteps) {
                    return;
                }
            }

            $cursor = array_values(array_unique($next));
        }
    }
}
```

- [ ] **Step 4: Write the workflow adapter, activities, and SubjectExiter**

`src/Workflows/Activities/LoadGraphActivity.php`:

```php
<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Models\Run;
use Workflow\Activity;

class LoadGraphActivity extends Activity
{
    public function execute(int $runId): array
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);

        $run->update(['status' => 'running', 'started_at' => now()]);

        return $run->flowVersion->graph;
    }
}
```

`src/Workflows/Activities/RunNodeActivity.php`:

```php
<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Run;
use Workflow\Activity;

class RunNodeActivity extends Activity
{
    public function execute(int $runId, string $nodeId): array
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);

        $run->increment('steps_taken');

        return app(NodeRunner::class)->run($run, Graph::fromArray($run->flowVersion->graph), $nodeId);
    }
}
```

`src/Workflows/Activities/CompleteRunActivity.php`:

```php
<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Models\Run;
use Workflow\Activity;

class CompleteRunActivity extends Activity
{
    public function execute(int $runId): void
    {
        Run::withoutTenancy()->where('id', $runId)
            ->update(['status' => 'completed', 'ended_at' => now()]);
    }
}
```

`src/Workflows/FlowInterpreter.php`:

```php
<?php

namespace Nodeflow\Workflows;

use Nodeflow\Execution\InterpreterLoop;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;
use Nodeflow\Workflows\Activities\CompleteRunActivity;
use Nodeflow\Workflows\Activities\LoadGraphActivity;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Workflow\SignalMethod;
use Workflow\Workflow;

use function Workflow\activity;
use function Workflow\awaitWithTimeout;

/**
 * Control flow only. No DB, no HTTP, no clock reads: the engine's boot-time
 * guardrail scan rejects those in workflow code, and replay determinism depends
 * on it. Everything with a side effect lives in an activity.
 */
class FlowInterpreter extends Workflow
{
    private bool $audienceEmpty = false;

    #[SignalMethod]
    public function audienceEmptied(): void
    {
        $this->audienceEmpty = true;
    }

    public function execute(int $runId, int $maxSteps = 1000)
    {
        $graph = Graph::fromArray(yield activity(LoadGraphActivity::class, $runId));

        $loop = (new InterpreterLoop)->steps($graph, $maxSteps);
        $send = null;

        while ($loop->valid()) {
            $step = $loop->current();

            if ($step instanceof WaitStep) {
                $this->audienceEmpty = false;

                yield awaitWithTimeout($step->duration, fn () => $this->audienceEmpty);

                $send = null;
            } elseif ($step instanceof RunNodeStep) {
                $send = yield activity(RunNodeActivity::class, $runId, $step->nodeId);
            }

            $loop->send($send);
        }

        yield activity(CompleteRunActivity::class, $runId);
    }
}
```

`src/Execution/SubjectExiter.php`:

```php
<?php

namespace Nodeflow\Execution;

use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

class SubjectExiter
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * Remove subjects from a live run. This is how cancellation works in a
     * cohort: the subject is gone by the time the next node runs. One signal is
     * sent, and only when the run's last active subject leaves.
     */
    public function exit(Run $run, array $subjectIds): void
    {
        RunSubject::where('run_id', $run->id)
            ->whereIn('subject_id', array_map('strval', $subjectIds))
            ->where('status', 'active')
            ->update(['status' => 'exited', 'exited_at' => now(), 'current_node_id' => null]);

        if ($run->activeSubjectCount() === 0 && $run->engine_workflow_id !== null) {
            $this->engine->signal($run->engine_workflow_id, 'audienceEmptied');
        }
    }
}
```

**Before moving on:** confirm `Workflow\Activity`, `Workflow\Workflow`, `Workflow\SignalMethod`, `Workflow\activity()`, and `Workflow\awaitWithTimeout()` are the correct symbols for the installed release candidate. The docs show `use function Workflow\awaitWithTimeout;` and `use Workflow\SignalMethod;`, while the determinism guardrails reference `Workflow\V2\Workflow::now()`. Read `vendor/durable-workflow/workflow/src/` and adjust `FlowInterpreter` and the three activity classes if the v2 namespace differs. These four files plus `DurableWorkflowEngine` are the entire engine surface.

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/InterpreterLoopTest.php tests/Feature/SubjectExiterTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add src tests
git commit -m "feat: interpreter loop, workflow adapter, and unified wait primitive"
```

---

## Task 13: Publishing — validate and freeze an immutable version

**Files:**
- Create: `src/Publishing/PublishFlow.php`, `src/Publishing/GraphInvalidException.php`
- Test: `tests/Feature/PublishFlowTest.php`

**Interfaces:**
- Consumes: `GraphValidator` (Task 9), models (Task 2).
- Produces: `PublishFlow::publish(Flow $flow, array $graph, ?string $publishedBy = null): FlowVersion`. Throws `GraphInvalidException` carrying `->errors()`. Increments the version number, computes a content hash, sets `published_at`, and repoints `flow.current_version_id`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PublishFlowTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    $this->validGraph = [
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ];
});

it('freezes version 1 and points the flow at it', function () {
    $version = app(PublishFlow::class)->publish($this->flow, $this->validGraph, 'user-9');

    expect($version->version)->toBe(1)
        ->and($version->published_at)->not->toBeNull()
        ->and($version->published_by)->toBe('user-9')
        ->and($this->flow->fresh()->current_version_id)->toBe($version->id);
});

it('increments the version on each publish and leaves earlier versions untouched', function () {
    $first = app(PublishFlow::class)->publish($this->flow, $this->validGraph);
    $second = app(PublishFlow::class)->publish($this->flow, $this->validGraph);

    expect($second->version)->toBe(2)
        ->and($first->fresh()->graph)->toBe($this->validGraph);
});

it('refuses to publish an invalid graph', function () {
    $invalid = $this->validGraph;
    $invalid['nodes'][0]['config'] = ['channel' => 'pigeon'];

    expect(fn () => app(PublishFlow::class)->publish($this->flow, $invalid))
        ->toThrow(GraphInvalidException::class);
});

it('leaves runs on the previous version untouched when a new one is published', function () {
    $v1 = app(PublishFlow::class)->publish($this->flow, $this->validGraph);

    $run = Run::create([
        'flow_version_id' => $v1->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    app(PublishFlow::class)->publish($this->flow, $this->validGraph);

    expect($run->fresh()->flow_version_id)->toBe($v1->id)
        ->and($v1->fresh()->hasLiveRuns())->toBeTrue();
});
```

The final test is requirement 7 proved directly: publishing cannot disturb an in-flight run.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/PublishFlowTest.php`
Expected: FAIL — class `Nodeflow\Publishing\PublishFlow` not found.

- [ ] **Step 3: Write the implementation**

`src/Publishing/GraphInvalidException.php`:

```php
<?php

namespace Nodeflow\Publishing;

use RuntimeException;

class GraphInvalidException extends RuntimeException
{
    public function __construct(private array $errors)
    {
        parent::__construct('The flow could not be published: '.implode(' ', $errors));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
```

`src/Publishing/PublishFlow.php`:

```php
<?php

namespace Nodeflow\Publishing;

use Illuminate\Support\Facades\DB;
use Nodeflow\Graph\Graph;
use Nodeflow\Graph\GraphValidator;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;

class PublishFlow
{
    public function __construct(private GraphValidator $validator) {}

    public function publish(Flow $flow, array $graph, ?string $publishedBy = null): FlowVersion
    {
        $result = $this->validator->validate(Graph::fromArray($graph));

        if (! $result->passes()) {
            throw new GraphInvalidException($result->errors());
        }

        return DB::transaction(function () use ($flow, $graph, $publishedBy) {
            $version = FlowVersion::create([
                'flow_id' => $flow->id,
                'version' => ((int) $flow->versions()->max('version')) + 1,
                'graph' => $graph,
                'content_hash' => hash('sha256', json_encode($graph)),
                'published_at' => now(),
                'published_by' => $publishedBy,
            ]);

            $flow->update(['current_version_id' => $version->id, 'status' => 'active']);

            return $version;
        });
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/PublishFlowTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Publishing tests/Feature/PublishFlowTest.php
git commit -m "feat: publish flow with validation and immutable version freeze"
```

---

## Task 14: Triggers and run start

**Files:**
- Create: `src/Triggers/{Trigger,TriggerRegistry,TriggerMatch,EventTriggerListener,SubFlowStarter}.php`, `src/Schema/TriggerDefinition.php`, `src/Execution/StartRun.php`, `src/Nodes/Core/StartFlowNode.php`
- Modify: `src/NodeflowServiceProvider.php` (bind listeners on boot)
- Test: `tests/Feature/StartRunTest.php`, `tests/Feature/EventTriggerTest.php`

**Interfaces:**
- Consumes: `AudienceMaterialiser` (Task 7), `WorkflowEngine` (Task 8), models.
- Produces: `StartRun::forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run` — creates the run against `flow.current_version_id`, materialises the audience at the start node, starts the workflow, stores `engine_workflow_id`. Options: `is_test`, `correlation_id`, `idempotency_key`, `strategy`.
- `Trigger` abstract: `static event(): string`, `definition(): TriggerDefinition`, `resolve(object $event): TriggerMatch`. `TriggerMatch::forTenant(string $tenantId, string $subjectType, iterable $ids)` and `->tenants(): array`.
- `SubFlowStarter::start(Run $parentRun, int $flowId, string $subjectType, array $subjectIds): ?Run` — enforces a depth limit of 5 via `correlation_id` lineage.

- [ ] **Step 1: Write the failing test**

`tests/Feature/StartRunTest.php`:

```php
<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Workflows\FlowInterpreter;
use Tests\Support\FakeSendNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return $i !== '666'; }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($this->flow, [
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);
});

it('creates a run pinned to the current version with subjects at the start node', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);

    expect($run->flow_version_id)->toBe($this->flow->fresh()->current_version_id)
        ->and($run->subjects()->count())->toBe(2)
        ->and($run->subjects()->first()->current_node_id)->toBe('n1')
        ->and($run->engine_workflow_id)->not->toBeNull();
});

it('starts the interpreter workflow with the run id', function () {
    $run = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1']);

    $started = app(WorkflowEngine::class)->started();

    expect($started[0]['workflow'])->toBe(FlowInterpreter::class)
        ->and($started[0]['args']['run_id'])->toBe($run->id);
});

it('marks a per-user run as the subject strategy automatically', function () {
    $cohort = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '2']);
    $single = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['3']);

    expect($cohort->strategy)->toBe('cohort')
        ->and($single->strategy)->toBe('subject');
});

it('refuses to start when a subject fails the tenant check and creates no run subjects', function () {
    expect(fn () => app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1', '666']))
        ->toThrow(Nodeflow\Execution\CrossTenantSubjectException::class);

    expect(Nodeflow\Models\RunSubject::count())->toBe(0);
});

it('is idempotent for a repeated trigger identity', function () {
    $first = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);
    $second = app(StartRun::class)->forFlow($this->flow->fresh(), 'user', ['1'], ['idempotency_key' => 'alert-218']);

    expect($second->id)->toBe($first->id)
        ->and(Nodeflow\Models\Run::count())->toBe(1);
});
```

`tests/Feature/EventTriggerTest.php`:

```php
<?php

use Illuminate\Support\Facades\Event;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerRegistry;
use Tests\Support\FakeSendNode;

class FakeAlertEvent
{
    public function __construct(public array $userIds) {}
}

class FakeAlertTrigger extends Trigger
{
    public static function type(): string
    {
        return 'test.alert';
    }

    public static function event(): string
    {
        return FakeAlertEvent::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Fake Alert');
    }

    public function resolve(object $event): TriggerMatch
    {
        return TriggerMatch::make()->forTenant('org-1', 'user', $event->userIds);
    }
}

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return null; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Nodeflow::register([FakeSendNode::class]);
    app(TriggerRegistry::class)->register(FakeAlertTrigger::class);

    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'test.alert', 'status' => 'draft']);

    app(PublishFlow::class)->publish($flow, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);
});

it('starts a run for each active flow matching the fired event', function () {
    app(Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1', '2']));

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->first()->subjects()->count())->toBe(2);
});

it('ignores flows that are not active', function () {
    Flow::withoutTenancy()->update(['status' => 'paused']);

    app(Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1']));

    expect(Run::withoutTenancy()->count())->toBe(0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/StartRunTest.php tests/Feature/EventTriggerTest.php`
Expected: FAIL — class `Nodeflow\Execution\StartRun` not found.

- [ ] **Step 3: Write StartRun**

`src/Execution/StartRun.php`:

```php
<?php

namespace Nodeflow\Execution;

use Illuminate\Support\Facades\DB;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\FlowInterpreter;
use RuntimeException;

class StartRun
{
    public function __construct(
        private AudienceMaterialiser $materialiser,
        private WorkflowEngine $engine,
    ) {}

    public function forFlow(Flow $flow, string $subjectType, iterable $subjectIds, array $options = []): Run
    {
        if ($flow->current_version_id === null) {
            throw new RuntimeException("Flow [{$flow->id}] has no published version.");
        }

        $key = $options['idempotency_key'] ?? null;

        if ($key !== null) {
            $existing = Run::withoutTenancy()
                ->where('flow_version_id', $flow->current_version_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $ids = is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds);

        $version = $flow->currentVersion()->firstOrFail();
        $startNodeId = Graph::fromArray($version->graph)->startNodeId();

        $run = DB::transaction(function () use ($flow, $version, $options, $key, $subjectType, $ids, $startNodeId) {
            $run = Run::create([
                'flow_version_id' => $version->id,
                'tenant_id' => $flow->tenant_id,
                'correlation_id' => $options['correlation_id'] ?? null,
                'strategy' => $options['strategy'] ?? (count($ids) === 1 ? 'subject' : 'cohort'),
                'status' => 'pending',
                'is_test' => (bool) ($options['is_test'] ?? false),
                'idempotency_key' => $key,
            ]);

            $this->materialiser->materialise($run, $subjectType, $ids, $startNodeId);

            return $run;
        });

        $workflowId = $this->engine->start(FlowInterpreter::class, [
            'run_id' => $run->id,
            'max_steps' => (int) config('nodeflow.limits.max_steps_per_run', 1000),
        ]);

        $run->update(['engine_workflow_id' => $workflowId]);

        return $run->fresh();
    }
}
```

The transaction wraps run creation and materialisation together, so the cross-tenant test leaves neither a run nor subjects behind.

- [ ] **Step 4: Write the trigger classes**

`src/Schema/TriggerDefinition.php`:

```php
<?php

namespace Nodeflow\Schema;

class TriggerDefinition
{
    private ?string $description = null;

    /** @var Field[] */
    private array $fields = [];

    private function __construct(public readonly string $label) {}

    public static function make(string $label): self
    {
        return new self($label);
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /** @param  Field[]  $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'description' => $this->description,
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->fields),
        ];
    }

    public function rules(): array
    {
        return array_merge(...array_map(fn (Field $f) => $f->rules(), $this->fields)) ?: [];
    }
}
```

`src/Triggers/TriggerMatch.php`:

```php
<?php

namespace Nodeflow\Triggers;

class TriggerMatch
{
    private array $tenants = [];

    public static function make(): self
    {
        return new self;
    }

    public function forTenant(string $tenantId, string $subjectType, iterable $subjectIds): self
    {
        $this->tenants[$tenantId] = [
            'subject_type' => $subjectType,
            'subject_ids' => is_array($subjectIds) ? $subjectIds : iterator_to_array($subjectIds),
        ];

        return $this;
    }

    /** @return array<string, array{subject_type: string, subject_ids: array}> */
    public function tenants(): array
    {
        return $this->tenants;
    }
}
```

`src/Triggers/Trigger.php`:

```php
<?php

namespace Nodeflow\Triggers;

use Nodeflow\Schema\TriggerDefinition;

abstract class Trigger
{
    abstract public static function type(): string;

    /** The host event class this trigger listens to. */
    abstract public static function event(): string;

    abstract public function definition(): TriggerDefinition;

    abstract public function resolve(object $event): TriggerMatch;

    /**
     * A stable identity for one firing, used for run idempotency. Override when
     * the event carries a natural id, e.g. "alert-218".
     */
    public function idempotencyKey(object $event): ?string
    {
        return null;
    }

    /** Does this flow's trigger config match this event? */
    public function matchesConfig(object $event, array $config): bool
    {
        return true;
    }
}
```

`src/Triggers/TriggerRegistry.php`:

```php
<?php

namespace Nodeflow\Triggers;

use RuntimeException;

class TriggerRegistry
{
    /** @var array<string, class-string<Trigger>> */
    private array $types = [];

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->types[$class::type()] = $class;
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function resolve(string $type): Trigger
    {
        if (! isset($this->types[$type])) {
            throw new RuntimeException("Unknown nodeflow trigger type [{$type}].");
        }

        return app($this->types[$type]);
    }

    /** @return array<string, class-string<Trigger>> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return Trigger[] */
    public function forEvent(string $eventClass): array
    {
        return array_values(array_map(
            fn (string $class) => app($class),
            array_filter($this->types, fn (string $class) => $class::event() === $eventClass),
        ));
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type) {
            return array_merge(app($class)->definition()->toArray(), ['type' => $type]);
        }, $this->types, array_keys($this->types)));
    }
}
```

Register it as a singleton in the service provider, alongside `NodeRegistry`.

`src/Triggers/EventTriggerListener.php`:

```php
<?php

namespace Nodeflow\Triggers;

use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;

class EventTriggerListener
{
    public function __construct(
        private TriggerRegistry $triggers,
        private StartRun $startRun,
    ) {}

    public function handle(object $event): void
    {
        foreach ($this->triggers->forEvent($event::class) as $trigger) {
            $match = $trigger->resolve($event);

            foreach ($match->tenants() as $tenantId => $audience) {
                $flows = Flow::withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->where('trigger_type', $trigger::type())
                    ->where('status', 'active')
                    ->whereNotNull('current_version_id')
                    ->get();

                foreach ($flows as $flow) {
                    if (! $trigger->matchesConfig($event, $flow->trigger_config ?? [])) {
                        continue;
                    }

                    $this->startRun->forFlow(
                        $flow,
                        $audience['subject_type'],
                        $audience['subject_ids'],
                        ['idempotency_key' => $trigger->idempotencyKey($event)],
                    );
                }
            }
        }
    }
}
```

`src/Triggers/SubFlowStarter.php`:

```php
<?php

namespace Nodeflow\Triggers;

use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

class SubFlowStarter
{
    public const MAX_DEPTH = 5;

    public function __construct(private StartRun $startRun) {}

    public function start(Run $parentRun, int $flowId, string $subjectType, array $subjectIds): ?Run
    {
        $lineage = array_filter(explode('>', (string) $parentRun->correlation_id));

        if (count($lineage) >= self::MAX_DEPTH) {
            return null;
        }

        $flow = Flow::withoutTenancy()
            ->where('id', $flowId)
            ->where('tenant_id', $parentRun->tenant_id)
            ->firstOrFail();

        return $this->startRun->forFlow($flow, $subjectType, $subjectIds, [
            'correlation_id' => trim(($parentRun->correlation_id ?? '').'>'.$parentRun->id, '>'),
            'is_test' => $parentRun->is_test,
        ]);
    }
}
```

The `correlation_id` doubles as the lineage chain (`12>48>91`), so its length is the depth and no extra column is needed. **The tenant lookup on `$flowId` is scoped to the parent run's tenant** — a graph referencing another tenant's flow id cannot start it.

`src/Nodes/Core/StartFlowNode.php` — the fifth core node, deferred from Task 10 because it
depends on `SubFlowStarter`:

```php
<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Triggers\SubFlowStarter;

class StartFlowNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.start_flow';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Start Another Flow')
            ->group('Flow')
            ->outputs(['default'])
            ->fields([
                Field::select('flow_id')->label('Flow to start')->required(),
                Field::boolean('exit_this_flow')->label('Leave this flow afterwards')->default(true),
            ]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        app(SubFlowStarter::class)->start(
            parentRun: $context->run(),
            flowId: (int) $context->config('flow_id'),
            subjectType: $context->subjectType(),
            subjectIds: $context->subjectIds(),
        );

        return $context->config('exit_this_flow', true)
            ? NodeResult::empty()
            : $context->all('default');
    }
}
```

`NodeResult::empty()` when the subject leaves: no output means no onward edge, so
`NodeRunner` marks those subjects completed. Register this node with the other four.

In `NodeflowServiceProvider::boot()`, wire the listeners:

```php
foreach (app(TriggerRegistry::class)->all() as $triggerClass) {
    Event::listen($triggerClass::event(), fn ($event) => app(EventTriggerListener::class)->handle($event));
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/StartRunTest.php tests/Feature/EventTriggerTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add src tests
git commit -m "feat: triggers, run start, and sub-flow chaining"
```

---

## Task 15: Test mode and node type resolution guards

**Files:**
- Create: `src/Console/CheckNodeTypesCommand.php`
- Modify: `src/NodeflowServiceProvider.php` (boot-time resolution check)
- Test: `tests/Feature/TestModeTest.php`, `tests/Feature/NodeTypeResolutionTest.php`

**Interfaces:**
- Consumes: `StartRun` (Task 14), `NodeRegistry` (Task 5).
- Produces: `StartRun::forFlow($flow, $type, $ids, ['is_test' => true])` propagating `is_test` to every `SubjectContext`/`AudienceContext`. Artisan command `nodeflow:check-node-types` exiting non-zero when any version with live runs references an unregistered type.

- [ ] **Step 1: Write the failing test**

`tests/Feature/TestModeTest.php`:

```php
<?php

use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\StartRun;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;

it('propagates test mode into the node context so nodes can suppress side effects', function () {
    Nodeflow::register([\Tests\Support\RecordingSendNode::class]);

    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    $graph = [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]],
        'edges' => [],
    ];

    app(PublishFlow::class)->publish($flow, $graph);

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1'], ['is_test' => true]);

    app(NodeRunner::class)->run($run, Graph::fromArray($graph), 'n1');

    expect(\Tests\Support\RecordingSendNode::$sent)->toBe([])
        ->and(\Tests\Support\RecordingSendNode::$wouldHaveSent)->toBe(['1']);
});
```

`tests/Support/RecordingSendNode.php`:

```php
<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class RecordingSendNode extends Node implements HandlesSubject
{
    public static array $sent = [];

    public static array $wouldHaveSent = [];

    public static function type(): string
    {
        return 'test.recording';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Recording Send')->outputs(['sent']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            static::$wouldHaveSent[] = $context->subjectId();
        } else {
            static::$sent[] = $context->subjectId();
        }

        return $context->continue('sent');
    }
}
```

`tests/Feature/NodeTypeResolutionTest.php`:

```php
<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;

it('reports a version with live runs referencing an unregistered type', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'gone.away', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    $this->artisan('nodeflow:check-node-types')
        ->expectsOutputToContain('gone.away')
        ->assertExitCode(1);
});

it('passes when every referenced type resolves', function () {
    $this->artisan('nodeflow:check-node-types')->assertExitCode(0);
});

it('resolves a renamed type through the alias map', function () {
    app(NodeRegistry::class)->register(Tests\Support\RecordingSendNode::class);
    app(NodeRegistry::class)->alias('test.old_recording', 'test.recording');

    expect(app(NodeRegistry::class)->resolve('test.old_recording'))
        ->toBeInstanceOf(Tests\Support\RecordingSendNode::class);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/TestModeTest.php tests/Feature/NodeTypeResolutionTest.php`
Expected: FAIL — command `nodeflow:check-node-types` not found.

- [ ] **Step 3: Write the command**

`src/Console/CheckNodeTypesCommand.php`:

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Nodes\NodeRegistry;

class CheckNodeTypesCommand extends Command
{
    protected $signature = 'nodeflow:check-node-types';

    protected $description = 'Verify every node type referenced by a flow version with live runs still resolves.';

    public function handle(NodeRegistry $registry): int
    {
        $missing = [];

        FlowVersion::query()->with('flow')->chunk(100, function ($versions) use ($registry, &$missing) {
            foreach ($versions as $version) {
                if (! $version->hasLiveRuns()) {
                    continue;
                }

                foreach (Graph::fromArray($version->graph)->nodeIds() as $nodeId) {
                    $type = Graph::fromArray($version->graph)->node($nodeId)['type'] ?? '';

                    if (! $registry->has($type)) {
                        $missing[] = "version {$version->id} node {$nodeId} type {$type}";
                    }
                }
            }
        });

        if ($missing !== []) {
            foreach ($missing as $line) {
                $this->error("Unresolvable node type: {$line}");
            }

            $this->line('Re-register the node class, or add an alias with NodeRegistry::alias().');

            return self::FAILURE;
        }

        $this->info('All node types referenced by live runs resolve.');

        return self::SUCCESS;
    }
}
```

Register the command in the service provider's console block. Also add, in `boot()` when `runningInConsole()` is false, a lightweight version of the same check gated behind `config('nodeflow.check_node_types_on_boot', false)` so production can opt in without paying the query on every request.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/TestModeTest.php tests/Feature/NodeTypeResolutionTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src tests
git commit -m "feat: test mode propagation and node type resolution guards"
```

---

## Task 16: Retention, and the architecture guard

**Files:**
- Create: `src/Console/PruneCommand.php`
- Test: `tests/Feature/PruneCommandTest.php`, `tests/Unit/ArchitectureTest.php`

**Interfaces:**
- Consumes: models, config.
- Produces: `nodeflow:prune {--days=} {--dry-run}` deleting terminal runs older than the retention window along with their subjects and node executions.

**The engine ships no end-to-end durable prune command** — archive and prune are separate concerns there, and the durable tables (`workflow_instances`, `workflow_runs`, `workflow_history_events`, `workflow_tasks`, `activity_executions`, `activity_attempts`) need their own job in dependency order. This task covers the package's own tables; the engine's tables get a follow-up task in the Portia integration plan, where the production retention window is known.

- [ ] **Step 1: Write the failing test**

`tests/Feature/PruneCommandTest.php`:

```php
<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\NodeExecution;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $this->version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
});

function makeRun($version, string $status, int $daysAgo): Run
{
    $run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => $status,
    ]);

    $run->forceFill(['ended_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo)])->save();

    RunSubject::create(['run_id' => $run->id, 'subject_type' => 'user', 'subject_id' => '1', 'status' => 'completed']);
    NodeExecution::create(['run_id' => $run->id, 'node_id' => 'n1', 'output' => 'default', 'subject_count' => 1]);

    return $run;
}

it('deletes terminal runs past the window with their subjects and executions', function () {
    makeRun($this->version, 'completed', 120);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(RunSubject::count())->toBe(0)
        ->and(NodeExecution::count())->toBe(0);
});

it('never deletes a live run regardless of age', function () {
    makeRun($this->version, 'waiting', 400);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('keeps runs inside the window', function () {
    makeRun($this->version, 'completed', 10);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('reports without deleting on a dry run', function () {
    makeRun($this->version, 'completed', 120);

    $this->artisan('nodeflow:prune', ['--days' => 90, '--dry-run' => true])
        ->expectsOutputToContain('1')
        ->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});
```

The second test is the one that matters operationally: a run mid-24-hour wait for a year must never be pruned by age.

`tests/Unit/ArchitectureTest.php`:

```php
<?php

it('confines the engine dependency to src/Engine and src/Workflows', function () {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        if (str_contains($path, '/src/Engine/') || str_contains($path, '/src/Workflows/')) {
            continue;
        }

        if (preg_match('/\buse\s+(function\s+)?Workflow\\\\/', file_get_contents($file->getPathname()))) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
```

That test is the enforcement mechanism for the constraint that makes an engine upgrade cheap. Without it the boundary erodes silently.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/PruneCommandTest.php tests/Unit/ArchitectureTest.php`
Expected: FAIL — command `nodeflow:prune` not found.

- [ ] **Step 3: Write the command**

`src/Console/PruneCommand.php`:

```php
<?php

namespace Nodeflow\Console;

use Illuminate\Console\Command;
use Nodeflow\Models\Run;

class PruneCommand extends Command
{
    protected $signature = 'nodeflow:prune {--days= : Retention window} {--dry-run}';

    protected $description = 'Delete terminal nodeflow runs, subjects, and node executions past the retention window.';

    private const TERMINAL = ['completed', 'failed', 'cancelled'];

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('nodeflow.retention.runs_days', 90));
        $cutoff = now()->subDays($days);

        $query = Run::withoutTenancy()
            ->whereIn('status', self::TERMINAL)
            ->where('created_at', '<', $cutoff);

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} runs older than {$days} days.");

            return self::SUCCESS;
        }

        $query->chunkById(500, function ($runs) {
            foreach ($runs as $run) {
                $run->delete();
            }
        });

        $this->info("Deleted {$count} runs older than {$days} days.");

        return self::SUCCESS;
    }
}
```

Subjects and node executions go with the run through the migration's `cascadeOnDelete`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest`
Expected: PASS, full suite.

- [ ] **Step 5: Commit**

```bash
git add src/Console tests
git commit -m "feat: retention pruning and engine boundary architecture test"
```

---

## Definition of done for this plan

Running `./vendor/bin/pest` is green, and this scenario works headlessly with no UI:

1. Register a node, a trigger, a tenancy resolver, and a subject resolver in a host app.
2. Publish a graph: send → wait 5 minutes → condition → send.
3. Fire the host event.
4. A run exists, pinned to that version, with subjects materialised at the start node.
5. Publish a second version. The in-flight run still executes version 1.
6. Exit half the audience mid-wait. Those subjects do not receive the follow-up.
7. `nodeflow:check-node-types` and `nodeflow:prune --dry-run` both exit 0.

Not in this plan, by design: the React editor and its Inertia controllers (plan 2); Rada/Yaya domain nodes, templates UX, and engine-table pruning (plan 3).

## Self-review notes

**Spec coverage.** §4 boundaries → Tasks 1, 3, 6, 7. §5 node contract → Tasks 4, 5, 6, 10, 11. §6 storage and versioning → Tasks 2, 13. §7 interpretation and the unified wait → Tasks 9, 11, 12. §8 triggers → Task 14. §9 tenancy → Tasks 3, 7, and the scoped lookup in `SubFlowStarter`. §10 config schema → Tasks 4, 5 (the `palette()` output is what plan 2 consumes). §11 editor → **plan 2, not here.** §13 templates → **plan 3.** §14 risks → Task 8 facade plus the Task 16 architecture test; node-class disappearance → Task 15. §15 test mode → Task 15; prune → Task 16.

**Known gap carried forward deliberately:** concurrent waits on parallel branches are sequential in v1. `GraphValidator` warns at publish (Task 9) and the limitation is stated in Task 12.

**Verification steps that cannot be pre-written:** Tasks 8 and 12 both instruct the implementer to read `vendor/durable-workflow/workflow/src/` and confirm the real symbol names before proceeding. The package is at `2.0.0-rc.32` and the v2 namespace may differ from the documented examples. This is called out rather than guessed, and the blast radius is five files.
