<?php

use Nodeflow\Graph\GraphTypeCatalog;
use Nodeflow\Graph\InvalidGraphTypeRegistration;
use Nodeflow\Nodeflow;
use Nodeflow\Nodes\NodeRegistry;
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

beforeEach(function () {
    FakeTriggerDriver::$onSourceRegistered = null;
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

it('keeps trigger matches immutable and normalizes identifiers', function () {
    $empty = TriggerMatch::make();
    $matched = $empty->forTenant(42, 7, [5 => 1, 9 => '2'], ['source' => 'fake'], 'occ-1');

    expect($empty->tenants())->toBe([])
        ->and($matched->tenants())->toHaveCount(1)
        ->and($matched->tenants()[0]->tenantId)->toBe('42')
        ->and($matched->tenants()[0]->subjectType)->toBe('7')
        ->and($matched->tenants()[0]->subjectIds)->toBe(['1', '2']);
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
