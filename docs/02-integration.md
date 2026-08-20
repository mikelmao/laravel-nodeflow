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

The second argument is the `Flow` or `Run` in question, and it is absent for the
list case (`viewAny`) — so a gate reused for both should default it:
`fn ($user, $flow = null) => ...`.

**Type the first argument if a gate must be evaluated for guests.** Laravel only
invokes a gate closure for an unauthenticated request when its first parameter
accepts `null` — declared `?Authenticatable $user` or defaulted to `$user = null`.
Leave it as a plain `$user`, as in the examples above, and Laravel skips the
closure entirely for a guest and returns a deny without your logic ever running.
That deny looks identical to a real one, so you end up debugging your
authorization rule when the actual bug is the signature. The examples above are
fine as written *because* these four abilities are meant to require a logged-in
user — a guest denied without the closure running is the outcome you want here.
Only make the first argument nullable when you deliberately want the closure
itself to decide the guest case, such as a public read.

Tenant isolation is **not** your gate's job: the models are already scoped, so a
cross-tenant id is a 404 before any gate runs. Gates answer "may this person do
this", not "is this row theirs".

A host needing more than a gate can override the package's policy entirely by
binding its own with `Gate::policy(Flow::class, YourPolicy::class)` in a
provider that boots after this one.

## Verifying the install

```bash
php artisan nodeflow:check-node-types
```

Exits 0 when every node type referenced by a flow version with live runs still resolves. Useful as a
deploy gate — see [Operations](06-operations.md).

There is no `nodeflow:install` command. Nothing scaffolds the two contracts, the bindings or a
`NodeflowServiceProvider` for you: the four steps above are the install, and four explicit steps beat
a generator whose output you then have to understand.

The one thing that *is* generated is the file you write over and over — a node class:

```bash
php artisan nodeflow:make-node SendSms --type=yaya.send_sms --outputs='sent, failed' --test
```

That writes a single class (optionally with a test), and appends it to
`app/Providers/NodeflowServiceProvider.php` when you have one — otherwise it prints the
`Nodeflow::register([...])` line for you to paste. See [Writing nodes](03-writing-nodes.md).

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
