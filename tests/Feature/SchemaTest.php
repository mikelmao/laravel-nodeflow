<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

it('persists a flow with an immutable version and a run', function () {
    $flow = Flow::create([
        'tenant_id' => 'org-1',
        'name' => 'Flood alert journey',
        'status' => 'draft',
    ]);

    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 1,
        'graph' => ['nodes' => [], 'edges' => []],
        'content_hash' => 'abc123',
    ]);

    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort',
        'status' => 'pending',
    ]);

    RunSubject::create([
        'run_id' => $run->id,
        'subject_type' => 'user',
        'subject_id' => '42',
        'status' => 'active',
    ]);

    expect($flow->versions)->toHaveCount(1)
        ->and($run->flowVersion->id)->toBe($version->id)
        ->and($run->subjects)->toHaveCount(1)
        ->and($version->graph)->toBe(['nodes' => [], 'edges' => []]);
});
