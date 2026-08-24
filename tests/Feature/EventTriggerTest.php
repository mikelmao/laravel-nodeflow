<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Tests\Support\OrderPlacedAcrossTenants;
use Tests\Support\OrderPlacedEventSource;

final class AlternateOrderPlacedEventSource implements LaravelEventTriggerSource
{
    public static int $snapshots = 0;

    public static int $resolutions = 0;

    public static bool $failSnapshot = false;

    public static function key(): string
    {
        return 'test.alternate_order_placed';
    }

    public static function driver(): string
    {
        return LaravelEventTriggerDriver::key();
    }

    public static function eventClass(): string
    {
        return OrderPlacedAcrossTenants::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Alternate order placed');
    }

    public function snapshot(object $event): LaravelEventOccurrence
    {
        self::$snapshots++;

        if (self::$failSnapshot) {
            throw new RuntimeException('alternate snapshot failed');
        }

        return new LaravelEventOccurrence($event::class, [
            'event_id' => $event->eventId,
            'deliveries' => $event->deliveries,
        ]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        self::$resolutions++;
        $delivery = $occurrence->payload->data['deliveries']['org-2'] ?? null;

        return $delivery === null
            ? TriggerMatch::make()
            : TriggerMatch::make()->forTenant(
                'org-2',
                'user',
                $delivery['users'],
                ['source' => 'alternate'],
                $occurrence->payload->data['event_id'],
            );
    }

    public static function reset(): void
    {
        self::$snapshots = 0;
        self::$resolutions = 0;
        self::$failSnapshot = false;
    }
}

final class ReverseOrderPlacedEvent
{
    public function __construct(public string $eventId = 'reverse-1') {}
}

abstract class ReverseOrderPlacedSource implements LaravelEventTriggerSource
{
    public static function driver(): string
    {
        return LaravelEventTriggerDriver::key();
    }

    public static function eventClass(): string
    {
        return ReverseOrderPlacedEvent::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make(static::key());
    }

    public function snapshot(object $event): LaravelEventOccurrence
    {
        static::$snapshots++;

        return new LaravelEventOccurrence($event::class, ['event_id' => $event->eventId]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        static::$resolutions++;

        return TriggerMatch::make();
    }
}

final class ReverseFirstOrderPlacedSource extends ReverseOrderPlacedSource
{
    public static int $snapshots = 0;

    public static int $resolutions = 0;

    public static function key(): string
    {
        return 'test.reverse_first';
    }
}

final class ReverseSecondOrderPlacedSource extends ReverseOrderPlacedSource
{
    public static int $snapshots = 0;

    public static int $resolutions = 0;

    public static function key(): string
    {
        return 'test.reverse_second';
    }
}

final class UnregisteredEvent
{
    public function __construct(public string $secret = 'must-not-be-read') {}
}

final class InvalidEventClassSource extends OrderPlacedEventSource
{
    public static function key(): string
    {
        return 'test.invalid_event_class';
    }

    public static function eventClass(): string
    {
        return 'Missing\\Events\\NeverDefined';
    }
}

interface InvalidEventInterface {}

final class InterfaceEventSource extends OrderPlacedEventSource
{
    public static function key(): string
    {
        return 'test.interface_event';
    }

    public static function eventClass(): string
    {
        return InvalidEventInterface::class;
    }
}

abstract class AbstractOrderPlacedEvent {}

final class AbstractEventSource extends OrderPlacedEventSource
{
    public static function key(): string
    {
        return 'test.abstract_event';
    }

    public static function eventClass(): string
    {
        return AbstractOrderPlacedEvent::class;
    }
}

final class RecordingEventTriggerExceptionHandler implements ExceptionHandler
{
    /** @var Throwable[] */
    public array $reported = [];

    public bool $throwWhileReporting = false;

    public function report(Throwable $e)
    {
        $this->reported[] = $e;

        if ($this->throwWhileReporting) {
            throw new RuntimeException('reporter failed');
        }
    }

    public function shouldReport(Throwable $e)
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {}
}

final class ExplosiveEventState implements JsonSerializable
{
    public int $serializationAttempts = 0;

    public function __serialize(): array
    {
        $this->serializationAttempts++;

        throw new RuntimeException('event object was serialized');
    }

    public function jsonSerialize(): mixed
    {
        $this->serializationAttempts++;

        throw new RuntimeException('event object was JSON serialized');
    }

    public function __toString(): string
    {
        $this->serializationAttempts++;

        throw new RuntimeException('event object was stringified');
    }
}

beforeEach(function () {
    $this->tenant = null;
    $this->unownedSubject = null;

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return $subjectId !== $this->test->unownedSubject;
        }
    });

    OrderPlacedEventSource::reset();
    AlternateOrderPlacedEventSource::reset();
    ReverseFirstOrderPlacedSource::$snapshots = 0;
    ReverseFirstOrderPlacedSource::$resolutions = 0;
    ReverseSecondOrderPlacedSource::$snapshots = 0;
    ReverseSecondOrderPlacedSource::$resolutions = 0;

    Nodeflow::registerTriggerSources([OrderPlacedEventSource::class]);
});

afterEach(fn () => OrderPlacedEventSource::reset());

it('starts every matching active tenant flow through the real Laravel dispatcher', function () {
    publishOrderPlacedFlow('org-1', minimumTotal: 50, name: 'Org 1');
    publishOrderPlacedFlow('org-2', minimumTotal: 100, name: 'Org 2');

    Event::dispatch(orderPlacedEvent());

    $runs = Run::withoutTenancy()->get()->keyBy('tenant_id');

    expect($runs)->toHaveCount(1)
        ->and($runs->has('org-1'))->toBeTrue()
        ->and($runs['org-1']->started_via)->toBe('event')
        ->and($runs['org-1']->trigger_data)->toBe(['total' => 75])
        ->and($runs['org-1']->subjects()->pluck('subject_id')->sort()->values()->all())->toBe(['1', '2']);
});

it('fans one event out to matching flows in several tenants with an ambient tenant', function () {
    publishOrderPlacedFlow('org-1', minimumTotal: 50, name: 'Org 1');
    publishOrderPlacedFlow('org-2', minimumTotal: 50, name: 'Org 2');
    $this->tenant = 'org-1';

    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->orderBy('tenant_id')->pluck('tenant_id')->all())
        ->toBe(['org-1', 'org-2']);
});

it('does nothing for an unallowlisted event class or when no active activation matches', function () {
    publishOrderPlacedFlow('org-1', minimumTotal: 1000);

    Event::dispatch(new UnregisteredEvent);
    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(OrderPlacedEventSource::$occurrences)->toHaveCount(1);
});

it('attaches one listener per event class across repeated and shared-source registration', function () {
    $dispatcher = Event::getFacadeRoot();
    $before = count($dispatcher->getListeners(OrderPlacedAcrossTenants::class));

    expect($before)->toBe(1);

    Nodeflow::registerTriggerSources([
        AlternateOrderPlacedEventSource::class,
        OrderPlacedEventSource::class,
    ]);
    Nodeflow::registerTriggerSources([AlternateOrderPlacedEventSource::class]);

    expect($dispatcher->getListeners(OrderPlacedAcrossTenants::class))->toHaveCount($before);

    publishOrderPlacedFlow('org-1', source: OrderPlacedEventSource::key(), name: 'Primary');
    publishOrderPlacedFlow('org-2', source: AlternateOrderPlacedEventSource::key(), name: 'Alternate');
    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->count())->toBe(2)
        ->and(OrderPlacedEventSource::$snapshots)->toBe(1)
        ->and(AlternateOrderPlacedEventSource::$snapshots)->toBe(1)
        ->and(AlternateOrderPlacedEventSource::$resolutions)->toBe(1);
});

it('deduplicates a shared event listener when the opposite source registers first', function () {
    $dispatcher = Event::getFacadeRoot();

    expect($dispatcher->getListeners(ReverseOrderPlacedEvent::class))->toBe([]);

    Nodeflow::registerTriggerSources([
        ReverseSecondOrderPlacedSource::class,
        ReverseFirstOrderPlacedSource::class,
    ]);
    Nodeflow::registerTriggerSources([
        ReverseFirstOrderPlacedSource::class,
        ReverseSecondOrderPlacedSource::class,
    ]);

    expect($dispatcher->getListeners(ReverseOrderPlacedEvent::class))->toHaveCount(1);

    Event::dispatch(new ReverseOrderPlacedEvent);

    // No activations means sources are never asked to snapshot or resolve.
    expect(ReverseFirstOrderPlacedSource::$snapshots)->toBe(0)
        ->and(ReverseSecondOrderPlacedSource::$snapshots)->toBe(0);
});

it('uses the source occurrence identity to make redelivery idempotent', function () {
    publishOrderPlacedFlow('org-1');
    $event = orderPlacedEvent('delivery-91');

    Event::dispatch($event);
    Event::dispatch($event);

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(OrderPlacedEventSource::$occurrences)->toHaveCount(2);
});

it('pins every candidate before one source resolution republishes and deactivates flows', function () {
    $first = publishOrderPlacedFlow('org-1', name: 'First');
    $second = publishOrderPlacedFlow('org-1', name: 'Second');
    $firstVersion = $first->current_version_id;
    $secondVersion = $second->current_version_id;
    $mutated = false;

    OrderPlacedEventSource::$resolver = function (LaravelEventOccurrence $occurrence, array $config) use (
        $first,
        $second,
        &$mutated,
    ): TriggerMatch {
        if (! $mutated) {
            $mutated = true;
            app(PublishFlow::class)->publish($first->fresh(), orderPlacedGraph(OrderPlacedEventSource::key(), 0));
            $second->fresh()->update(['status' => 'paused']);
        }

        return TriggerMatch::make()->forTenant(
            'org-1',
            'user',
            ['1'],
            ['snapshot' => true],
            'pinned-event',
        );
    };

    Event::dispatch(orderPlacedEvent('pinned-event'));

    expect(Run::withoutTenancy()->orderBy('flow_version_id')->pluck('flow_version_id')->all())
        ->toBe(collect([$firstVersion, $secondVersion])->sort()->values()->all());
});

it('isolates activation failures and contains reporter failures', function () {
    publishOrderPlacedFlow('org-1', name: 'Broken');
    publishOrderPlacedFlow('org-2', name: 'Healthy');
    $this->unownedSubject = '666';
    $handler = new RecordingEventTriggerExceptionHandler;
    $handler->throwWhileReporting = true;
    app()->instance(ExceptionHandler::class, $handler);

    Event::dispatch(new OrderPlacedAcrossTenants('isolated-1', [
        'org-1' => ['users' => ['666'], 'total' => 75],
        'org-2' => ['users' => ['3'], 'total' => 75],
    ]));

    expect(Run::withoutTenancy()->pluck('tenant_id')->all())->toBe(['org-2'])
        ->and($handler->reported)->toHaveCount(1);
});

it('isolates a source snapshot failure from another source sharing the event', function () {
    Nodeflow::registerTriggerSources([AlternateOrderPlacedEventSource::class]);
    publishOrderPlacedFlow('org-1', source: OrderPlacedEventSource::key(), name: 'Primary');
    publishOrderPlacedFlow('org-2', source: AlternateOrderPlacedEventSource::key(), name: 'Broken');
    AlternateOrderPlacedEventSource::$failSnapshot = true;
    $handler = new RecordingEventTriggerExceptionHandler;
    app()->instance(ExceptionHandler::class, $handler);

    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->pluck('tenant_id')->all())->toBe(['org-1'])
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0]->getMessage())->toBe('alternate snapshot failed');
});

it('isolates malformed foreign tenant matches from healthy sources', function () {
    Nodeflow::registerTriggerSources([AlternateOrderPlacedEventSource::class]);
    publishOrderPlacedFlow('org-1', source: OrderPlacedEventSource::key(), name: 'Foreign');
    publishOrderPlacedFlow('org-2', source: AlternateOrderPlacedEventSource::key(), name: 'Healthy');
    OrderPlacedEventSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('foreign-org', 'user', ['9']);
    $handler = new RecordingEventTriggerExceptionHandler;
    app()->instance(ExceptionHandler::class, $handler);

    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->pluck('tenant_id')->all())->toBe(['org-2'])
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0]->getMessage())->toContain('activation tenant [org-1]');
});

it('never reflects over or automatically serializes the host event object', function () {
    publishOrderPlacedFlow('org-1');
    $unsafe = new ExplosiveEventState;

    Event::dispatch(new OrderPlacedAcrossTenants(
        'safe-1',
        ['org-1' => ['users' => ['1'], 'total' => 75]],
        $unsafe,
    ));

    $occurrence = OrderPlacedEventSource::$occurrences[0];
    $run = Run::withoutTenancy()->sole();

    expect($unsafe->serializationAttempts)->toBe(0)
        ->and($occurrence)->toBeInstanceOf(LaravelEventOccurrence::class)
        ->and($occurrence)->not->toHaveProperty('event')
        ->and($occurrence->data)->not->toHaveKey('unsafeState')
        ->and($run->trigger_data)->toBe(['total' => 75]);
});

it('rejects occurrence snapshots containing mutable object state', function () {
    publishOrderPlacedFlow('org-1');
    OrderPlacedEventSource::$snapshotter = fn (OrderPlacedAcrossTenants $event) => new LaravelEventOccurrence(
        $event::class,
        ['unsafe' => new stdClass],
    );
    $handler = new RecordingEventTriggerExceptionHandler;
    app()->instance(ExceptionHandler::class, $handler);

    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0])->toBeInstanceOf(InvalidArgumentException::class);
});

it('stops a cyclic source snapshot before it reaches resolution or dispatch', function () {
    publishOrderPlacedFlow('org-1');
    OrderPlacedEventSource::$snapshotter = function (OrderPlacedAcrossTenants $event): LaravelEventOccurrence {
        $data = [];
        $data['self'] =& $data;

        return new LaravelEventOccurrence($event::class, $data);
    };
    $handler = new RecordingEventTriggerExceptionHandler;
    app()->instance(ExceptionHandler::class, $handler);

    Event::dispatch(orderPlacedEvent());

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(OrderPlacedEventSource::$occurrences)->toBe([])
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($handler->reported[0]->getMessage())->toContain('recursive reference');
});

it('preserves normal synchronous Laravel event timing', function () {
    publishOrderPlacedFlow('org-1');

    DB::transaction(function () {
        Event::dispatch(orderPlacedEvent());

        expect(OrderPlacedEventSource::$occurrences)->toHaveCount(1)
            ->and(Run::withoutTenancy()->count())->toBe(1);
    });
});

it('rejects registration of a source whose allowlisted event class does not exist', function () {
    expect(fn () => Nodeflow::registerTriggerSources([InvalidEventClassSource::class]))
        ->toThrow(InvalidArgumentException::class, 'event class');

    expect(app(TriggerSourceRegistry::class)->has('event', InvalidEventClassSource::key()))->toBeFalse();
});

it('requires a concrete allowlisted event class rather than an interface listener', function () {
    expect(fn () => Nodeflow::registerTriggerSources([InterfaceEventSource::class]))
        ->toThrow(InvalidArgumentException::class, 'event class');

    expect(app(TriggerSourceRegistry::class)->has('event', InterfaceEventSource::key()))->toBeFalse();
});

it('rejects an abstract allowlisted event class that can never be dispatched directly', function () {
    expect(fn () => Nodeflow::registerTriggerSources([AbstractEventSource::class]))
        ->toThrow(InvalidArgumentException::class, 'event class');

    expect(app(TriggerSourceRegistry::class)->has('event', AbstractEventSource::key()))->toBeFalse();
});

it('deep-copies referenced scalar and array values out of an event occurrence', function () {
    $status = 'before';
    $nested = ['value' => 'nested-before'];
    $data = [
        'status' => &$status,
        'nested' => &$nested,
    ];

    $occurrence = new LaravelEventOccurrence(OrderPlacedAcrossTenants::class, $data);
    $status = 'after';
    $nested['value'] = 'nested-after';

    expect($occurrence->data)->toBe([
        'status' => 'before',
        'nested' => ['value' => 'nested-before'],
    ]);
});

it('rejects excessively deep event value trees at a deterministic boundary', function () {
    $data = 'leaf';

    for ($depth = 0; $depth < 70; $depth++) {
        $data = ['next' => $data];
    }

    expect(fn () => new LaravelEventOccurrence(OrderPlacedAcrossTenants::class, ['root' => $data]))
        ->toThrow(InvalidArgumentException::class, 'maximum depth');
});

it('rejects oversized event value trees at a deterministic boundary', function () {
    expect(fn () => new LaravelEventOccurrence(
        OrderPlacedAcrossTenants::class,
        array_fill(0, 10001, 'value'),
    ))->toThrow(InvalidArgumentException::class, 'maximum value count');
});

it('rejects self-referential event value trees without exhausting the PHP process', function () {
    $autoload = realpath(__DIR__.'/../../vendor/autoload.php');
    $code = sprintf(<<<'PHP'
    require %s;

    $data = [];
    $data['self'] =& $data;

    try {
        new \Nodeflow\Triggers\LaravelEvent\LaravelEventOccurrence(\stdClass::class, $data);
    } catch (\InvalidArgumentException $e) {
        echo $e->getMessage();
        exit(0);
    }

    exit(2);
    PHP, var_export($autoload, true));
    $output = [];
    $exitCode = 0;

    exec(escapeshellarg(PHP_BINARY).' -d memory_limit=32M -r '.escapeshellarg($code).' 2>&1', $output, $exitCode);

    expect($exitCode)->toBe(0)
        ->and(implode(PHP_EOL, $output))->toContain('recursive reference');
});

function publishOrderPlacedFlow(
    string $tenantId,
    int $minimumTotal = 0,
    string $source = 'test.order_placed',
    string $name = 'Order placed flow',
): Flow {
    $flow = Flow::create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'status' => 'draft',
    ]);
    app(PublishFlow::class)->publish($flow, orderPlacedGraph($source, $minimumTotal));

    return $flow->fresh();
}

function orderPlacedGraph(string $source, int $minimumTotal): array
{
    return [
        'start' => 'trigger',
        'nodes' => [
            [
                'id' => 'trigger',
                'type' => 'core.trigger.laravel_event',
                'config' => [
                    'source' => $source,
                    'minimum_total' => $minimumTotal,
                ],
            ],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'trigger', 'output' => 'started', 'to' => 'exit']],
    ];
}

function orderPlacedEvent(string $eventId = 'delivery-1'): OrderPlacedAcrossTenants
{
    return new OrderPlacedAcrossTenants($eventId, [
        'org-1' => ['users' => ['1', '2'], 'total' => 75],
        'org-2' => ['users' => ['3'], 'total' => 75],
    ]);
}
