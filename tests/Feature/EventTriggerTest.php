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
            $match = $match->forTenant($tenantId, 'user', $userIds);
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

it('fans out to every tenant even when the ambient tenant is non-null', function () {
    // Every other test in this file binds an ambient tenant of null. This one
    // proves the fan-out also works when the listener runs with a concrete,
    // resolved ambient tenant — e.g. a queued job carrying tenant context, or
    // a listener invoked synchronously mid-request — which is the realistic
    // case, not the exception.
    //
    // History: this test used to document a real conflict between Task 3's
    // BelongsToTenant creating guard and this fan-out — with a non-null
    // ambient tenant, org-1's run would be created (matching the ambient
    // tenant) and then org-2's Run::create would throw
    // CrossTenantWriteException, aborting the loop and leaving org-2's alert
    // never started. The fix: StartRun now creates its Run row inside
    // TenancyGuardSuspension::run(), which suspends only the creating()
    // contradiction check — not the read scope, not materialise() — because
    // that run's tenant_id is $flow->tenant_id, already read from a trusted
    // row, not attacker-supplied input, and the run is being created in
    // response to a system event with no ambient tenant of its own. Both
    // tenants' runs are now created regardless of the ambient tenant.
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

    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeMultiTenantAlertEvent([
        'org-1' => ['1', '2'],
        'org-2' => ['3'],
    ]));

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(2)
        ->and($runs['org-1']->subjects()->count())->toBe(2)
        ->and($runs['org-2']->subjects()->count())->toBe(1);
});

it('does not let one tenant\'s failure strand another tenant\'s alert', function () {
    // org-1's subject '666' is not owned by any tenant here (simulating a bad
    // subject id in the alert payload), so materialising org-1's audience
    // throws CrossTenantSubjectException. org-2's subject '3' is fine. That
    // failure must not prevent org-2's run from starting — the same principle
    // NodeRunner already applies per subject: a fan-out must not let one
    // participant's failure strand the others. A stranded participant here is
    // a bank whose customers never receive a flood warning.
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return null; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return $i !== '666'; }
    });

    // Capture reports rather than letting them hit the real handler, so this
    // test can assert EventTriggerListener actually reports the failure
    // (via the global report() helper) instead of merely swallowing it.
    $reported = [];
    app()->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, new class($reported) implements \Illuminate\Contracts\Debug\ExceptionHandler {
        public function __construct(private array &$reported) {}

        public function report(\Throwable $e) {
            $this->reported[] = $e;
        }

        public function shouldReport(\Throwable $e)
        {
            return true;
        }

        public function render($request, \Throwable $e)
        {
            throw $e;
        }

        public function renderForConsole($output, \Throwable $e): void
        {
        }
    });

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

    // Must not throw out of handle(): org-1's failure is caught and reported.
    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeMultiTenantAlertEvent([
        'org-1' => ['666'],
        'org-2' => ['3'],
    ]));

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(1)
        ->and($runs->has('org-1'))->toBeFalse()
        ->and($runs['org-2']->subjects()->count())->toBe(1)
        ->and($reported)->toHaveCount(1)
        ->and($reported[0])->toBeInstanceOf(\Nodeflow\Execution\CrossTenantSubjectException::class);
});
