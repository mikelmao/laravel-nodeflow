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

// A flood alert cuts across several tenants at once: one event, one TriggerMatch
// naming several tenants, one run per tenant. This is the scenario the whole
// task exists for, so it gets its own event/trigger pair rather than reusing
// FakeAlertTrigger, which is hard-wired to a single tenant.
class FakeMultiTenantAlertEvent
{
    /** @param  array<string, array<int, string>>  $tenantUserIds */
    public function __construct(public array $tenantUserIds) {}
}

class FakeMultiTenantAlertTrigger extends Trigger
{
    public static function type(): string
    {
        return 'test.multi_alert';
    }

    public static function event(): string
    {
        return FakeMultiTenantAlertEvent::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Fake Multi-Tenant Alert');
    }

    public function resolve(object $event): TriggerMatch
    {
        $match = TriggerMatch::make();

        foreach ($event->tenantUserIds as $tenantId => $userIds) {
            $match->forTenant($tenantId, 'user', $userIds);
        }

        return $match;
    }
}

// A second trigger sharing FakeAlertEvent, used only to prove that two
// triggers on one event class attach a single Event::listen, not two.
class FakeSecondAlertTrigger extends Trigger
{
    public static function type(): string
    {
        return 'test.alert.second';
    }

    public static function event(): string
    {
        return FakeAlertEvent::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Fake Second Alert');
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
    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1', '2']));

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->first()->subjects()->count())->toBe(2);
});

it('ignores flows that are not active', function () {
    Flow::withoutTenancy()->update(['status' => 'paused']);

    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1']));

    expect(Run::withoutTenancy()->count())->toBe(0);
});

it('starts a run when the trigger event is fired through the real Laravel dispatcher', function () {
    // Calling EventTriggerListener::handle() directly (as the tests above do)
    // never proves the listener is actually wired to the event. Registering a
    // trigger must attach a real Event::listen, or a redelivered production
    // event would silently do nothing.
    Event::dispatch(new FakeAlertEvent(['1', '2']));

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->first()->subjects()->count())->toBe(2);
});

it('does not process a shared event twice when two triggers listen for it', function () {
    // FakeAlertTrigger is already registered for FakeAlertEvent in beforeEach.
    // Registering a second trigger for the very same event class must not
    // attach a second Event::listen — EventTriggerListener::handle() already
    // fans out across every matching trigger by itself, so a second listener
    // would run that fan-out twice per firing.
    app(TriggerRegistry::class)->register(FakeSecondAlertTrigger::class);

    Event::dispatch(new FakeAlertEvent(['1', '2']));

    // Only the flow whose trigger_type is 'test.alert' exists (from beforeEach),
    // so only one run should ever be created — twice would mean the shared
    // event class picked up a duplicate listener.
    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('starts one run per tenant when one event matches flows in different tenants', function () {
    app(TriggerRegistry::class)->register(FakeMultiTenantAlertTrigger::class);

    $flowOrg1 = Flow::create(['tenant_id' => 'org-1', 'name' => 'F1', 'trigger_type' => 'test.multi_alert', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flowOrg1, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    $flowOrg2 = Flow::create(['tenant_id' => 'org-2', 'name' => 'F2', 'trigger_type' => 'test.multi_alert', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flowOrg2, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeMultiTenantAlertEvent([
        'org-1' => ['1', '2'],
        'org-2' => ['3'],
    ]));

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(2)
        ->and($runs['org-1']->subjects()->count())->toBe(2)
        ->and($runs['org-2']->subjects()->count())->toBe(1)
        ->and($runs['org-1']->subjects()->pluck('subject_id')->sort()->values()->all())->toBe(['1', '2'])
        ->and($runs['org-2']->subjects()->pluck('subject_id')->all())->toBe(['3']);
});

it('investigates what happens to the fan-out when the ambient tenant is non-null', function () {
    // Every other test in this file binds an ambient tenant of null, which is
    // why fan-out across org-1 and org-2 works: BelongsToTenant's creating
    // guard only throws when the ambient tenant is non-null AND contradicts an
    // explicit tenant_id. A real deployment is not guaranteed to run the
    // listener with a null ambient tenant — a queued job carrying tenant
    // context, or a listener invoked synchronously mid-request, could easily
    // resolve a concrete tenant. This test pins down what actually happens in
    // that case rather than assuming the null-tenant tests generalise.
    app(TriggerRegistry::class)->register(FakeMultiTenantAlertTrigger::class);

    $flowOrg1 = Flow::create(['tenant_id' => 'org-1', 'name' => 'F1', 'trigger_type' => 'test.multi_alert', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flowOrg1, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    $flowOrg2 = Flow::create(['tenant_id' => 'org-2', 'name' => 'F2', 'trigger_type' => 'test.multi_alert', 'status' => 'draft']);
    app(PublishFlow::class)->publish($flowOrg2, [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    // Switch the ambient tenant to a concrete, non-null value that matches
    // ONE of the two tenants the event resolves to, then fire it.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $thrown = null;

    try {
        app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeMultiTenantAlertEvent([
            'org-1' => ['1', '2'],
            'org-2' => ['3'],
        ]));
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    // FINDING: this throws Nodeflow\Models\CrossTenantWriteException. The org-1
    // run (matching the ambient tenant) is created and its workflow started
    // before the org-2 iteration is reached; org-2's Run::create then fails
    // the BelongsToTenant creating guard (ambient 'org-1' contradicts explicit
    // tenant_id 'org-2'), and that exception is not a QueryException, so
    // StartRun's idempotency-recovery catch does not intercept it — it
    // propagates straight out of EventTriggerListener::handle(), aborting the
    // loop. Net effect: one run exists (org-1's), org-2's alert is silently
    // never started, and the caller sees a thrown exception instead of a
    // clean per-tenant failure. This is a genuine conflict between the Task 3
    // mass-assignment guard and the multi-tenant fan-out this task exists
    // for: any deployment where the event listener runs with a resolved
    // ambient tenant cannot fan out to any tenant other than the ambient one.
    expect($thrown)->toBeInstanceOf(\Nodeflow\Models\CrossTenantWriteException::class);

    $runs = Run::withoutTenancy()->get();

    expect($runs)->toHaveCount(1)
        ->and($runs->first()->tenant_id)->toBe('org-1');
});
