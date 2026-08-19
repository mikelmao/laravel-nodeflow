# 2. Integration

Four steps: install, implement two contracts, register your domain surface, run workers.

## Step 1 — Install

```bash
composer require atram/laravel-nodeflow
php artisan vendor:publish --tag=nodeflow-migrations
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag=nodeflow-config
```

Requirements: PHP `^8.3`, Laravel `^12.0|^13.0`, and a queue driver other than `sync` — the engine
needs real queued jobs to hibernate and resume. It also needs a cache driver supporting atomic locks.

Six tables are created, all prefixed `nodeflow_`: `flows`, `flow_versions`, `runs`, `run_subjects`,
`node_executions`, `templates`.

## Step 2 — Implement the two required contracts

The package ships defaults for both, and **both defaults deliberately fail closed.** If you skip this
step, audiences come back empty and subject resolution throws. That is intentional: a misconfigured
install that silently sends nothing to nobody is far worse than one that fails loudly.

### `TenantResolver`

```php
namespace App\Nodeflow;

use App\Models\Organization;
use App\Models\User;
use Nodeflow\Contracts\TenantResolver;

class OrganizationTenantResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        return auth()->user()?->organization_id
            ? (string) auth()->user()->organization_id
            : null;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        if ($subjectType !== 'user') {
            return false;
        }

        return User::where('id', $subjectId)
            ->where('organization_id', $tenantId)
            ->exists();
    }
}
```

`currentTenantId()` returns the ambient tenant, or `null` in a console or queue context where there
isn't one. It is called on every scoped query, so keep it cheap — cache it per request.

`ownsSubject()` is the **mandatory audience check**. It is called for every subject before a single
audience row is written, and if it returns false the whole materialisation aborts with nothing
written. This is the control that stops one customer's people receiving another customer's messages.
Never implement it as `return true`.

> **Performance note.** `ownsSubject()` is currently called once per subject. At six-figure audiences
> that is a lot of round trips. Implement it against an indexed column. A set-shaped variant of this
> contract is a known follow-up.

### `SubjectResolver`

```php
namespace App\Nodeflow;

use App\Models\User;
use Nodeflow\Contracts\SubjectResolver;

class UserSubjectResolver implements SubjectResolver
{
    public function resolve(string $subjectType, array $subjectIds): array
    {
        if ($subjectType !== 'user') {
            return [];
        }

        return User::whereIn('id', $subjectIds)
            ->get()
            ->keyBy(fn (User $user) => (string) $user->getKey())
            ->all();
    }
}
```

It receives a chunk of ids and must return a map of `subjectId => model`. **Key by string** — the
package passes string ids throughout, and an integer-keyed array will silently miss lookups.

Called once per chunk, not once per subject, so eager-load whatever your nodes will touch.

### Bind them

```php
// app/Providers/AppServiceProvider.php
public function register(): void
{
    $this->app->bind(
        \Nodeflow\Contracts\TenantResolver::class,
        \App\Nodeflow\OrganizationTenantResolver::class,
    );

    $this->app->bind(
        \Nodeflow\Contracts\SubjectResolver::class,
        \App\Nodeflow\UserSubjectResolver::class,
    );
}
```

The package uses `bindIf` for its own defaults, so your binding wins regardless of provider order.

## Step 3 — Register your domain surface

In a service provider's `boot()`:

```php
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\TriggerRegistry;

public function boot(): void
{
    // Nodes — the things that do work.
    Nodeflow::register([
        \App\Nodeflow\Nodes\SendMessage::class,
        \App\Nodeflow\Nodes\CheckEligibility::class,
    ]);

    // Triggers — which of your events start journeys.
    app(TriggerRegistry::class)->register(
        \App\Nodeflow\Triggers\OrderPlaced::class,
    );

    // Subject attributes — what a non-technical author may build conditions on.
    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Has clicked', 'boolean',
            fn ($subject) => $subject->clicked_at !== null),
        SubjectAttribute::make('plan', 'Plan', 'text',
            fn ($subject) => $subject->plan),
    );
}
```

Three things worth knowing:

**Trigger registration attaches the event listener.** `TriggerRegistry::register()` wires
`Event::listen()` at the moment you register, one listener per distinct event class. Registering in
`boot()` is fine; registering lazily somewhere that may never execute means the trigger never fires.

**Subject attributes are the alternative to an expression language.** A `core.condition` node can only
reference attributes you register. That is a deliberate constraint — it keeps a customer-authored
condition from reaching arbitrary data, and it means every option in the editor's dropdown is one you
chose to expose. The `type` (`boolean`, `text`, `number`) drives how comparisons coerce; get it right.

**The registries are singletons**, so registration order across providers doesn't matter, but a
`SubjectAttribute` referenced by a published graph must be registered before a run reaches that node.

## Step 4 — Run queue workers

```bash
php artisan queue:work
```

Or Horizon. Without a worker, runs are created and then sit there: the interpreter is a queued
workflow, and every node body executes as a queued activity.

## Verifying the install

```bash
php artisan nodeflow:check-node-types
```

Exits 0 when every node type referenced by a flow version with live runs still resolves. Useful as a
deploy gate — see [Operations](06-operations.md).

There is no `nodeflow:install` command and no scaffolding generator. Four explicit steps beat a
generator whose output you then have to understand.

## What you have not wired yet

There is **no UI**. Nothing in this package renders anything. Flows are created and published
programmatically:

```php
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

$flow = Flow::create([
    'name' => 'Welcome journey',
    'trigger_type' => 'app.order_placed',
    'status' => 'draft',
]);

app(PublishFlow::class)->publish($flow, [
    'start' => 'n1',
    'nodes' => [
        ['id' => 'n1', 'type' => 'app.send_message', 'config' => ['template' => 'welcome']],
        ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
    ],
    'edges' => [
        ['from' => 'n1', 'output' => 'sent', 'to' => 'n2'],
    ],
]);
```

`Flow::create()` stamps `tenant_id` from your resolver automatically. `publish()` validates the graph
and throws `Nodeflow\Publishing\GraphInvalidException` (carrying `errors()`) if it fails — see
[Execution model](05-execution-model.md#publish-time-validation) for what is checked.

The graph JSON shape above is the contract a future editor will produce. A node may carry a `position`
key for canvas coordinates; the package round-trips it untouched and ignores it.
