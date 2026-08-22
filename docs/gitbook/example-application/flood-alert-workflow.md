# Flood-alert workflow

This page makes the flood-alert journey executable. The application owns the message records, alert event, and conversion event; Nodeflow provides the trigger listener, durable runtime, and graph validation.

## Implement the message node

`DemoMessage` is a host record, not a package model. Its unique `run_id`, `node_id`, `user_id` index is required: retries of the same node for the same user must not create a second delivery record. This node declares the `sent` output used by the graph below. A `fail()` result is terminal for that subject; it records a sanitized error and does not follow a graph edge.

**File: `app/Nodeflow/Nodes/SendMessage.php`**

```php
<?php

namespace App\Nodeflow\Nodes;

use App\Models\DemoMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Models\Run;
use Throwable;

class SendMessage extends Node implements HandlesSubject
{
    protected const BODIES = [
        'flood_alert' => 'A flood alert affects your area.',
        'offer' => 'Here is the support offer associated with this alert.',
        'follow_up' => 'We have not seen a response. Here is a follow-up.',
    ];

    public static function type(): string
    {
        return 'app.send_message';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send message')
            ->group('Messaging')
            ->description('Record one application-owned message for each user.')
            ->outputs(['sent'])
            ->fields([
                Field::select('message')
                    ->label('Message')
                    ->options([
                        'flood_alert' => 'Flood alert',
                        'offer' => 'Offer',
                        'follow_up' => 'Follow-up',
                    ])
                    ->required(),
            ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        $message = (string) $context->config('message');
        $body = self::BODIES[$message] ?? null;
        $user = $context->subject();

        if (! $user instanceof User) {
            return $context->fail('Message recipient is unavailable.');
        }

        if ($body === null) {
            return $context->fail('Message configuration is invalid.');
        }

        if ($context->isTest()) {
            return $context->continue('sent');
        }

        // The original audience check happened when the run started. A wait can
        // pass before this node runs, so re-check current host membership against
        // the run's tenant before making any host-owned side effect.
        $run = Run::withoutTenancy()->find($context->runId());
        $tenantId = $run?->tenant_id;

        if ($tenantId === null || (string) $user->organization_id !== (string) $tenantId) {
            return $context->fail('Message recipient is no longer eligible.');
        }

        $identity = [
            'run_id' => $context->runId(),
            'node_id' => $context->nodeId(),
            'user_id' => $context->subjectId(),
        ];

        try {
            DemoMessage::query()->firstOrCreate($identity, [
                'organization_id' => $tenantId,
                'message' => $message,
                'body' => $body,
            ]);

            return $context->continue('sent');
        } catch (QueryException $exception) {
            // A competing retry may have won the unique insert. Treat that as
            // the same successful delivery record, but surface every other DB error.
            if (DemoMessage::query()->where($identity)->exists()) {
                return $context->continue('sent');
            }

            report($exception);

            return $context->fail('Message delivery could not be recorded.');
        } catch (Throwable $exception) {
            report($exception);

            return $context->fail('Message delivery could not be recorded.');
        }
    }
}
```

Test mode must prevent externally visible work. Here it suppresses the `DemoMessage` write while retaining the normal `sent` route. In a live run, the node reads only the package-owned run selected by `runId()` and compares its tenant with the current `User` organization immediately before the write. Initial `ownsSubject()` validation cannot catch a membership change during a durable wait. A mismatch fails the subject before any host write or delivery attempt; the persisted `organization_id` always comes from the trusted run tenant, not request input. A real delivery adapter belongs behind the same branch and must use the same identity (or an equivalent provider idempotency key). The returned failure text is safe for run inspection; exception details go only to the application's error reporting.

## Implement the trigger

`FloodAlertFires` listens for the exact `FloodAlertDispatched` class from the setup page. It returns one tenant audience for each map entry, filters active flows by their `trigger_config`, and gives each redelivery of the same alert a stable idempotency key.

**File: `app/Nodeflow/Triggers/FloodAlertFires.php`**

```php
<?php

namespace App\Nodeflow\Triggers;

use App\Events\FloodAlertDispatched;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\Trigger;
use Nodeflow\Triggers\TriggerMatch;

class FloodAlertFires extends Trigger
{
    public static function type(): string
    {
        return 'app.flood_alert';
    }

    public static function event(): string
    {
        return FloodAlertDispatched::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Flood alert dispatched')
            ->description('Start for the users affected by a dispatched flood alert.')
            ->fields([
                Field::multiselect('severities')
                    ->label('Severities')
                    ->options([
                        'moderate' => 'Moderate',
                        'severe' => 'Severe',
                    ]),
            ]);
    }

    public function resolve(object $event): TriggerMatch
    {
        /** @var FloodAlertDispatched $event */
        $match = TriggerMatch::make();

        foreach ($event->userIdsByOrganization as $organizationId => $userIds) {
            if ($userIds === []) {
                continue;
            }

            $match->forTenant(
                tenantId: (string) $organizationId,
                subjectType: 'user',
                subjectIds: $userIds,
            );
        }

        return $match;
    }

    public function matchesConfig(object $event, array $config): bool
    {
        /** @var FloodAlertDispatched $event */
        $severities = $config['severities'] ?? [];

        return is_array($severities)
            && ($severities === [] || in_array($event->severity, $severities, true));
    }

    public function idempotencyKey(object $event): ?string
    {
        /** @var FloodAlertDispatched $event */
        return 'flood-alert:'.$event->alertId;
    }
}
```

`TriggerMatch` holds one audience per tenant, so calling `forTenant()` twice with the same organization replaces the earlier audience. Merge IDs first if the application has more than one source. For every matched tenant, the listener starts each active flow with trigger type `app.flood_alert` whose `severities` configuration matches. The idempotency scope is a flow version: one event can still create separate runs for different organizations, matching flows, or later published versions.

When a run already exists for the same flow version and idempotency key, `StartRun` returns that existing run and ignores newly supplied subject IDs and options. A redelivered `FloodAlertDispatched` must therefore replay the same immutable audience snapshot. If the business audience legitimately changes, define an explicit new event identity and versioned idempotency-key semantics; do not silently change the audience behind an existing key or generate a new key merely to bypass deduplication.

The generated provider registration is the source of listener wiring. Keep these existing homes and add the two classes once:

**File: `app/Providers/NodeflowServiceProvider.php` (partial)**

```php
use App\Nodeflow\Nodes\SendMessage;
use App\Nodeflow\Triggers\FloodAlertFires;

/** @var class-string[] */
protected array $nodes = [
    SendMessage::class,
];

/** @var class-string[] */
protected array $triggers = [
    FloodAlertFires::class,
];
```

The complete provider, including `Nodeflow::register($this->nodes)` and `TriggerRegistry::register(...$this->triggers)`, is in [Application setup](application-setup.md). Registering the trigger attaches its exact Laravel event listener.

## Create and publish the graph

The graph is acyclic, uses one edge per `from` and `output` pair, and includes the `sent` output declared by `app.send_message`.

**File: `app/Support/FloodAlertGraph.php`**

```php
<?php

namespace App\Support;

class FloodAlertGraph
{
    public static function definition(): array
    {
        return [
            'start' => 'send-alert',
            'nodes' => [
                ['id' => 'send-alert', 'type' => 'app.send_message', 'config' => ['message' => 'flood_alert']],
                ['id' => 'wait-before-offer', 'type' => 'core.wait', 'config' => ['duration' => '5 minutes']],
                ['id' => 'send-offer', 'type' => 'app.send_message', 'config' => ['message' => 'offer']],
                ['id' => 'wait-for-response', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
                ['id' => 'clicked-offer', 'type' => 'core.condition', 'config' => [
                    'attribute' => 'clicked_offer',
                    'operator' => 'is_true',
                    'value' => null,
                ]],
                ['id' => 'send-follow-up', 'type' => 'app.send_message', 'config' => ['message' => 'follow_up']],
                ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'send-alert', 'output' => 'sent', 'to' => 'wait-before-offer'],
                ['from' => 'wait-before-offer', 'output' => 'default', 'to' => 'send-offer'],
                ['from' => 'send-offer', 'output' => 'sent', 'to' => 'wait-for-response'],
                ['from' => 'wait-for-response', 'output' => 'default', 'to' => 'clicked-offer'],
                ['from' => 'clicked-offer', 'output' => 'yes', 'to' => 'exit'],
                ['from' => 'clicked-offer', 'output' => 'no', 'to' => 'send-follow-up'],
                ['from' => 'send-follow-up', 'output' => 'sent', 'to' => 'exit'],
            ],
        ];
    }
}
```

Only an authorized, tenant-scoped host action should create and publish a flow. Do not accept a tenant, flow, version, or graph structural ID from a request; this example supplies the trusted graph class and derives tenant ownership from the authenticated actor. `FloodAlertWorkflow` is the host-owned, unique mapping that makes this provisioning replay-safe: without it, two active `app.flood_alert` flows would both start and produce duplicate deliveries.

**File: `app/Http/Controllers/FloodAlertFlowController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Nodeflow\Triggers\FloodAlertFires;
use App\Support\FloodAlertGraph;
use App\Models\FloodAlertWorkflow;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;
use LogicException;

class FloodAlertFlowController
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('nodeflow.viewAny');

        $organizationId = (int) $request->user()->organization_id;

        $flow = DB::transaction(function () use ($organizationId, $request): Flow {
            // Lock the actor's own organization before looking up or creating its
            // unique mapping. This serializes concurrent and retried provisioning.
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            $mapping = FloodAlertWorkflow::query()
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if ($mapping?->flow_id !== null) {
                // This scoped lookup is safe because organizationId came from the
                // authenticated actor and Flow is tenant-scoped by the resolver.
                $flow = Flow::query()->findOrFail($mapping->flow_id);

                if ($flow->status !== 'active' || $flow->current_version_id === null) {
                    throw new LogicException('The provisioned flood-alert flow is not active.');
                }

                return $flow;
            }

            $mapping ??= FloodAlertWorkflow::create([
                'organization_id' => $organizationId,
            ]);

            $flow = Flow::create([
                'name' => 'Flood alert follow-up',
                'trigger_type' => FloodAlertFires::type(),
                'trigger_config' => ['severities' => ['severe']],
                'status' => 'draft',
            ]);

            Gate::authorize('publish', $flow);

            app(PublishFlow::class)->publish(
                $flow,
                FloodAlertGraph::definition(),
                (string) $request->user()->getKey(),
            );

            $mapping->update(['flow_id' => $flow->id]);

            return $flow;
        });

        return to_route('nodeflow.flows.edit', ['flow' => $flow]);
    }
}
```

The mapping row's unique `organization_id` and the locked organization row make concurrent requests converge on one flow. The flow, publication, and mapping update share one transaction: a failed authorization or publish rolls back a newly created draft and mapping row. If a mapped flow has been manually made inactive, the endpoint refuses it rather than provisioning a second matching flow. `PublishFlow` validates the graph, creates an immutable version, then makes it current. Runs remain pinned to the version they started with. Publishing and concurrent draft saves need application-level coordination; see [Publishing flows](../building-automations/publishing-flows.md) and [Flows and versions](../building-automations/flows-and-versions.md) for those constraints.

## Dispatch alerts and stop converted users

Dispatching this event is live: trigger-started runs have `is_test` set to `false`. Dispatch only after the alert and its audience are committed, and never use this event as a test-mode shortcut.

**File: `app/Actions/DispatchFloodAlert.php` (partial action; `$alert` is a persisted `FloodAlert`)**

```php
use App\Events\FloodAlertDispatched;
use App\Models\User;

$userIdsByOrganization = User::query()
    ->whereIn('id', $affectedUserIds)
    ->get()
    ->groupBy('organization_id')
    ->map(fn ($users): array => $users->map(fn (User $user): string => (string) $user->getKey())->all())
    ->all();

event(new FloodAlertDispatched(
    alertId: (string) $alert->getKey(),
    severity: $alert->severity,
    userIdsByOrganization: $userIdsByOrganization,
));
```

When a user converts, mark `users.clicked_offer_at` first, then dispatch an event that removes that user from active flood-alert runs. Nodeflow has no packaged inverse lookup from a user to its runs, so scope that host query through the organization, flood-alert flows, their versions, and the active subject row.

**File: `app/Events/OfferClicked.php`**

```php
<?php

namespace App\Events;

class OfferClicked
{
    public function __construct(
        public readonly string $organizationId,
        public readonly string $userId,
    ) {
    }
}
```

**File: `app/Listeners/ExitFloodAlertRunsForOfferClick.php`**

```php
<?php

namespace App\Listeners;

use App\Events\OfferClicked;
use App\Models\User;
use App\Nodeflow\Triggers\FloodAlertFires;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;

class ExitFloodAlertRunsForOfferClick
{
    public function handle(OfferClicked $event): void
    {
        $organizationId = (string) $event->organizationId;
        $userId = (string) $event->userId;

        if (! User::query()
            ->whereKey($userId)
            ->where('organization_id', $organizationId)
            ->exists()) {
            return;
        }

        $flowIds = Flow::withoutTenancy()
            ->where('tenant_id', $organizationId)
            ->where('trigger_type', FloodAlertFires::type())
            ->pluck('id');

        $versionIds = FlowVersion::withoutTenancy()
            ->whereIn('flow_id', $flowIds)
            ->pluck('id');

        $runs = Run::withoutTenancy()
            ->where('tenant_id', $organizationId)
            ->whereIn('flow_version_id', $versionIds)
            ->whereIn('status', ['pending', 'running', 'waiting', 'blocked'])
            ->whereHas('subjects', fn ($query) => $query
                ->where('subject_type', 'user')
                ->where('subject_id', $userId)
                ->where('status', 'active'))
            ->get();

        foreach ($runs as $run) {
            app(SubjectExiter::class)->exit($run, [$userId]);
        }
    }
}
```

Register the host listener once in the application's event provider:

**File: `app/Providers/EventServiceProvider.php` (partial `boot()` method)**

```php
use App\Events\OfferClicked;
use App\Listeners\ExitFloodAlertRunsForOfferClick;
use Illuminate\Support\Facades\Event;

Event::listen(OfferClicked::class, ExitFloodAlertRunsForOfferClick::class);
```

`SubjectExiter` marks only active matching subjects as exited. If the last active subject leaves a live waiting run, it signals the workflow so the wait can finish early. It does not reverse a message already delivered. Start workers for the application's selected queue connection:

```bash
php artisan queue:work
```

Configure a non-`sync` Laravel queue connection for durable waits, then keep workers running for that connection. A `sync` queue runs work inline and is not an appropriate worker-backed configuration for a long-lived automation.

## Next step

Use [Testing the workflow](testing-the-workflow.md) to prove the tenant, version, test-mode, cancellation, and validation boundaries.
