# Testing the workflow

These focused Pest excerpts verify the flood-alert application boundary without pretending that a fake engine executes a workflow. `FakeWorkflowEngine` records `start()`, `signal()`, and `cancel()` calls only. Use `NodeRunner` directly when a test needs to execute `app.send_message`; use Laravel's real event dispatcher when a test needs trigger registration and routing.

## Test prerequisites

The host test database must run the application migrations for `organizations`, `users`, `flood_alerts`, `demo_messages`, and `flood_alert_workflows`, plus the package migrations. The data contract is defined in [Application setup](application-setup.md). These excerpts create records directly, so the illustrative models must permit the shown attributes or use equivalent factories.

Put the helpers and imports below at the top of `tests/Feature/FloodAlertWorkflowTest.php`. The local `FloodAlertTestTenantResolver` is a test fixture, not a package fake. It makes multi-organization setup explicit while preserving the real ownership query. The real application `UserSubjectResolver`, `SendMessage`, `FloodAlertFires`, graph class, and events are exercised.

```php
<?php

use App\Events\FloodAlertDispatched;
use App\Events\OfferClicked;
use App\Listeners\ExitFloodAlertRunsForOfferClick;
use App\Models\FloodAlert;
use App\Models\Organization;
use App\Models\User;
use App\Nodeflow\Nodes\SendMessage;
use App\Nodeflow\Triggers\FloodAlertFires;
use App\Nodeflow\UserSubjectResolver;
use App\Support\FloodAlertGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\StartRun;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Triggers\TriggerRegistry;

uses(RefreshDatabase::class);

final class FloodAlertTestTenantResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        return null;
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        return $subjectType === 'user'
            && User::query()
                ->whereKey($subjectId)
                ->where('organization_id', $tenantId)
                ->exists();
    }
}

function makeOrganization(string $name): Organization
{
    return Organization::forceCreate(['name' => $name]);
}

function makeUser(Organization $organization, string $email): User
{
    return User::forceCreate([
        'organization_id' => $organization->getKey(),
        'name' => $email,
        'email' => $email,
        'password' => 'not-used-by-this-test',
        'clicked_offer_at' => null,
    ]);
}

function publishFloodFlow(Organization $organization): Flow
{
    $flow = Flow::create([
        'tenant_id' => (string) $organization->getKey(),
        'name' => 'Flood alert follow-up',
        'trigger_type' => FloodAlertFires::type(),
        'trigger_config' => ['severities' => ['severe']],
        'status' => 'draft',
    ]);

    app(PublishFlow::class)->publish($flow, FloodAlertGraph::definition(), 'test-author');

    return $flow->fresh();
}

beforeEach(function (): void {
    config(['nodeflow.tenancy' => 'disabled']);
    app()->bind(TenantResolver::class, FloodAlertTestTenantResolver::class);
    app()->bind(SubjectResolver::class, UserSubjectResolver::class);
    app()->singleton(WorkflowEngine::class, FakeWorkflowEngine::class);

    Nodeflow::register([SendMessage::class]);
    app(TriggerRegistry::class)->register(FloodAlertFires::class);
});
```

`RefreshDatabase` is Laravel's database fixture tool. This fixture sets `nodeflow.tenancy` to `disabled` only because it creates flows for two organizations without authenticating an actor. It explicitly asserts each resulting `tenant_id`; it does not test tenant-scoped reads. Production uses the real `OrganizationTenantResolver` and a tenant-aware request or worker context. `FakeWorkflowEngine` is the package binding used here to observe workflow starts and audience-empty signals; it does not invoke node code. `Event::fake()` is intentionally absent from trigger tests because it would suppress the listener that is under test.

## One event isolates tenant runs

Dispatch one real event with separate organization map entries. The trigger may create more runs if an organization has more matching active flows; this fixture creates exactly one matching flow per organization.

```php
it('creates isolated runs for each tenant in a flood alert', function (): void {
    $firstOrganization = makeOrganization('First organization');
    $secondOrganization = makeOrganization('Second organization');
    $firstUser = makeUser($firstOrganization, 'first@example.test');
    $secondUser = makeUser($secondOrganization, 'second@example.test');
    $firstFlow = publishFloodFlow($firstOrganization);
    $secondFlow = publishFloodFlow($secondOrganization);
    $alert = FloodAlert::forceCreate(['severity' => 'severe']);

    Event::dispatch(new FloodAlertDispatched(
        alertId: (string) $alert->getKey(),
        severity: $alert->severity,
        userIdsByOrganization: [
            (string) $firstOrganization->getKey() => [(string) $firstUser->getKey()],
            (string) $secondOrganization->getKey() => [(string) $secondUser->getKey()],
        ],
    ));

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(2)
        ->and($runs[(string) $firstOrganization->getKey()]->flow_version_id)
            ->toBe($firstFlow->current_version_id)
        ->and($runs[(string) $secondOrganization->getKey()]->flow_version_id)
            ->toBe($secondFlow->current_version_id)
        ->and($runs[(string) $firstOrganization->getKey()]->subjects()->pluck('subject_id')->all())
            ->toBe([(string) $firstUser->getKey()])
        ->and($runs[(string) $secondOrganization->getKey()]->subjects()->pluck('subject_id')->all())
            ->toBe([(string) $secondUser->getKey()]);

    expect(app(WorkflowEngine::class)->started())->toHaveCount(2);
});
```

The last assertion observes two queued workflow starts. It does not claim that either workflow has already sent a message.

## A run stays pinned to its published version

```php
it('pins a run to the graph version that was current when it started', function (): void {
    $organization = makeOrganization('Organization');
    $user = makeUser($organization, 'user@example.test');
    $flow = publishFloodFlow($organization);
    $firstVersionId = $flow->current_version_id;

    $run = app(StartRun::class)->forFlow(
        $flow,
        'user',
        [(string) $user->getKey()],
    );

    app(PublishFlow::class)->publish($flow->fresh(), FloodAlertGraph::definition(), 'test-author');

    expect($flow->fresh()->current_version_id)->not->toBe($firstVersionId)
        ->and($run->fresh()->flow_version_id)->toBe($firstVersionId)
        ->and($run->fresh()->flowVersion->graph['start'])->toBe('send-alert');
});
```

## Test mode routes without writing a message

This test runs the first node explicitly because the fake engine does not consume the run. It proves both sides of test mode: no `DemoMessage` persistence and normal movement to `wait-before-offer`.

```php
it('suppresses DemoMessage persistence in test mode while preserving routing', function (): void {
    $organization = makeOrganization('Organization');
    $user = makeUser($organization, 'user@example.test');
    $flow = publishFloodFlow($organization);

    $run = app(StartRun::class)->forFlow(
        $flow,
        'user',
        [(string) $user->getKey()],
        ['is_test' => true],
    );

    app(NodeRunner::class)->run(
        $run,
        Graph::fromArray($run->flowVersion->graph),
        'send-alert',
    );

    expect(\App\Models\DemoMessage::query()->count())->toBe(0)
        ->and(RunSubject::query()->where('run_id', $run->id)->value('current_node_id'))
            ->toBe('wait-before-offer');
});
```

## Tenant drift is rejected before delivery

The initial audience check does not freeze a user's organization membership for the duration of a wait. This focused test moves the user after the run starts and executes the node directly; the node must fail before it creates a `DemoMessage` row.

```php
it('does not persist a message after the user leaves the run tenant', function (): void {
    $organization = makeOrganization('Organization');
    $otherOrganization = makeOrganization('Other organization');
    $user = makeUser($organization, 'user@example.test');
    $flow = publishFloodFlow($organization);
    $run = app(StartRun::class)->forFlow($flow, 'user', [(string) $user->getKey()]);

    $user->forceFill(['organization_id' => $otherOrganization->getKey()])->save();

    app(NodeRunner::class)->run(
        $run,
        Graph::fromArray($run->flowVersion->graph),
        'send-alert',
    );

    expect(\App\Models\DemoMessage::query()->count())->toBe(0)
        ->and(RunSubject::query()->where('run_id', $run->id)->value('status'))->toBe('failed');
});
```

## Conversion exits before a follow-up

The durable wait itself belongs to the workflow engine. This focused test places the user at the second wait, runs the application listener, and confirms that no active subject remains for the wait to advance. It also proves the `audienceEmptied` signal sent by `SubjectExiter` when this is the final active subject.

```php
it('exits a converted user before the follow-up can run', function (): void {
    $organization = makeOrganization('Organization');
    $user = makeUser($organization, 'user@example.test');
    $flow = publishFloodFlow($organization);
    $run = app(StartRun::class)->forFlow($flow, 'user', [(string) $user->getKey()]);

    $user->forceFill(['clicked_offer_at' => now()])->save();
    $run->update(['status' => 'waiting']);
    RunSubject::query()
        ->where('run_id', $run->id)
        ->update(['current_node_id' => 'wait-for-response', 'status' => 'active']);

    app(ExitFloodAlertRunsForOfferClick::class)->handle(new OfferClicked(
        organizationId: (string) $organization->getKey(),
        userId: (string) $user->getKey(),
    ));

    app(NodeRunner::class)->run(
        $run->fresh(),
        Graph::fromArray($run->fresh()->flowVersion->graph),
        'wait-for-response',
    );

    expect(RunSubject::query()->where('run_id', $run->id)->value('status'))->toBe('exited')
        ->and(RunSubject::query()->where('run_id', $run->id)->value('current_node_id'))->toBeNull()
        ->and(\App\Models\DemoMessage::query()->where('message', 'follow_up')->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->signals()[0]['method'])->toBe('audienceEmptied');
});
```

## An invalid graph creates no version

Use the real node registration and publication action. The unsupported `message` option fails the node field validation before `PublishFlow` writes a `FlowVersion`.

```php
it('rejects an invalid graph without writing a flow version', function (): void {
    $organization = makeOrganization('Organization');
    $flow = Flow::create([
        'tenant_id' => (string) $organization->getKey(),
        'name' => 'Flood alert follow-up',
        'trigger_type' => FloodAlertFires::type(),
        'trigger_config' => ['severities' => ['severe']],
        'status' => 'draft',
    ]);
    $graph = FloodAlertGraph::definition();
    $graph['nodes'][0]['config']['message'] = 'unknown_message';
    $versionsBefore = FlowVersion::withoutTenancy()->count();

    expect(fn () => app(PublishFlow::class)->publish($flow, $graph))
        ->toThrow(GraphInvalidException::class);

    expect(FlowVersion::withoutTenancy()->count())->toBe($versionsBefore)
        ->and($flow->fresh()->current_version_id)->toBeNull();
});
```

Run the focused file after application migrations and the Nodeflow migrations are available:

```bash
php artisan test tests/Feature/FloodAlertWorkflowTest.php
```

## Next step

Use [Writing nodes](../building-automations/writing-nodes.md), [Writing triggers](../building-automations/writing-triggers.md), and [Publishing flows](../building-automations/publishing-flows.md) when adapting this illustrative workflow to a production delivery service.
