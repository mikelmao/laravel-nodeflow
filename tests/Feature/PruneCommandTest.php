<?php

use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\NodeExecution;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;

beforeEach(function () {
    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'F', 'status' => 'active']);
    $this->version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h']);
});

function makeRun($version, string $status, int $daysAgo): Run
{
    $run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
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
        ->expectsOutputToContain('Would delete 1 runs older than 90 days.')
        ->assertExitCode(0);

    expect(Run::withoutTenancy()->count())->toBe(1);
});

it('never prunes a blocked run at any age', function () {
    // PruneCommand::TERMINAL carries a nine-line comment explaining why 'blocked'
    // is excluded: a blocked run is recoverable once the missing node type is
    // re-registered, so pruning it destroys state a fix could still resume. That
    // reasoning had no test. Ten years old and still not pruned.
    $blocked = makeRun($this->version, 'blocked', 3650);

    $this->artisan('nodeflow:prune', ['--days' => 1])->assertExitCode(0);

    expect(Run::withoutTenancy()->whereKey($blocked->id)->exists())->toBeTrue()
        ->and(RunSubject::where('run_id', $blocked->id)->count())->toBe(1)
        ->and(NodeExecution::where('run_id', $blocked->id)->count())->toBe(1);
});

it('reports a blocked run as not deletable on a dry run', function () {
    makeRun($this->version, 'blocked', 3650);

    $this->artisan('nodeflow:prune', ['--days' => 1, '--dry-run' => true])
        ->expectsOutputToContain('Would delete 0 runs older than 1 days.')
        ->assertExitCode(0);
});
