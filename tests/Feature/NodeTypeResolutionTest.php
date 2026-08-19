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

it('logs error when enabled with unresolvable types', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    // Deliberately push the version under test off row 1. The old assertion
    // matched the literal string 'version 1', which was only correct because the
    // fixture happened to be the first row — it could not tell the reported id
    // from a hardcoded one. With a decoy row ahead of it, the id is 2 and the
    // assertion has to be about the real value.
    FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'decoy',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]], 'edges' => []],
    ]);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 2, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'boot.unresolvable', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    config(['nodeflow.check_node_types_on_boot' => true]);
    NodeflowServiceProvider::resetNodeTypeCheckForTesting();

    // Assert the flow version's actual id, not the literal 'version 1'. The
    // resolver reports "version {id}", and matching 'version 1' was only correct
    // because this fixture happens to be the first row in a fresh database — it
    // would have passed against a resolver that hardcoded the string, and would
    // silently start passing for the wrong reason on id 10, 100, 1000.
    $versionId = $version->id;

    expect($versionId)->toBeGreaterThan(1);

    Log::shouldReceive('error')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'Unresolvable nodeflow type')
            && str_contains($message, 'boot.unresolvable')
            && str_contains($message, "version {$versionId} ")
            && ! str_contains($message, 'version 1 '));

    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();
});

it('does not log error when boot check is disabled', function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);

    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1, 'content_hash' => 'h',
        'graph' => ['start' => 'n1', 'nodes' => [['id' => 'n1', 'type' => 'boot.disabled.type', 'config' => []]], 'edges' => []],
    ]);

    Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'waiting']);

    config(['nodeflow.check_node_types_on_boot' => false]);
    NodeflowServiceProvider::resetNodeTypeCheckForTesting();

    Log::spy();

    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();

    Log::shouldNotHaveReceived('error', fn ($message) => str_contains($message, 'Unresolvable nodeflow type'));
});

it('logs warning when boot check encounters exception', function () {
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

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn ($message) => str_contains($message, 'Could not verify nodeflow node types at boot'));

    $provider = new NodeflowServiceProvider(app());
    $provider->checkNodeTypesOnBoot();
});
