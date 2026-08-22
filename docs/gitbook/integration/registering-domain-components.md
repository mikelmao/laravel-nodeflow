# Registering domain components

Register the nodes, triggers, and subject attributes your application intentionally exposes so authors can use only domain capabilities that you own.

Run `php artisan nodeflow:install` first. Its generated `NodeflowServiceProvider` gives the generators three reliable registration homes: the `$nodes` property, the `$triggers` property, and the `subjectAttributes()` method. Keep those anchors in place.

## Use the generated provider as the integration point

Create the component classes named below before registering them. `SendWelcomeMessage` and `UserRegisteredTrigger` are illustrative application classes; their type strings are examples to replace with stable, domain-owned identifiers.

```php
<?php

namespace App\Providers;

use App\Nodeflow\Nodes\SendWelcomeMessage;
use App\Nodeflow\OrganizationTenantResolver;
use App\Nodeflow\Triggers\UserRegisteredTrigger;
use App\Nodeflow\UserSubjectResolver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Nodeflow\Triggers\TriggerRegistry;

class NodeflowServiceProvider extends ServiceProvider
{
    /** @var class-string[] */
    protected array $nodes = [
        SendWelcomeMessage::class,
    ];

    /** @var class-string[] */
    protected array $triggers = [
        UserRegisteredTrigger::class,
    ];

    public function register(): void
    {
        $this->app->bind(TenantResolver::class, OrganizationTenantResolver::class);
        $this->app->bind(SubjectResolver::class, UserSubjectResolver::class);
    }

    public function boot(): void
    {
        Nodeflow::register($this->nodes);
        app(TriggerRegistry::class)->register(...$this->triggers);
        app(SubjectAttributeRegistry::class)->register(...$this->subjectAttributes());

        Gate::define('nodeflow.viewAny', fn (?\App\Models\User $user, mixed $resource = null): bool =>
            $user !== null
            && $user->isNodeflowAdministrator()
            && ($resource === null
                || (string) $user->organization_id === (string) $resource->tenant_id)
        );
    }

    /** @return \Nodeflow\Schema\SubjectAttribute[] */
    protected function subjectAttributes(): array
    {
        return [
            SubjectAttribute::make(
                'email_verified',
                'Email verified',
                'boolean',
                fn (\App\Models\User $user): bool => $user->hasVerifiedEmail(),
            ),
        ];
    }
}
```

`register()` is for container bindings that must exist in web, console, and queue contexts. `boot()` is for work that uses the ready application: register the components and define the application’s gates there. The example’s one gate is tenant-aware for both list and individual views, but it is not a complete authorization setup. Add the remaining three gates from [Authorization](authorization.md) before exposing Nodeflow routes.

The package keeps `NodeRegistry`, `TriggerRegistry`, and `SubjectAttributeRegistry` as container singletons. Registering in this provider updates the same registries used by the editor and runtime.

## Register nodes and keep their types stable

`Nodeflow::register($this->nodes)` accepts node class names. Each class must extend `Node` and implement `HandlesSubject`, `HandlesAudience`, or both; the registry rejects a class that lacks both cardinality interfaces.

Give each node a stable, application-owned type such as `shop.send_receipt`. Published graph versions resolve nodes by this string, not by their PHP class name. Do not use the reserved `core.` prefix, and do not let two application components claim the same type: a later registration replaces the registry entry for that type.

When renaming an implementation but preserving a published node type, keep the type method unchanged. When changing the type itself, register the new node and map every historical type directly to the current registered type with the registry’s alias API:

```php
// Partial snippet: App\Providers\NodeflowServiceProvider::boot().

Nodeflow::register($this->nodes);
Nodeflow::nodes()->alias('shop.send_receipt', 'shop.send_order_receipt');
Nodeflow::nodes()->alias('shop.email_receipt', 'shop.send_order_receipt');
```

Aliases are one hop: the registry checks one alias and then resolves that type, without following another alias. Do not create alias chains such as `shop.send_receipt` → `shop.email_receipt` → `shop.send_order_receipt`; point both historical values directly to the current type. The alias API is for node types only. `TriggerRegistry` has no alias API, so keep trigger types stable for as long as flows reference them.

For node contracts, fields, retries, and idempotency, continue with [Writing nodes](../building-automations/writing-nodes.md).

## Register triggers and attach listeners

`app(TriggerRegistry::class)->register(...$this->triggers)` registers each trigger by its stable type and immediately attaches an event listener for `Trigger::event()`. A trigger absent from `$triggers` never fires. If several registered triggers name the same event class, the registry attaches one listener and fans out to matching triggers.

Give a trigger a domain-owned type such as `shop.order_placed`; flows store that type in `trigger_type`. One type must have one owner, because a later direct registration for the same type replaces the earlier class. The registration is also the moment the listener attaches, so a wrong `event()` class is silent: the listener waits for an event that is never dispatched.

For matching, tenant grouping, idempotency, and audience selection, see [Writing triggers](../building-automations/writing-triggers.md).

## Let generators append safely

The generators append only when they find exactly one generated anchor. They do not guess if the provider is missing, an anchor is absent or duplicated, or their post-edit PHP validation fails. In those cases they print a manual registration snippet. Put the printed call in `boot()`; do not also add the same class or attribute to its generated array or method.

```php
// Partial snippets: the direct fallback calls printed by the generators.

Nodeflow::register([
    \App\Nodeflow\Nodes\SendWelcomeMessage::class,
]);

app(TriggerRegistry::class)->register(
    \App\Nodeflow\Triggers\UserRegisteredTrigger::class,
);

app(SubjectAttributeRegistry::class)->register(
    \Nodeflow\Schema\SubjectAttribute::make(
        'email_verified',
        'Email verified',
        'boolean',
        fn (\App\Models\User $user): bool => $user->hasVerifiedEmail(),
    ),
);
```

The exact property openings are `protected array $nodes = [` and `protected array $triggers = [`; the attribute generator locates `protected function subjectAttributes(): array` and its simple `return [` body. Do not duplicate or comment out copies of these anchors if you want automatic appends to continue working. For a later generator run, move a one-off fallback registration into its matching generated home before adding more components.

## Register subject attributes with durable keys

`SubjectAttributeRegistry` backs the options for the built-in condition node. An attribute has a key, author-facing label, type, and resolver closure. Each key has one owner: a later registration of the same key silently replaces the earlier label, type, and resolver. That replacement changes how every condition that stores and resolves that key is presented and evaluated. The key is stored in published graphs, so choose a conservative stable key such as `email_verified`; changing or removing it can make an existing condition unable to resolve its value. The registry returns labels as editor options and throws for an unknown key at evaluation time.

Keep the resolver focused on one resolved subject and register only fields that non-technical authors should be allowed to compare. The generator can add a starter entry:

```bash
php artisan nodeflow:make-subject-attribute email_verified --label="Email verified" --type=boolean
```

The supported introductory types are `boolean`, `text`, and `number`. See [Condition fields](../building-automations/condition-fields.md) for comparison behavior and attribute design.

## Next step

Use [Authorization](authorization.md) to complete the four gates, then follow [Writing nodes](../building-automations/writing-nodes.md) before giving authors a new side effect.
