<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\NodeExecution;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $this->version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
});

function makeRun($version, string $status, int $daysAgo): Run
{
    $run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => $status,
    ]);

    $run->forceFill(['ended_at' => now()->subDays($daysAgo), 'created_at' => now()->subDays($daysAgo)])->save();

    RunSubject::create(['run_id' => $run->id, 'subject_type' => 'user', 'subject_id' => '1', 'status' => 'completed']);
    NodeExecution::create(['run_id' => $run->id, 'node_id' => 'n1', 'output' => 'default', 'subject_count' => 1]);

    return $run;
}

it('deletes terminal runs past the window with their subjects and executions', function () {
    makeRun($this->version, 'completed', 120);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(0)
        ->and(RunSubject::count())->toBe(0)
        ->and(NodeExecution::count())->toBe(0);
});

it('never deletes a live run regardless of age', function () {
    makeRun($this->version, 'waiting', 400);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('keeps runs inside the window', function () {
    makeRun($this->version, 'completed', 10);

    $this->artisan('nodeflow:prune', ['--days' => 90])->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('reports without deleting on a dry run', function () {
    makeRun($this->version, 'completed', 120);

    $this->artisan('nodeflow:prune', ['--days' => 90, '--dry-run' => true])
        ->expectsOutputToContain('1')
        ->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});
