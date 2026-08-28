<?php

use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Graph\InvalidGraphTypeRegistration;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\AbstractTriggerNode;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerNodeRegistry;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Tests\Support\FakeCollidingExecutableNode;
use Tests\Support\FakeDuplicateTriggerDriver;
use Tests\Support\FakeDuplicateTriggerSource;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeTriggerDriver;
use Tests\Support\FakeTriggerNode;
use Tests\Support\FakeTriggerSource;

class MutableStableKeyDriver implements \Nodeflow\Contracts\TriggerDriver
{
    public static string $key = 'test.mutable-driver';

    public static int $sourceRegistrations = 0;

    public static function key(): string
    {
        return self::$key;
    }

    public function sourceRegistered(\Nodeflow\Contracts\TriggerSource $source): void
    {
        self::$sourceRegistrations++;
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        return [];
    }
}

class MutableStableKeySource implements \Nodeflow\Contracts\TriggerSource
{
    public static string $key = 'test.mutable-source';

    public static string $driver = 'test.mutable-driver';

    public static function key(): string
    {
        return self::$key;
    }

    public static function driver(): string
    {
        return self::$driver;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Mutable stable source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

class MutableStableKeyTriggerNode extends AbstractTriggerNode
{
    public static string $type = 'test.mutable-trigger';

    public static function type(): string
    {
        return self::$type;
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Mutable stable trigger');
    }

    public function driver(): string
    {
        return 'test.mutable-driver';
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor('test.mutable-driver', 'test.mutable-source', null, []);
    }
}

class MutableStableKeyExecutableNode extends Node implements HandlesSubject
{
    public static string $type = 'test.mutable-executable';

    public static function type(): string
    {
        return self::$type;
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Mutable stable executable');
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        return $context->continue();
    }
}

beforeEach(function () {
    FakeTriggerDriver::$onSourceRegistered = null;
    MutableStableKeyDriver::$key = 'test.mutable-driver';
    MutableStableKeyDriver::$sourceRegistrations = 0;
    MutableStableKeySource::$key = 'test.mutable-source';
    MutableStableKeySource::$driver = 'test.mutable-driver';
    MutableStableKeyTriggerNode::$type = 'test.mutable-trigger';
    MutableStableKeyExecutableNode::$type = 'test.mutable-executable';
});

it('registers extensions under stable graph driver and source keys', function () {
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([FakeTriggerNode::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    $driver = app(TriggerDriverRegistry::class)->resolve('test.fake');
    $node = app(TriggerNodeRegistry::class)->resolve('test.fake_trigger');
    $source = app(TriggerSourceRegistry::class)->resolve('test.fake', 'test.orders');
    $descriptor = $node->compile(['source' => 'test.orders', 'account' => 'primary']);

    expect($driver)->toBeInstanceOf(FakeTriggerDriver::class)
        ->and($node)->toBeInstanceOf(FakeTriggerNode::class)
        ->and($source)->toBeInstanceOf(FakeTriggerSource::class)
        ->and($descriptor->toArray())->toBe([
            'driver' => 'test.fake',
            'source' => 'test.orders',
            'qualifier' => null,
            'metadata' => ['account' => 'primary'],
        ])
        ->and(json_encode($descriptor->toArray()))
        ->not->toContain(FakeTriggerDriver::class)
        ->not->toContain(FakeTriggerNode::class)
        ->not->toContain(FakeTriggerSource::class);
});

it('notifies a driver exactly once when its source is registered idempotently', function () {
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect(app(TriggerDriverRegistry::class)->resolve('test.fake')->registeredSources)->toBe(1);
});

it('makes a source visible while notifying its driver', function () {
    $sources = app(TriggerSourceRegistry::class);
    $visibleDuringCallback = false;

    FakeTriggerDriver::$onSourceRegistered = function ($source) use ($sources, &$visibleDuringCallback) {
        $visibleDuringCallback = $sources->has('test.fake', 'test.orders')
            && $sources->resolve('test.fake', 'test.orders') === $source;
    };

    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect($visibleDuringCallback)->toBeTrue();
});

it('does not notify a driver twice when source registration re-enters', function () {
    $sources = app(TriggerSourceRegistry::class);
    $reentered = false;

    FakeTriggerDriver::$onSourceRegistered = function ($source) use ($sources, &$reentered) {
        if (! $reentered) {
            $reentered = true;
            $sources->register($source::class);
        }
    };

    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect(app(TriggerDriverRegistry::class)->resolve('test.fake')->registeredSources)->toBe(1);
});

it('rolls back a source whose driver callback fails and permits retry', function () {
    $sources = app(TriggerSourceRegistry::class);
    $attempts = 0;

    FakeTriggerDriver::$onSourceRegistered = function () use (&$attempts) {
        $attempts++;

        if ($attempts === 1) {
            throw new RuntimeException('listener boot failed');
        }
    };

    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);

    expect(fn () => Nodeflow::registerTriggerSources([FakeTriggerSource::class]))
        ->toThrow(RuntimeException::class, 'listener boot failed')
        ->and($sources->has('test.fake', 'test.orders'))->toBeFalse()
        ->and(fn () => $sources->resolve('test.fake', 'test.orders'))
        ->toThrow(RuntimeException::class, 'test.fake:test.orders');

    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect($sources->has('test.fake', 'test.orders'))->toBeTrue()
        ->and(app(TriggerDriverRegistry::class)->resolve('test.fake')->registeredSources)->toBe(2);
});

it('refuses to register a source before its driver', function () {
    expect(fn () => Nodeflow::registerTriggerSources([FakeTriggerSource::class]))
        ->toThrow(InvalidArgumentException::class, 'test.fake');
});

it('rejects duplicate driver and source keys claimed by different classes', function () {
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);

    expect(fn () => Nodeflow::registerTriggerDrivers([FakeDuplicateTriggerDriver::class]))
        ->toThrow(InvalidArgumentException::class, 'test.fake');

    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    expect(fn () => Nodeflow::registerTriggerSources([FakeDuplicateTriggerSource::class]))
        ->toThrow(InvalidArgumentException::class, 'test.fake:test.orders')
        ->and(app(TriggerSourceRegistry::class)->resolve('test.fake', 'test.orders'))
        ->toBeInstanceOf(FakeTriggerSource::class);
});

it('throws descriptive errors when resolving unknown drivers and sources', function () {
    expect(fn () => app(TriggerDriverRegistry::class)->resolve('test.missing'))
        ->toThrow(RuntimeException::class, 'test.missing')
        ->and(fn () => app(TriggerSourceRegistry::class)->resolve('test.fake', 'test.missing'))
        ->toThrow(RuntimeException::class, 'test.fake:test.missing');
});

it('prevents executable and trigger nodes claiming the same stable graph type', function () {
    $nodes = app(NodeRegistry::class);
    $triggers = app(TriggerNodeRegistry::class);

    $nodes->register(FakeCollidingExecutableNode::class);

    expect(fn () => $triggers->register(FakeTriggerNode::class))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.fake_trigger')
        ->and(app(GraphTypeCatalog::class)->family('test.fake_trigger'))->toBe('executable');
});

it('prevents the same collision when the trigger node registers first', function () {
    $nodes = app(NodeRegistry::class);
    $triggers = app(TriggerNodeRegistry::class);

    $triggers->register(FakeTriggerNode::class);

    expect(fn () => $nodes->register(FakeCollidingExecutableNode::class))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.fake_trigger');
});

it('prevents an executable alias from claiming a registered trigger type', function () {
    $nodes = app(NodeRegistry::class);
    app(TriggerNodeRegistry::class)->register(FakeTriggerNode::class);
    $nodes->register(FakeSendNode::class);

    expect(fn () => $nodes->alias('test.fake_trigger', 'test.send'))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.fake_trigger')
        ->and($nodes->has('test.fake_trigger'))->toBeFalse();
});

it('prevents a trigger from claiming a registered executable alias', function () {
    $nodes = app(NodeRegistry::class);
    $nodes->register(FakeSendNode::class);
    $nodes->alias('test.fake_trigger', 'test.send');

    expect($nodes->resolve('test.fake_trigger'))->toBeInstanceOf(FakeSendNode::class)
        ->and(fn () => app(TriggerNodeRegistry::class)->register(FakeTriggerNode::class))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.fake_trigger');
});

it('rejects malformed public stable keys before registry state or listeners change', function (
    string $key,
    string $reason,
) {
    MutableStableKeyDriver::$key = $key;

    expect(fn () => app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class))
        ->toThrow(InvalidArgumentException::class, $reason)
        ->and(array_values(app(TriggerDriverRegistry::class)->all()))
        ->not->toContain(MutableStableKeyDriver::class);

    MutableStableKeyDriver::$key = 'test.mutable-driver';
    app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class);
    MutableStableKeySource::$key = $key;

    expect(fn () => app(TriggerSourceRegistry::class)->register(MutableStableKeySource::class))
        ->toThrow(InvalidArgumentException::class, $reason)
        ->and(app(TriggerSourceRegistry::class)->all())->toBe([])
        ->and(MutableStableKeyDriver::$sourceRegistrations)->toBe(0);

    MutableStableKeyTriggerNode::$type = $key;

    expect(fn () => app(TriggerNodeRegistry::class)->register(MutableStableKeyTriggerNode::class))
        ->toThrow(InvalidArgumentException::class, $reason)
        ->and(array_values(app(TriggerNodeRegistry::class)->all()))
        ->not->toContain(MutableStableKeyTriggerNode::class);

    MutableStableKeyExecutableNode::$type = $key;

    expect(fn () => app(NodeRegistry::class)->register(MutableStableKeyExecutableNode::class))
        ->toThrow(InvalidArgumentException::class, $reason)
        ->and(array_values(app(NodeRegistry::class)->all()))
        ->not->toContain(MutableStableKeyExecutableNode::class);
})->with([
    'numeric leading key' => ['1bad', 'must start with a lowercase letter'],
    'slash path ambiguity' => ['bad/key', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'percent path ambiguity' => ['bad%2fkey', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'whitespace' => ['bad key', 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'control character' => ["bad\nkey", 'only lowercase letters, digits, dots, underscores, and hyphens'],
    'invalid UTF-8' => ["bad\xFF", 'valid UTF-8'],
]);

it('rejects run-origin keys at the public trigger driver registry boundary', function (string $key) {
    MutableStableKeyDriver::$key = $key;

    expect(fn () => app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class))
        ->toThrow(InvalidArgumentException::class, $key)
        ->and(array_values(app(TriggerDriverRegistry::class)->all()))
        ->not->toContain(MutableStableKeyDriver::class)
        ->and(MutableStableKeyDriver::$sourceRegistrations)->toBe(0);
})->with(['manual', 'subflow']);

it('rejects malformed source driver keys before source registration side effects', function () {
    MutableStableKeySource::$driver = 'bad/driver';

    expect(fn () => app(TriggerSourceRegistry::class)->register(MutableStableKeySource::class))
        ->toThrow(InvalidArgumentException::class, 'trigger source driver key')
        ->and(app(TriggerSourceRegistry::class)->all())->toBe([])
        ->and(MutableStableKeyDriver::$sourceRegistrations)->toBe(0);
});

it('accepts stable keys at their storage boundaries', function () {
    MutableStableKeyDriver::$key = str_repeat('d', 191);
    MutableStableKeySource::$driver = MutableStableKeyDriver::$key;
    MutableStableKeySource::$key = str_repeat('s', 191);
    MutableStableKeyTriggerNode::$type = str_repeat('t', 255);
    MutableStableKeyExecutableNode::$type = str_repeat('e', 255);

    app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class);
    app(TriggerSourceRegistry::class)->register(MutableStableKeySource::class);
    app(TriggerNodeRegistry::class)->register(MutableStableKeyTriggerNode::class);
    app(NodeRegistry::class)->register(MutableStableKeyExecutableNode::class);

    expect(app(TriggerDriverRegistry::class)->has(str_repeat('d', 191)))->toBeTrue()
        ->and(app(TriggerSourceRegistry::class)->has(str_repeat('d', 191), str_repeat('s', 191)))->toBeTrue()
        ->and(app(TriggerNodeRegistry::class)->has(str_repeat('t', 255)))->toBeTrue()
        ->and(app(NodeRegistry::class)->has(str_repeat('e', 255)))->toBeTrue();
});

it('rejects stable keys beyond their storage boundaries', function () {
    MutableStableKeyDriver::$key = str_repeat('d', 192);

    expect(fn () => app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class))
        ->toThrow(InvalidArgumentException::class, '191');

    MutableStableKeyDriver::$key = 'test.mutable-driver';
    app(TriggerDriverRegistry::class)->register(MutableStableKeyDriver::class);
    MutableStableKeySource::$key = str_repeat('s', 192);

    expect(fn () => app(TriggerSourceRegistry::class)->register(MutableStableKeySource::class))
        ->toThrow(InvalidArgumentException::class, '191');

    MutableStableKeyTriggerNode::$type = str_repeat('t', 256);

    expect(fn () => app(TriggerNodeRegistry::class)->register(MutableStableKeyTriggerNode::class))
        ->toThrow(InvalidArgumentException::class, '255');

    MutableStableKeyExecutableNode::$type = str_repeat('e', 256);

    expect(fn () => app(NodeRegistry::class)->register(MutableStableKeyExecutableNode::class))
        ->toThrow(InvalidArgumentException::class, '255');
});

it('keeps trigger matches immutable and normalizes identifiers', function () {
    $empty = TriggerMatch::make();
    $matched = $empty->forTenant(42, 7, [5 => 1, 9 => '2'], ['source' => 'fake'], 'occ-1');

    expect($empty->tenants())->toBe([])
        ->and($matched->tenants())->toHaveCount(1)
        ->and($matched->tenants()[0]->tenantId)->toBe('42')
        ->and($matched->tenants()[0]->subjectType)->toBe('7')
        ->and(iterator_to_array($matched->tenants()[0]->subjectIds, false))->toBe(['1', '2']);
});

it('resolves occurrences through stable source configuration', function () {
    $match = (new FakeTriggerSource)->resolve(
        new TriggerOccurrence('test.fake', 'test.orders', [
            'tenant_id' => 42,
            'subject_id' => 9,
            'occurrence_id' => 'occ-9',
        ]),
        [],
    );

    expect($match->tenants()[0]->triggerData)->toBe(['occurrence' => 'occ-9'])
        ->and($match->tenants()[0]->occurrenceId)->toBe('occ-9');
});
