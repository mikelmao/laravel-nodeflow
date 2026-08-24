<?php

use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Nodeflow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerDriver;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerNode;
use Nodeflow\Triggers\LaravelEvent\LaravelEventTriggerSource;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerDriver;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerNode;
use Nodeflow\Triggers\ModelObserver\ModelObserverTriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\Webhook\WebhookTriggerDriver;
use Nodeflow\Triggers\Webhook\WebhookTriggerNode;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

function registerBuiltInDefinitionsModelSource(): string
{
    $source = new class implements ModelObserverTriggerSource
    {
        public static function key(): string
        {
            return 'orders';
        }

        public static function driver(): string
        {
            return 'model';
        }

        public static function modelClass(): string
        {
            return \Illuminate\Database\Eloquent\Model::class;
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Orders model');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    Nodeflow::registerTriggerSources([$source::class]);

    return $source::key();
}

it('registers the built-in trigger nodes and drivers under stable keys', function () {
    $nodes = app(TriggerNodeRegistry::class);
    $drivers = app(TriggerDriverRegistry::class);

    expect($nodes->all())->toMatchArray([
        'core.trigger.webhook' => WebhookTriggerNode::class,
        'core.trigger.model_observer' => ModelObserverTriggerNode::class,
        'core.trigger.laravel_event' => LaravelEventTriggerNode::class,
    ])->and($drivers->all())->toMatchArray([
        'webhook' => WebhookTriggerDriver::class,
        'model' => ModelObserverTriggerDriver::class,
        'event' => LaravelEventTriggerDriver::class,
    ]);
});

it('exposes trigger-only palette output contracts', function () {
    $palette = collect(app(TriggerNodeRegistry::class)->palette())->keyBy('type');

    foreach (['core.trigger.webhook', 'core.trigger.model_observer', 'core.trigger.laravel_event'] as $type) {
        expect($palette[$type])
            ->toHaveKey('kind', 'trigger')
            ->toHaveKey('outputs', ['started']);
    }
});

it('defines required source fields and the model observer event contract', function () {
    $webhook = app(WebhookTriggerNode::class)->definition();
    $model = app(ModelObserverTriggerNode::class)->definition();
    $event = app(LaravelEventTriggerNode::class)->definition();

    expect($webhook->outputNames())->toBe(['started'])
        ->and($event->outputNames())->toBe(['started'])
        ->and($model->outputNames())->toBe(['started'])
        ->and($webhook->rules()['source'])->toContain('required')
        ->and($event->rules()['source'])->toContain('required')
        ->and($model->rules()['source'])->toContain('required')
        ->and($model->rules()['event'])->toContain('required', 'in:created,updated,deleted,restored')
        ->and($model->rules()['changed_fields'])->toContain('nullable', 'array');
});

it('compiles built-in trigger configuration into stable descriptors', function () {
    expect(app(WebhookTriggerNode::class)->compile(['source' => 'orders'])->toArray())->toBe([
        'driver' => 'webhook',
        'source' => 'orders',
        'qualifier' => null,
        'metadata' => [],
    ])->and(app(LaravelEventTriggerNode::class)->compile(['source' => 'order.placed'])->toArray())->toBe([
        'driver' => 'event',
        'source' => 'order.placed',
        'qualifier' => null,
        'metadata' => [],
    ])->and(app(ModelObserverTriggerNode::class)->compile([
        'source' => 'orders',
        'event' => 'updated',
        'changed_fields' => ['status' => 'status', 8 => 'total'],
    ])->toArray())->toBe([
        'driver' => 'model',
        'source' => 'orders',
        'qualifier' => 'updated',
        'metadata' => ['changed_fields' => ['status', 'total']],
    ]);
});

it('validates required model fields', function () {
    $sources = Nodeflow::triggerSources();
    $node = app(ModelObserverTriggerNode::class);

    expect($node->validate([], $sources))->toHaveKeys(['source', 'event']);
});

it('accepts every supported model event when changed fields are omitted', function (string $event) {
    $source = registerBuiltInDefinitionsModelSource();

    expect(app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => $event,
    ], Nodeflow::triggerSources()))->toBe([]);
})->with(['created', 'updated', 'deleted', 'restored']);

it('accepts absent-equivalent and updated model changed fields', function (string $event, mixed $changedFields) {
    $source = registerBuiltInDefinitionsModelSource();

    expect(app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => $event,
        'changed_fields' => $changedFields,
    ], Nodeflow::triggerSources()))->toBe([]);
})->with([
    'null on a non-updated event' => ['created', null],
    'empty on a non-updated event' => ['created', []],
    'non-empty on updated' => ['updated', ['status']],
]);

it('rejects unsupported model events with the event validation message', function () {
    $source = registerBuiltInDefinitionsModelSource();
    $errors = app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => 'saved',
    ], Nodeflow::triggerSources());

    expect($errors)->toHaveKey('event')
        ->and($errors['event'])->toContain('The selected event is invalid.')
        ->and($errors)->not->toHaveKey('source');
});

it('rejects non-empty changed fields for non-updated model events', function () {
    $source = registerBuiltInDefinitionsModelSource();
    $errors = app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => 'created',
        'changed_fields' => ['status'],
    ], Nodeflow::triggerSources());

    expect($errors)->toHaveKey('changed_fields')
        ->and($errors['changed_fields'])->toBe([
            'Changed fields may only be configured for the updated event.',
        ])
        ->and($errors)->not->toHaveKey('source');
});

it('rejects malformed model changed fields without masking the shape error', function () {
    $source = registerBuiltInDefinitionsModelSource();
    $errors = app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => 'created',
        'changed_fields' => 'status',
    ], Nodeflow::triggerSources());

    expect($errors)->toHaveKey('changed_fields')
        ->and($errors['changed_fields'])->toBe(['The changed fields field must be an array.'])
        ->and($errors)->not->toHaveKey('source');
});

it('rejects non-string model changed field members', function () {
    $source = registerBuiltInDefinitionsModelSource();
    $errors = app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => 'created',
        'changed_fields' => ['status', 42],
    ], Nodeflow::triggerSources());

    expect($errors)->toBe([
        'changed_fields.1' => [
            'The changed_fields.1 field must be a string.',
        ],
    ]);
});

it('accepts only registered sources implementing the driver-specific source contract', function () {
    $webhookSource = new class implements WebhookTriggerSource
    {
        public static function key(): string
        {
            return 'orders';
        }

        public static function driver(): string
        {
            return 'webhook';
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Orders webhook');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    $wronglyTypedSource = new class implements TriggerSource
    {
        public static function key(): string
        {
            return 'not-a-webhook';
        }

        public static function driver(): string
        {
            return 'webhook';
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Wrong source type');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    Nodeflow::registerTriggerSources([$webhookSource::class, $wronglyTypedSource::class]);

    $node = app(WebhookTriggerNode::class);
    $driver = app(WebhookTriggerDriver::class);

    expect($node->validate(['source' => 'orders'], Nodeflow::triggerSources()))->toBe([])
        ->and($driver->validate(new TriggerActivationDescriptor('webhook', 'orders', null, [])))->toBe([])
        ->and($node->validate(['source' => 'missing'], Nodeflow::triggerSources()))->toHaveKey('source')
        ->and($node->validate(['source' => 'not-a-webhook'], Nodeflow::triggerSources()))->toHaveKey('source')
        ->and($driver->validate(new TriggerActivationDescriptor('webhook', 'missing', null, [])))->toHaveKey('source')
        ->and($driver->validate(new TriggerActivationDescriptor('webhook', 'not-a-webhook', null, [])))->toHaveKey('source')
        ->and($driver->validate(new TriggerActivationDescriptor('event', 'orders', null, [])))->toHaveKey('driver');
});

it('recognizes typed model and event source extensions', function () {
    expect(is_subclass_of(ModelObserverTriggerSource::class, TriggerSource::class))->toBeTrue()
        ->and(is_subclass_of(LaravelEventTriggerSource::class, TriggerSource::class))->toBeTrue()
        ->and(is_subclass_of(WebhookTriggerSource::class, TriggerSource::class))->toBeTrue()
        ->and(method_exists(ModelObserverTriggerSource::class, 'modelClass'))->toBeTrue()
        ->and(method_exists(LaravelEventTriggerSource::class, 'eventClass'))->toBeTrue();
});

it('validates model descriptors against model observer sources', function () {
    $source = registerBuiltInDefinitionsModelSource();

    $descriptor = new TriggerActivationDescriptor('model', $source, 'updated', [
        'changed_fields' => ['status'],
    ]);

    expect(app(ModelObserverTriggerNode::class)->validate([
        'source' => $source,
        'event' => 'updated',
        'changed_fields' => ['status'],
    ], Nodeflow::triggerSources()))->toBe([])
        ->and(app(ModelObserverTriggerDriver::class)->validate($descriptor))->toBe([])
        ->and(app(ModelObserverTriggerDriver::class)->validate(
            new TriggerActivationDescriptor('webhook', $source, 'updated', []),
        ))->toHaveKey('driver');
});

it('rejects a model descriptor resolving to the wrong source interface', function () {
    $source = new class implements TriggerSource
    {
        public static function key(): string
        {
            return 'not-a-model';
        }

        public static function driver(): string
        {
            return 'model';
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Wrong model source type');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    Nodeflow::registerTriggerSources([$source::class]);

    expect(app(ModelObserverTriggerDriver::class)->validate(
        new TriggerActivationDescriptor('model', 'not-a-model', 'updated', []),
    ))->toBe([
        'source' => ['The registered source is not a model observer trigger source.'],
    ]);
});

it('validates event descriptors against Laravel event sources', function () {
    $source = new class implements LaravelEventTriggerSource
    {
        public static function key(): string
        {
            return 'order.placed';
        }

        public static function driver(): string
        {
            return 'event';
        }

        public static function eventClass(): string
        {
            return stdClass::class;
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Order placed event');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    Nodeflow::registerTriggerSources([$source::class]);

    expect(app(LaravelEventTriggerNode::class)->validate([
        'source' => 'order.placed',
    ], Nodeflow::triggerSources()))->toBe([])
        ->and(app(LaravelEventTriggerDriver::class)->validate(
            new TriggerActivationDescriptor('event', 'order.placed', null, []),
        ))->toBe([])
        ->and(app(LaravelEventTriggerDriver::class)->validate(
            new TriggerActivationDescriptor('model', 'order.placed', null, []),
        ))->toHaveKey('driver');
});

it('rejects an event descriptor resolving to the wrong source interface', function () {
    $source = new class implements TriggerSource
    {
        public static function key(): string
        {
            return 'not-an-event';
        }

        public static function driver(): string
        {
            return 'event';
        }

        public function definition(): TriggerDefinition
        {
            return TriggerDefinition::make('Wrong event source type');
        }

        public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
        {
            return TriggerMatch::make();
        }
    };

    Nodeflow::registerTriggerSources([$source::class]);

    expect(app(LaravelEventTriggerDriver::class)->validate(
        new TriggerActivationDescriptor('event', 'not-an-event', null, []),
    ))->toBe([
        'source' => ['The registered source is not a Laravel event trigger source.'],
    ]);
});
