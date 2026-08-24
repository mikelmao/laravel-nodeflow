<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\TriggerActivation;
use Illuminate\Support\Facades\Artisan;

function healthTriggerGraph(string $type = 'test.fake_trigger', string $source = 'test.orders'): array
{
    return [
        'start' => 'trigger',
        'nodes' => [
            ['id' => 'trigger', 'type' => $type, 'config' => ['source' => $source]],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'trigger', 'to' => 'exit', 'output' => 'started']],
    ];
}

function healthActivation(string $status, array $graph, string $driver, string $source): array
{
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => uniqid('health-', true), 'status' => $status]);
    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'tenant_id' => 'org-1',
        'version' => 1,
        'content_hash' => hash('sha256', json_encode($graph)),
        'graph' => $graph,
    ]);
    TriggerActivation::withoutTenancy()->create([
        'flow_id' => $flow->id,
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'driver' => $driver,
        'source' => $source,
        'qualifier' => null,
        'trigger_node_id' => 'trigger',
        'descriptor' => [],
    ]);

    return [$flow, $version];
}

it('reports each missing component of an active activation with exact identity and remediation', function () {
    [$flow, $version] = healthActivation('active', healthTriggerGraph('gone.trigger'), 'gone.driver', 'gone.source');

    $exit = Artisan::call('nodeflow:check-node-types');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain("flow {$flow->id} version {$version->id} node trigger")
        ->toContain('trigger node type gone.trigger')
        ->toContain('trigger driver gone.driver')
        ->toContain('trigger source gone.driver:gone.source')
        ->toContain('Nodeflow::registerTriggerNodes')
        ->toContain('Nodeflow::registerTriggerDrivers')
        ->toContain('Nodeflow::registerTriggerSources');
});

it('checks trigger components derived from historical versions pinned by live runs', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Pinned', 'status' => 'draft']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'pinned',
        'graph' => healthTriggerGraph(source: 'gone.historical_source'),
    ]);
    Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => 'trigger',
        'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    $exit = Artisan::call('nodeflow:check-node-types');
    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("flow {$flow->id} version {$version->id} node trigger")
        ->toContain('trigger source test.fake:gone.historical_source');
});

it('includes flow identity when a tenant-neutral live run pins a missing executable type', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Pinned executable', 'status' => 'draft']);
    $graph = healthTriggerGraph();
    $graph['nodes'][] = ['id' => 'work', 'type' => 'gone.executable', 'config' => []];
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'missing-executable',
        'graph' => $graph,
    ]);
    Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => 'trigger',
        'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    $exit = Artisan::call('nodeflow:check-node-types');
    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("flow {$flow->id} version {$version->id} node work missing executable node type gone.executable")
        ->toContain("NodeRegistry::alias('gone.executable', 'canonical.type')");
});

it('does not flag inactive activations drafts or completed-only historical versions', function () {
    healthActivation('draft', healthTriggerGraph('gone.inactive'), 'gone.driver', 'gone.source');

    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Complete', 'status' => 'draft']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'complete',
        'graph' => healthTriggerGraph('gone.completed'),
    ]);
    Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => 'trigger',
        'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'completed',
    ]);

    $this->artisan('nodeflow:check-node-types')->assertExitCode(0);
});

it('reports malformed active graph state as a useful failure without crashing', function () {
    [$flow, $version] = healthActivation('active', ['nodes' => 'not-an-array'], 'test.fake', 'test.orders');

    $exit = Artisan::call('nodeflow:check-node-types');
    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("flow {$flow->id} version {$version->id} node trigger")
        ->toContain('malformed trigger graph');
});

it('reports a live graph whose pinned trigger identity points at an executable node', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Wrong family', 'status' => 'draft']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'wrong-family',
        'graph' => [
            'start' => 'trigger',
            'nodes' => [['id' => 'trigger', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ],
    ]);
    Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => 'trigger',
        'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    $exit = Artisan::call('nodeflow:check-node-types');
    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain("flow {$flow->id} version {$version->id} node trigger")
        ->toContain('malformed trigger graph');
});

it('passes when active and live trigger components all resolve', function () {
    healthActivation('active', healthTriggerGraph(), 'test.fake', 'test.orders');

    $this->artisan('nodeflow:check-node-types')->assertExitCode(0);
});
