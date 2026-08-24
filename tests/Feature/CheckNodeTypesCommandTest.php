<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Console\CheckNodeTypesResolver;
use Nodeflow\Nodes\NodeRegistry;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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

it('skips trigger-family requirements for manual and subflow runs while still checking downstream executables', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Manual pinned', 'status' => 'draft']);
    $graph = healthTriggerGraph('gone.start');
    $graph['nodes'][] = ['id' => 'work', 'type' => 'gone.downstream', 'config' => []];
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'manual-pinned', 'graph' => $graph,
    ]);
    foreach (['manual', 'subflow'] as $origin) {
        Run::create([
            'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => $origin,
            'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
        ]);
    }

    Artisan::call('nodeflow:check-node-types');
    expect(Artisan::output())
        ->toContain("flow {$flow->id} version {$version->id} node work missing executable node type gone.downstream")
        ->not->toContain('gone.start')
        ->not->toContain('node trigger missing executable');
});

it('requires trigger components when mixed live origins include a trigger run', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Mixed pinned', 'status' => 'draft']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'tenant_id' => 'org-1', 'version' => 1, 'content_hash' => 'mixed-pinned',
        'graph' => healthTriggerGraph('gone.mixed_start'),
    ]);
    foreach (['manual', 'trigger'] as $origin) {
        Run::create([
            'flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => $origin,
            'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
        ]);
    }

    Artisan::call('nodeflow:check-node-types');
    expect(Artisan::output())->toContain("flow {$flow->id} version {$version->id} node trigger missing trigger node type gone.mixed_start");
});

it('uses a bounded tenant-neutral query set and never scans inactive historical versions', function () {
    for ($i = 0; $i < 20; $i++) {
        $flow = Flow::create(['tenant_id' => $i % 2 ? 'org-2' : 'org-1', 'name' => "Inactive {$i}", 'status' => 'draft']);
        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id, 'tenant_id' => $flow->tenant_id, 'version' => 1,
            'content_hash' => "inactive-{$i}", 'graph' => healthTriggerGraph('gone.inactive.'.$i),
        ]);
        Run::withoutTenancy()->create([
            'flow_version_id' => $version->id, 'tenant_id' => $flow->tenant_id, 'started_via' => 'trigger',
            'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'completed',
        ]);
    }
    $liveFlow = Flow::withoutTenancy()->create(['tenant_id' => 'org-2', 'name' => 'Live other tenant', 'status' => 'draft']);
    $liveVersion = FlowVersion::withoutTenancy()->create([
        'flow_id' => $liveFlow->id, 'tenant_id' => 'org-2', 'version' => 1,
        'content_hash' => 'live-other', 'graph' => healthTriggerGraph(source: 'gone.other_tenant'),
    ]);
    Run::withoutTenancy()->create([
        'flow_version_id' => $liveVersion->id, 'tenant_id' => 'org-2', 'started_via' => 'trigger',
        'trigger_node_id' => 'trigger', 'trigger_data' => [], 'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) $queries++;
    });
    $missing = CheckNodeTypesResolver::findMissingTypes(app(NodeRegistry::class));

    expect($queries)->toBeLessThanOrEqual(4)
        ->and($missing)->toHaveCount(1)
        ->and($missing[0])->toContain("flow {$liveFlow->id} version {$liveVersion->id}")
        ->toContain('gone.other_tenant');
});

it('chunks thousands of live versions and never builds an oversized IN clause', function () {
    $flowId = DB::table('nodeflow_flows')->insertGetId([
        'tenant_id' => 'org-scale', 'name' => 'Scale', 'status' => 'draft', 'reentry_policy' => 'reenter',
        'draft_revision' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $graphValue = healthTriggerGraph();
    $graphValue['nodes'][] = ['id' => 'work', 'type' => 'gone.scale', 'config' => []];
    $graph = json_encode($graphValue);
    foreach (array_chunk(range(1, 1001), 100) as $versions) {
        DB::table('nodeflow_flow_versions')->insert(array_map(static fn (int $version): array => [
            'tenant_id' => 'org-scale', 'flow_id' => $flowId, 'version' => $version,
            'graph' => $graph, 'content_hash' => 'scale-'.$version, 'created_at' => now(), 'updated_at' => now(),
        ], $versions));
    }
    DB::table('nodeflow_flow_versions')->where('flow_id', $flowId)->orderBy('id')->pluck('id')
        ->chunk(100)
        ->each(function ($ids): void {
            DB::table('nodeflow_runs')->insert($ids->map(static fn (int $id): array => [
                'flow_version_id' => $id, 'tenant_id' => 'org-scale', 'strategy' => 'cohort',
                'status' => 'waiting', 'is_test' => false, 'started_via' => 'manual',
                'trigger_node_id' => 'trigger', 'trigger_data' => '[]', 'steps_taken' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ])->all());
        });

    $largestIn = 0;
    $versionBatchQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$largestIn, &$versionBatchQueries): void {
        preg_match_all('/\bin\s*\(([^)]*)\)/i', $query->sql, $matches);
        foreach ($matches[1] ?? [] as $placeholders) {
            $largestIn = max($largestIn, substr_count($placeholders, '?'));
        }
        if (str_contains($query->sql, 'from "nodeflow_flow_versions"') && str_contains(strtolower($query->sql), ' in ')) {
            $versionBatchQueries++;
        }
    });

    $missing = CheckNodeTypesResolver::findMissingTypes(app(NodeRegistry::class));

    expect($largestIn)->toBeLessThanOrEqual(CheckNodeTypesResolver::QUERY_CHUNK_SIZE)
        ->and($versionBatchQueries)->toBeGreaterThan(1)
        ->and($missing)->toHaveCount(1001)
        ->and($missing[0])->toContain('missing executable node type')
        ->and($missing[1000])->toContain('missing executable node type');
});
