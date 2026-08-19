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
    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1', '2']));

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->first()->subjects()->count())->toBe(2);
});

it('ignores flows that are not active', function () {
    Flow::withoutTenancy()->update(['status' => 'paused']);

    app(\Nodeflow\Triggers\EventTriggerListener::class)->handle(new FakeAlertEvent(['1']));

    expect(Run::withoutTenancy()->count())->toBe(0);
});
