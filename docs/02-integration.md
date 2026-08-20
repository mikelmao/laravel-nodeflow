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
for the second, so `nodeflow.tenancy` declares which you mean — though, as below,
you will usually leave it alone:

```php
// config/nodeflow.php
'tenancy' => 'auto',   // or 'disabled', or 'resolver'
```

The config value is `env('NODEFLOW_TENANCY', 'auto')`, so you do not have to
publish the config file to set it:

```dotenv
NODEFLOW_TENANCY=disabled
```

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

Those three strings are the only accepted values, matched exactly. Anything else —
`Auto`, `AUTO`, `true`, or a cached config from before the key existed — throws
`InvalidArgumentException` on the next scoped read, naming the offending value. It
deliberately does not fall back to `disabled`: a typo that silently read every
tenant's rows would punish the reader of these docs and spare the person who
never opened them.

System operations that genuinely span tenants opt out explicitly with
`Model::withoutTenancy()` — the package's own fan-out triggers and queue
activities all do, so none of the three modes break them.

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

In a service provider's `boot()`:

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

**A `Gate::before` hook in your application overrides the default deny.** That
is Laravel's own semantics rather than anything the package adds: a `before`
callback returning a non-null value is answered before the policy is consulted
at all, so `Gate::before(fn ($user) => $user->isSuperAdmin() ? true : null)`
grants your super-admins every Nodeflow ability without a single
`Gate::define`. Your choice, and a reasonable one — but worth knowing, because
"deny when the gate does not exist" then no longer describes those users.
Return `null` for the cases the hook does not decide; returning `false` denies
outright and takes the decision away from the policies entirely.

A host needing more than a gate can override the package's policy entirely by
binding its own with `Gate::policy(Flow::class, YourPolicy::class)` in a
provider that boots after this one.

### Tenant isolation and your gate

The tenant scope is the primary control, and it is a stronger one than a gate: a
cross-tenant id never survives the scoped read, so it is a 404 before your gate
is asked anything. But that holds only while a tenant actually resolves. Under
the shipped default (`auto`), a null tenant is unscoped only if you never bound a
`TenantResolver`; once you bind one, `auto` throws on a null instead of reading
every tenant's rows. The gap opens only if you explicitly set `nodeflow.tenancy`
to `disabled` while a resolver is bound — a queue-dispatched preview, an API
token that has not selected an organisation yet, a console command — the read is
**not** scoped and the row comes back.

Which is why the `nodeflow.update` example above compares
`$user->organization_id === $flow->tenant_id`. That is deliberate: it costs one
comparison, it is defence in depth behind the scope, and on the one path where
the ambient tenant can still go unscoped — `disabled` mode with a resolver bound
— it is the only check still standing. Keep it, and prefer it in any gate that
receives a `Flow` or a `Run`.

So: the scope decides "is this row reachable at all", and your gate decides "may
this person do this" — plus, cheaply, "is this row theirs".

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

`PUT .../draft` takes `{graph, draft_revision}` and returns the new
`{draft_revision}` — an integer counter, not a timestamp. `draft_revision` is
nullable: omit it (or send `null`) for a flow's first-ever save, since there is
nothing yet to be stale against. Echo the value you were last given back on
every save after that: if it does not match what the server holds, you get
**409** with `{message, graph, draft_revision}` — the newer graph and its
revision — so the editor can say "someone else edited this" rather than
silently discarding a colleague's work.

`draft_updated_at` still exists and is written on every save, but it never
appears in this endpoint's response. It surfaces only in `flow.draft_updated_at`
on the edit page's initial payload, for display — "last saved 3 minutes ago" —
and must not be used for staleness detection. (An earlier version of this
contract used that timestamp as the token; Laravel's own timestamp columns store
to second precision, so two autosaves inside the same second minted an identical
value and staleness detection silently stopped detecting. The integer counter
replaced it for that reason.)

**Draft saves are not validated.** A graph mid-edit is allowed to be broken — that
is why a draft is not a version. Validation happens at publish.

### Publish

`POST .../publish` takes `{graph}` and returns `{version}`. On rejection it returns
**422** with `message` plus both shapes of the same failures:

- `errors` — flat strings, fine for a summary banner
- `node_errors` — `[{node, field, message}]`, so each message can be rendered on its
  own node. `node` is `null` for a graph-level problem such as a cycle, which belongs
  to no single node.

One further wrinkle: for "the start node you set does not exist in this flow",
`node` is set to that missing start id — an id that has, by definition, no node in
the graph. A client cannot assume every `node_errors` entry maps to a rendered
card; render what you can find and fall back to the banner for the rest.

Publishing clears the draft, since the draft became the version.

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

There is **no bundled front end**. The editor's server half — [the routes above](#the-editors-routes),
draft autosave, publish validation, tenant-scoped field options — is complete; what is missing is the
React app: no canvas, no field controls, no palette sidebar for a staff member to actually click
through. Until that exists, or if you never want it, flows are created and published programmatically:

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

The graph JSON shape above is the same shape [the editor's routes](#the-editors-routes) already
consume, not a hypothetical one. A node may carry a `position` key for canvas coordinates; the
package round-trips it untouched and ignores it.
