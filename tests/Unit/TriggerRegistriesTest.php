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
use Tests\Support\FakeTriggerDriver;
use Tests\Support\FakeTriggerNode;
use Tests\Support\FakeTriggerSource;

it('registers extensions under stable graph driver and source keys', function () {
    Nodeflow::registerTriggerDrivers([FakeTriggerDriver::class]);
    Nodeflow::registerTriggerNodes([FakeTriggerNode::class]);
    Nodeflow::registerTriggerSources([FakeTriggerSource::class]);

    $driver = app(TriggerDriverRegistry::class)->resolve('test.fake');
    $node = app(TriggerNodeRegistry::class)->resolve('test.trigger');
    $source = app(TriggerSourceRegistry::class)->resolve('test.fake', 'test.source');
    $descriptor = $node->compile(['source' => 'test.source', 'account' => 'primary']);

    expect($driver)->toBeInstanceOf(FakeTriggerDriver::class)
        ->and($node)->toBeInstanceOf(FakeTriggerNode::class)
        ->and($source)->toBeInstanceOf(FakeTriggerSource::class)
        ->and($descriptor->toArray())->toBe([
            'driver' => 'test.fake',
            'source' => 'test.source',
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

it('refuses to register a source before its driver', function () {
    expect(fn () => Nodeflow::registerTriggerSources([FakeTriggerSource::class]))
        ->toThrow(InvalidArgumentException::class, 'test.fake');
});

it('prevents executable and trigger nodes claiming the same stable graph type', function () {
    $nodes = app(NodeRegistry::class);
    $triggers = app(TriggerNodeRegistry::class);

    $nodes->register(FakeCollidingExecutableNode::class);

    expect(fn () => $triggers->register(FakeTriggerNode::class))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.trigger')
        ->and(app(GraphTypeCatalog::class)->family('test.trigger'))->toBe('executable');
});

it('prevents the same collision when the trigger node registers first', function () {
    $nodes = app(NodeRegistry::class);
    $triggers = app(TriggerNodeRegistry::class);

    $triggers->register(FakeTriggerNode::class);

    expect(fn () => $nodes->register(FakeCollidingExecutableNode::class))
        ->toThrow(InvalidGraphTypeRegistration::class, 'test.trigger');
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
        new TriggerOccurrence('test.fake', 'test.source', [
            'tenant_id' => 42,
            'subject_id' => 9,
            'occurrence_id' => 'occ-9',
        ]),
        [],
    );

    expect($match->tenants()[0]->triggerData)->toBe(['occurrence' => 'occ-9'])
        ->and($match->tenants()[0]->occurrenceId)->toBe('occ-9');
});
