# Testing the workflow

Use Laravel's real event dispatcher when testing a Laravel-event trigger. `Event::fake()` would suppress the exact package listener under test. `FakeWorkflowEngine` proves dispatch intent without pretending to execute the durable workflow; test node behavior separately through `NodeRunner`.

## Fixture

```php
<?php

use App\Events\FloodAlertDispatched;
use App\Models\Organization;
use App\Models\User;
use App\Nodeflow\Nodes\SendMessage;
use App\Nodeflow\Triggers\FloodAlertSource;
use App\Nodeflow\UserSubjectResolver;
use App\Support\FloodAlertGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;

uses(RefreshDatabase::class);

final class FloodAlertTestTenantResolver implements TenantResolver
{
    public function currentTenantId(): ?string
    {
        return null;
    }

    public function ownsSubject(
        string $tenantId,
        string $subjectType,
        string $subjectId,
    ): bool {
        return $subjectType === 'user'
            && User::query()
                ->whereKey($subjectId)
                ->where('organization_id', $tenantId)
                ->exists();
    }
}

function publishFloodFlow(Organization $organization): Flow
{
    $flow = Flow::create([
        'tenant_id' => (string) $organization->getKey(),
        'name' => 'Flood alert',
        'status' => 'draft',
    ]);

    app(PublishFlow::class)->publish(
        $flow,
        FloodAlertGraph::definition(),
        publishedBy: 'test-author',
        expectedDraftRevision: (int) $flow->draft_revision,
    );

    return $flow->fresh();
}

beforeEach(function (): void {
    config(['nodeflow.tenancy' => 'disabled']);
    app()->bind(TenantResolver::class, FloodAlertTestTenantResolver::class);
    app()->bind(SubjectResolver::class, UserSubjectResolver::class);
    app()->singleton(WorkflowEngine::class, FakeWorkflowEngine::class);

    Nodeflow::register([SendMessage::class]);
    Nodeflow::registerTriggerSources([FloodAlertSource::class]);
});
```

The source is the allowlist. The built-in `event` driver and `core.trigger.laravel_event` node are already present; repeating them in this fixture is unnecessary.

## One event creates isolated tenant runs

```php
it('starts one pinned tenant run for each organization', function (): void {
    $first = Organization::forceCreate(['name' => 'First']);
    $second = Organization::forceCreate(['name' => 'Second']);
    $firstUser = User::factory()->create(['organization_id' => $first->id]);
    $secondUser = User::factory()->create(['organization_id' => $second->id]);
    $firstFlow = publishFloodFlow($first);
    $secondFlow = publishFloodFlow($second);

    event(new FloodAlertDispatched(
        alertId: 'alert-42',
        severity: 'severe',
        userIdsByOrganization: [
            (string) $first->id => [(string) $firstUser->id],
            (string) $second->id => [(string) $secondUser->id],
        ],
    ));

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(2)
        ->and($runs[(string) $first->id]->flow_version_id)
            ->toBe($firstFlow->current_version_id)
        ->and($runs[(string) $second->id]->flow_version_id)
            ->toBe($secondFlow->current_version_id)
        ->and($runs[(string) $first->id]->started_via)->toBe('event')
        ->and($runs[(string) $first->id]->trigger_node_id)->toBe('flood-alert')
        ->and($runs[(string) $first->id]->trigger_data)->toBe([
            'alert_id' => 'alert-42',
            'severity' => 'severe',
        ]);
});
```

This assertion proves tenant selection, pinned version, origin, and source-owned value data. It does not claim the fake engine executed `SendMessage`.

## Redelivery is idempotent

```php
it('deduplicates the same alert for the same flow version', function (): void {
    $organization = Organization::forceCreate(['name' => 'One']);
    $user = User::factory()->create(['organization_id' => $organization->id]);
    publishFloodFlow($organization);
    $event = new FloodAlertDispatched(
        alertId: 'alert-42',
        severity: 'severe',
        userIdsByOrganization: [
            (string) $organization->id => [(string) $user->id],
        ],
    );

    event($event);
    event($event);

    expect(Run::withoutTenancy()->count())->toBe(1);
});
```

The source's occurrence identity is hashed with driver/source identity and protected by the unique `(flow_version_id, idempotency_key)` index. Re-publishing creates a new version and therefore a new deduplication scope.

## Publication rejects a graph without a trigger start

```php
it('requires the flood event trigger as the graph start', function (): void {
    $organization = Organization::forceCreate(['name' => 'One']);
    $flow = Flow::create([
        'tenant_id' => (string) $organization->id,
        'name' => 'Invalid',
        'status' => 'draft',
    ]);
    $graph = FloodAlertGraph::definition();
    $graph['start'] = 'send-alert';

    expect(fn () => app(PublishFlow::class)->publish(
        $flow,
        $graph,
        'test-author',
        (int) $flow->draft_revision,
    ))->toThrow(\Nodeflow\Publishing\GraphInvalidException::class);
});
```

For HTTP-level tests, also assert the publish response's structured `node_errors`, route authorization, and tenant-scoped `404` behavior. For webhook protocols, use the exact raw body and headers described in [Writing triggers](../building-automations/writing-triggers.md#sign-and-send-the-request).
