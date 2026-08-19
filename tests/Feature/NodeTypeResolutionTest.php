<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodes\NodeRegistry;

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

it('passes when every referenced type resolves', function () {
    $this->artisan('nodeflow:check-node-types')->assertExitCode(0);
});

it('resolves a renamed type through the alias map', function () {
    app(NodeRegistry::class)->register(Tests\Support\RecordingSendNode::class);
    app(NodeRegistry::class)->alias('test.old_recording', 'test.recording');

    expect(app(NodeRegistry::class)->resolve('test.old_recording'))
        ->toBeInstanceOf(Tests\Support\RecordingSendNode::class);
});
