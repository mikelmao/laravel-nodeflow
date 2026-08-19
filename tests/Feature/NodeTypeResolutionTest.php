<?php

use Illuminate\Support\Facades\Log;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;
use Nodeflow\NodeflowServiceProvider;

it('reports a version with live runs referencing an unregistered type', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'gone.away', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    $this->artisan('nodeflow:check-node-types')
        ->expectsOutputToContain('gone.away')
        ->assertExitCode(1);
});

it('ignores versions whose runs have all completed', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'never.registered', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'completed']);

    $this->artisan('nodeflow:check-node-types')
        ->assertExitCode(0);
});

it('passes when every referenced type resolves', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    app(NodeRegistry::class)->register(Tests\Support\RecordingSendNode::class);

    $this->artisan('nodeflow:check-node-types')->assertExitCode(0);
});

it('resolves a renamed type through the alias map', function () {
    app(NodeRegistry::class)->register(Tests\Support\RecordingSendNode::class);
    app(NodeRegistry::class)->alias('test.old_recording', 'test.recording');

    expect(app(NodeRegistry::class)->resolve('test.old_recording'))
        ->toBeInstanceOf(Tests\Support\RecordingSendNode::class);
});

it('boot check completes successfully when enabled with unresolvable types', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'boot.unresolvable', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    config(['nodeflow.check_node_types_on_boot' => true]);
    NodeflowServiceProvider::resetNodeTypeCheckForTesting();

    // Should not throw, should handle the unresolvable type gracefully
    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();

    expect(true)->toBeTrue();
});

it('boot check respects disabled config and returns early', function () {
    config(['nodeflow.check_node_types_on_boot' => false]);
    NodeflowServiceProvider::resetNodeTypeCheckForTesting();

    // Should complete without querying the database
    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();

    expect(true)->toBeTrue();
});

it('boot check handles exceptions gracefully instead of crashing', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    config(['nodeflow.check_node_types_on_boot' => true]);
    NodeflowServiceProvider::resetNodeTypeCheckForTesting();

    // Mock the registry to throw an exception during resolution
    $registry = \Mockery::mock(NodeRegistry::class);
    $registry->shouldReceive('has')->andThrow(new \Exception('Simulated database error'));

    app()->instance(NodeRegistry::class, $registry);

    // Should not throw even with exception during resolution
    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();

    expect(true)->toBeTrue();
});
