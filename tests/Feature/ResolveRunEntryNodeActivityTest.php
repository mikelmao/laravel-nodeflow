<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\Activities\ResolveRunEntryNodeActivity;

function runForEntryResolution(array $graph): Run
{
    return TenancyGuardSuspension::run(function () use ($graph) {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => 'org-1',
            'name' => 'Legacy workflow',
            'status' => 'active',
        ]);
        $version = FlowVersion::withoutTenancy()->create([
            'tenant_id' => 'org-1',
            'flow_id' => $flow->id,
            'version' => 1,
            'graph' => $graph,
            'content_hash' => hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)),
        ]);

        return Run::withoutTenancy()->create([
            'tenant_id' => 'org-1',
            'flow_version_id' => $version->id,
            'started_via' => 'manual',
            'trigger_node_id' => $graph['start'],
            'trigger_data' => null,
            'strategy' => 'subject',
            'status' => 'pending',
        ]);
    });
}

it('resolves the executable target from a pinned trigger graph', function () {
    $run = runForEntryResolution(triggeredExitGraph());

    expect(app(ResolveRunEntryNodeActivity::class)->handle($run->id))->toBe('first-action');
});

it('preserves the start of a genuine executable-only legacy graph', function () {
    $run = runForEntryResolution([
        'start' => 'legacy-start',
        'nodes' => [['id' => 'legacy-start', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    expect(app(ResolveRunEntryNodeActivity::class)->handle($run->id))->toBe('legacy-start');
});

it('rejects a corrupted run and version tenant tuple', function () {
    $run = runForEntryResolution(triggeredExitGraph());
    DB::table('nodeflow_runs')->where('id', $run->id)->update(['tenant_id' => 'org-2']);

    expect(fn () => app(ResolveRunEntryNodeActivity::class)->handle($run->id))
        ->toThrow(CrossTenantExecutionException::class, "Run [{$run->id}]");
});

it('fails consistently when the pinned version is missing', function () {
    $run = runForEntryResolution(triggeredExitGraph());
    DB::table('nodeflow_runs')->where('id', $run->id)->update(['flow_version_id' => 999999]);

    expect(fn () => app(ResolveRunEntryNodeActivity::class)->handle($run->id))
        ->toThrow(ModelNotFoundException::class, 'FlowVersion');
});

it('rejects a graph whose start is neither a registered trigger nor executable node', function () {
    $run = runForEntryResolution([
        'start' => 'unknown-start',
        'nodes' => [['id' => 'unknown-start', 'type' => 'missing.type', 'config' => []]],
        'edges' => [],
    ]);

    expect(fn () => app(ResolveRunEntryNodeActivity::class)->handle($run->id))
        ->toThrow(\RuntimeException::class, 'must be a registered trigger or executable node');
});
