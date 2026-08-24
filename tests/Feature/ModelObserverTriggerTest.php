<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerDriver;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelOccurrence;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Tests\Support\Models\ObservedOrder;
use Tests\Support\OrderModelTriggerSource;

class AlternateOrderModelTriggerSource implements ModelObserverTriggerSource
{
    public static int $resolutions = 0;

    public static function key(): string
    {
        return 'test.alternate_observed_orders';
    }

    public static function driver(): string
    {
        return ModelObserverTriggerDriver::key();
    }

    public static function modelClass(): string
    {
        return ObservedOrder::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Alternate observed orders');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        self::$resolutions++;
        $payload = $occurrence->payload;

        return TriggerMatch::make()->forTenant(
            (string) $payload->attributes['tenant_id'],
            'user',
            [(string) $payload->attributes['user_id']],
            ['source' => 'alternate'],
            'alternate-'.$payload->event.'-'.$payload->modelKey,
        );
    }
}

class ReverseObservedOrder extends ObservedOrder {}

abstract class ReverseOrderModelTriggerSource implements ModelObserverTriggerSource
{
    public static function driver(): string
    {
        return ModelObserverTriggerDriver::key();
    }

    public static function modelClass(): string
    {
        return ReverseObservedOrder::class;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Reverse-order observed orders');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        static::$resolutions++;
        $payload = $occurrence->payload;

        return TriggerMatch::make()->forTenant(
            (string) $payload->attributes['tenant_id'],
            'user',
            [(string) $payload->attributes['user_id']],
            ['source' => static::key()],
            static::key().'-'.$payload->event.'-'.$payload->modelKey,
        );
    }
}

class ReverseFirstOrderModelTriggerSource extends ReverseOrderModelTriggerSource
{
    public static int $resolutions = 0;

    public static function key(): string
    {
        return 'test.reverse_first_observed_orders';
    }
}

class ReverseSecondOrderModelTriggerSource extends ReverseOrderModelTriggerSource
{
    public static int $resolutions = 0;

    public static function key(): string
    {
        return 'test.reverse_second_observed_orders';
    }
}

class RecordingModelTriggerExceptionHandler implements ExceptionHandler
{
    /** @var Throwable[] */
    public array $reported = [];

    public function report(Throwable $e)
    {
        $this->reported[] = $e;
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

beforeEach(function () {
    Schema::create('observed_orders', function (Blueprint $table) {
        $table->id();
        $table->string('tenant_id');
        $table->string('user_id');
        $table->string('status');
        $table->softDeletes();
    });

    $this->tenant = 'org-1';
    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return $tenantId === $this->test->tenant || $this->test->tenant === null;
        }
    });

    OrderModelTriggerSource::$resolver = null;
    OrderModelTriggerSource::$occurrences = [];
    AlternateOrderModelTriggerSource::$resolutions = 0;
    app(TriggerSourceRegistry::class)->register(OrderModelTriggerSource::class);
});

afterEach(function () {
    OrderModelTriggerSource::$resolver = null;
    OrderModelTriggerSource::$occurrences = [];
});

it('starts immediately for a created model outside a transaction', function () {
    publishObservedOrderFlow('created');

    $order = ObservedOrder::create(orderAttributes());

    $run = Run::withoutTenancy()->sole();
    expect($run->started_via)->toBe('model')
        ->and($run->trigger_data)->toMatchArray(['event' => 'created', 'status' => 'new'])
        ->and($run->subjects()->pluck('subject_id')->all())->toBe(['42'])
        ->and(OrderModelTriggerSource::$occurrences)->toHaveCount(1)
        ->and(OrderModelTriggerSource::$occurrences[0]->modelClass)->toBe(ObservedOrder::class)
        ->and(OrderModelTriggerSource::$occurrences[0]->modelKey)->toBe((string) $order->getKey())
        ->and(OrderModelTriggerSource::$occurrences[0]->connectionName)->toBe('testing');
});

it('supports only the four configured post-lifecycle events', function (string $configuredEvent, Closure $action) {
    publishObservedOrderFlow($configuredEvent);

    $action();

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->sole()->trigger_data['event'])->toBe($configuredEvent);
})->with([
    'created' => ['created', fn () => ObservedOrder::create(orderAttributes())],
    'updated' => ['updated', function () {
        $order = ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes()));
        $order->update(['status' => 'paid']);
    }],
    'deleted' => ['deleted', function () {
        $order = ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes()));
        $order->delete();
    }],
    'restored' => ['restored', function () {
        $order = ObservedOrder::withoutEvents(function () {
            $order = ObservedOrder::create(orderAttributes());
            $order->delete();

            return $order;
        });
        $order->restore();
    }],
]);

it('filters updated activations by changed-field intersection and qualifier', function () {
    publishObservedOrderFlow('created', name: 'Created only');
    publishObservedOrderFlow('updated', ['status'], 'Status updates');
    publishObservedOrderFlow('updated', ['user_id'], 'User updates');
    $order = ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes()));

    $order->update(['status' => 'paid']);

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->sole()->flow_version_id)->toBe(
            Flow::withoutTenancy()->where('name', 'Status updates')->value('current_version_id')
        )
        ->and(OrderModelTriggerSource::$occurrences[0]->changedFields)->toBe(['status']);
});

it('starts only after the outer transaction commits and never after rollback', function () {
    publishObservedOrderFlow('created');

    DB::transaction(function () {
        DB::transaction(function () {
            ObservedOrder::create(orderAttributes());
            expect(Run::withoutTenancy()->count())->toBe(0);
        });
        expect(Run::withoutTenancy()->count())->toBe(0);
    });

    expect(Run::withoutTenancy()->count())->toBe(1);

    try {
        DB::transaction(function () {
            ObservedOrder::create(orderAttributes(['user_id' => '99']));
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('captures value-only immutable state before deferral', function () {
    publishObservedOrderFlow('created');

    DB::transaction(function () use (&$order) {
        $order = ObservedOrder::create(orderAttributes());
        $order->status = 'mutated-without-save';
        expect(OrderModelTriggerSource::$occurrences)->toBe([]);
    });

    $occurrence = OrderModelTriggerSource::$occurrences[0];
    expect($occurrence)->toBeInstanceOf(ModelOccurrence::class)
        ->and($occurrence->attributes['status'])->toBe('new')
        ->and($occurrence->event)->toBe('created')
        ->and($occurrence)->not->toHaveProperty('model');
});

it('keeps deleted and restored value snapshots immutable before outer commit', function (
    string $event,
    Closure $prepare,
    Closure $emit,
    bool $eventDeletedAtIsNull,
    array $eventChangedFields,
) {
    publishObservedOrderFlow($event);

    DB::transaction(function () use ($event, $prepare, $emit) {
        $order = $prepare();
        $emit($order);

        // Reuse the exact event instance and replace all three mutable inputs a
        // deferred implementation could accidentally read: current values,
        // originals and the model's tracked changes.
        $order->status = 'mutated-after-'.$event;
        $order->deleted_at = $event === 'deleted' ? null : '2099-01-01 00:00:00';
        $order->syncChanges();
        $order->syncOriginal();

        expect(OrderModelTriggerSource::$occurrences)->toBe([]);
    });

    $occurrence = OrderModelTriggerSource::$occurrences[0];
    expect($occurrence->event)->toBe($event)
        ->and($occurrence->attributes['status'])->toBe('new')
        ->and($occurrence->original['status'])->toBe('new')
        ->and($occurrence->changedFields)->toBe($eventChangedFields)
        ->and($occurrence->attributes['deleted_at'] === null)->toBe($eventDeletedAtIsNull)
        ->and($occurrence->original['deleted_at'] === null)->toBe($eventDeletedAtIsNull)
        ->and($occurrence)->not->toHaveProperty('model');
})->with([
    'deleted' => [
        'deleted',
        fn () => ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes())),
        fn (ObservedOrder $order) => $order->delete(),
        false,
        [],
    ],
    'restored' => [
        'restored',
        fn () => ObservedOrder::withoutEvents(function () {
            $order = ObservedOrder::create(orderAttributes());
            $order->delete();

            return $order;
        }),
        fn (ObservedOrder $order) => $order->restore(),
        true,
        ['deleted_at'],
    ],
]);

it('pins the activation active at emission across republish and deactivation', function () {
    $flow = publishObservedOrderFlow('created');
    $oldVersionId = $flow->current_version_id;

    DB::transaction(function () use ($flow) {
        ObservedOrder::create(orderAttributes());
        app(PublishFlow::class)->publish(
            $flow->fresh(),
            observedOrderGraph('created', [], OrderModelTriggerSource::key()),
        );
        $flow->fresh()->update(['status' => 'paused']);
        expect(Run::withoutTenancy()->count())->toBe(0);
    });

    expect(Run::withoutTenancy()->sole()->flow_version_id)->toBe($oldVersionId);
});

it('deduplicates listeners across repeat and shared-model source registration in any order', function () {
    $eventName = 'eloquent.created: '.ObservedOrder::class;
    $before = count(Event::getFacadeRoot()->getListeners($eventName));

    expect($before)->toBe(1)
        ->and(Event::getFacadeRoot()->getListeners('eloquent.updated: '.ObservedOrder::class))->toHaveCount(1)
        ->and(Event::getFacadeRoot()->getListeners('eloquent.deleted: '.ObservedOrder::class))->toHaveCount(1)
        ->and(Event::getFacadeRoot()->getListeners('eloquent.restored: '.ObservedOrder::class))->toHaveCount(1)
        ->and(Event::getFacadeRoot()->getListeners('eloquent.saving: '.ObservedOrder::class))->toBe([])
        ->and(Event::getFacadeRoot()->getListeners('eloquent.saved: '.ObservedOrder::class))->toBe([]);

    app(TriggerSourceRegistry::class)->register(
        AlternateOrderModelTriggerSource::class,
        OrderModelTriggerSource::class,
    );
    app(TriggerSourceRegistry::class)->register(AlternateOrderModelTriggerSource::class);

    expect(count(Event::getFacadeRoot()->getListeners($eventName)))->toBe($before);

    publishObservedOrderFlow('created', source: OrderModelTriggerSource::key());
    publishObservedOrderFlow('created', name: 'Alternate', source: AlternateOrderModelTriggerSource::key());
    ObservedOrder::create(orderAttributes());

    expect(Run::withoutTenancy()->count())->toBe(2)
        ->and(AlternateOrderModelTriggerSource::$resolutions)->toBe(1)
        ->and(OrderModelTriggerSource::$occurrences)->toHaveCount(1);
});

it('deduplicates shared-model listeners when the opposite source registers first', function () {
    $dispatcher = Event::getFacadeRoot();
    $eventNames = array_map(
        fn (string $event): string => 'eloquent.'.$event.': '.ReverseObservedOrder::class,
        ['created', 'updated', 'deleted', 'restored'],
    );
    ReverseFirstOrderModelTriggerSource::$resolutions = 0;
    ReverseSecondOrderModelTriggerSource::$resolutions = 0;

    foreach ($eventNames as $eventName) {
        expect($dispatcher->getListeners($eventName))->toBe([]);
    }

    app(TriggerSourceRegistry::class)->register(
        ReverseSecondOrderModelTriggerSource::class,
        ReverseFirstOrderModelTriggerSource::class,
    );
    app(TriggerSourceRegistry::class)->register(
        ReverseFirstOrderModelTriggerSource::class,
        ReverseSecondOrderModelTriggerSource::class,
    );

    expect(array_sum(array_map(
        fn (string $eventName): int => count($dispatcher->getListeners($eventName)),
        $eventNames,
    )))->toBe(4);

    foreach ($eventNames as $eventName) {
        expect($dispatcher->getListeners($eventName))->toHaveCount(1);
    }

    publishObservedOrderFlow(
        'created',
        name: 'Reverse second',
        source: ReverseSecondOrderModelTriggerSource::key(),
    );
    publishObservedOrderFlow(
        'created',
        name: 'Reverse first',
        source: ReverseFirstOrderModelTriggerSource::key(),
    );

    ReverseObservedOrder::create(orderAttributes());

    expect(Run::withoutTenancy()->count())->toBe(2)
        ->and(ReverseFirstOrderModelTriggerSource::$resolutions)->toBe(1)
        ->and(ReverseSecondOrderModelTriggerSource::$resolutions)->toBe(1);
});

it('does not fire for query-builder mass updates', function () {
    publishObservedOrderFlow('updated');
    ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes()));

    ObservedOrder::query()->update(['status' => 'paid']);

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(OrderModelTriggerSource::$occurrences)->toBe([]);
});

it('lets the source filter, sanitize data, and define idempotency', function () {
    publishObservedOrderFlow('created');
    OrderModelTriggerSource::$resolver = fn (ModelOccurrence $occurrence) => $occurrence->attributes['status'] === 'skip'
        ? TriggerMatch::make()
        : TriggerMatch::make()->forTenant(
            'org-1',
            'user',
            ['42'],
            ['safe' => true],
            'model-delivery-1',
        );

    ObservedOrder::create(orderAttributes(['status' => 'skip']));
    ObservedOrder::create(orderAttributes());
    ObservedOrder::create(orderAttributes(['user_id' => '43']));

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and(Run::withoutTenancy()->sole()->trigger_data)->toBe(['safe' => true])
        ->and(json_encode(Run::withoutTenancy()->sole()->trigger_data))->not->toContain('tenant_id', 'status');
});

it('isolates an activation failure and does not leak it into model persistence', function () {
    publishObservedOrderFlow('updated', ['status'], 'Healthy');
    publishObservedOrderFlow('updated', ['status', 'explode'], 'Broken');
    $order = ObservedOrder::withoutEvents(fn () => ObservedOrder::create(orderAttributes()));
    $handler = new RecordingModelTriggerExceptionHandler;
    app()->instance(ExceptionHandler::class, $handler);
    OrderModelTriggerSource::$resolver = function (ModelOccurrence $occurrence, array $config): TriggerMatch {
        if (in_array('explode', $config['changed_fields'], true)) {
            throw new RuntimeException('source failed');
        }

        return TriggerMatch::make()->forTenant('org-1', 'user', ['42']);
    };

    $order->update(['status' => 'paid']);

    expect($order->fresh()->status)->toBe('paid')
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and($handler->reported)->toHaveCount(1)
        ->and($handler->reported[0]->getMessage())->toBe('source failed');
});

function publishObservedOrderFlow(
    string $event,
    array $changedFields = [],
    string $name = 'Observed flow',
    string $source = 'test.observed_orders',
): Flow {
    $flow = Flow::create(['name' => $name, 'status' => 'draft']);
    app(PublishFlow::class)->publish($flow, observedOrderGraph($event, $changedFields, $source));

    return $flow->fresh();
}

function observedOrderGraph(string $event, array $changedFields, string $source): array
{
    return [
        'start' => 'trigger',
        'nodes' => [
            [
                'id' => 'trigger',
                'type' => 'core.trigger.model_observer',
                'config' => [
                    'source' => $source,
                    'event' => $event,
                    'changed_fields' => $changedFields,
                ],
            ],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'trigger', 'output' => 'started', 'to' => 'exit']],
    ];
}

function orderAttributes(array $overrides = []): array
{
    return [...[
        'tenant_id' => 'org-1',
        'user_id' => '42',
        'status' => 'new',
    ], ...$overrides];
}
