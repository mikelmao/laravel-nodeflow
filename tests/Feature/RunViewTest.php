<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Nodeflow;
use Nodeflow\Workflows\FlowInterpreter;
use Workflow\V2\Events\WorkflowFailed;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string { return $this->test->tenant; }

        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());

    $this->user = new User;
    $this->user->id = 1;

    $this->flow = Flow::create(['name' => 'A', 'status' => 'active']);

    // The run's pinned version. 'pinned' is the node that proves the run view
    // read this graph and not another.
    $this->version = FlowVersion::create([
        'flow_id' => $this->flow->id, 'version' => 1, 'content_hash' => 'h1',
        'graph' => triggeredGraph([
            'start' => 'pinned',
            'nodes' => [['id' => 'pinned', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ]),
    ]);

    $this->run = Run::create([
        'flow_version_id' => $this->version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'running',
    ]);
});

function allowRunViewing(): void
{
    foreach (['viewAny', 'update', 'publish', 'runManually'] as $ability) {
        Gate::define("nodeflow.{$ability}", fn ($user, $subject = null) => true);
    }
}

function runPage($test, int $runId)
{
    // Inertia's own JSON response, which is what a real request produces.
    // Without the header Inertia renders a root Blade view Testbench does not
    // ship; with it, the response is a plain prop payload needing no view.
    return $test->actingAs($test->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => '', 'Accept' => 'application/json'])
        ->get("/nodeflow/runs/{$runId}");
}

it('denies the run view when the host has defined no gates', function () {
    // Plan 2's default-deny floor, over the run routes. Counterfactual: skip
    // authorize() in the controller and this returns 200.
    $this->actingAs($this->user)->get("/nodeflow/runs/{$this->run->id}")->assertForbidden();
});

it('four-oh-fours another tenants run rather than forbidding it', function () {
    // 403 would confirm the row exists. Counterfactual: look the run up with
    // Run::withoutTenancy() and this returns 200.
    allowRunViewing();

    $theirs = TenancyGuardSuspension::run(function () {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => 'org-2', 'name' => 'Theirs', 'status' => 'active',
        ]);
        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id, 'tenant_id' => 'org-2', 'version' => 1, 'content_hash' => 'h', 'graph' => ['start' => 'x', 'nodes' => [], 'edges' => []],
        ]);

        return Run::withoutTenancy()->create([
            'flow_version_id' => $version->id, 'tenant_id' => 'org-2',
            'started_via' => 'manual',
            'trigger_node_id' => 'trigger',
            'trigger_data' => null,
            'strategy' => 'cohort', 'status' => 'running',
        ]);
    });

    $this->actingAs($this->user)->get("/nodeflow/runs/{$theirs->id}")->assertNotFound();
});

/**
 * Trap 2 of the spec's three, half one.
 *
 * Only meaningful because the draft genuinely differs from the run's version:
 * a same-graph fixture passes while the bug is present. Counterfactual: render
 * `$flow->draft_graph ?? …` the way the *editor* correctly does and 'draftonly'
 * appears in a run that never executed it.
 */
it('renders the pinned version and not the flows draft', function () {
    allowRunViewing();

    $this->flow->update([
        'draft_graph' => [
            'start' => 'draftonly',
            'nodes' => [['id' => 'draftonly', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ],
        'draft_revision' => 1,
    ]);

    $nodeIds = collect(runPage($this, $this->run->id)->assertOk()->json('props.graph.nodes'))
        ->pluck('id');

    expect($nodeIds)->toContain('pinned')
        ->and($nodeIds)->not->toContain('draftonly');
});

/**
 * Trap 2, half two — a different wrong implementation with the same symptom.
 *
 * Counterfactual: read `$run->flowVersion->flow->currentVersion->graph`, or
 * `$flow->currentVersion->graph`, and a run still mid-wait on version 1 is
 * painted onto version 2's graph. D8's immutability exists exactly so this
 * cannot happen, and nothing else in the suite would catch it.
 */
it('renders the runs own version and not the flows newest published version', function () {
    allowRunViewing();

    $newer = FlowVersion::create([
        'flow_id' => $this->flow->id, 'version' => 2, 'content_hash' => 'h2',
        'graph' => triggeredGraph([
            'start' => 'newer',
            'nodes' => [['id' => 'newer', 'type' => 'core.exit', 'config' => []]],
            'edges' => [],
        ]),
    ]);
    $this->flow->update(['current_version_id' => $newer->id]);

    $response = runPage($this, $this->run->id)->assertOk();
    $nodeIds = collect($response->json('props.graph.nodes'))->pluck('id');

    expect($nodeIds)->toContain('pinned')
        ->and($nodeIds)->not->toContain('newer')
        ->and($response->json('props.run.version'))->toBe(1);
});

it('carries an overlay entry for every node in the pinned graph', function () {
    // Counterfactual: emit entries only for nodes with rows and the client has
    // to invent the never-reached state, which is the one state it must not
    // guess at.
    allowRunViewing();

    $overlay = runPage($this, $this->run->id)->assertOk()->json('props.overlay');

    expect(array_keys($overlay['nodes']))->toBe(['trigger', 'pinned'])
        ->and($overlay['nodes']['pinned']['reached'])->toBeFalse()
        ->and($overlay['terminal'])->toBeFalse();
});

it('exposes a projected workflow failure only through the run-level error prop', function () {
    allowRunViewing();

    Event::dispatch(new WorkflowFailed(
        instanceId: "nodeflow-run:{$this->run->id}",
        runId: 'durable-run-4',
        workflowType: 'class',
        workflowClass: FlowInterpreter::class,
        exceptionClass: RuntimeException::class,
        message: 'Yaya remained unavailable',
        committedAt: '2026-08-25T14:15:16+00:00',
    ));

    $response = runPage($this, $this->run->id)->assertOk();
    $run = $response->json('props.run');
    $overlay = $response->json('props.overlay');

    expect($run['status'])->toBe('failed')
        ->and($run['terminal'])->toBeTrue()
        ->and($run['error'])->toBe(RuntimeException::class.': Yaya remained unavailable')
        ->and(array_keys($overlay))->toBe(['status', 'terminal', 'nodes'])
        ->and($overlay['status'])->toBe('failed')
        ->and($overlay['terminal'])->toBeTrue()
        ->and($overlay['nodes']['pinned']['failed'])->toBe(0)
        ->and($overlay['nodes']['pinned']['error'])->toBeNull();
});

it('supplies discriminated executable and trigger definitions for the pinned graph', function () {
    allowRunViewing();

    $palette = collect(runPage($this, $this->run->id)->assertOk()->json('props.palette'));

    expect($palette->firstWhere('type', 'core.exit')['kind'])->toBe('executable')
        ->and($palette->firstWhere('type', 'test.fake_trigger'))
        ->toMatchArray([
            'kind' => 'trigger',
            'driver' => 'test.fake',
            'outputs' => ['started'],
            'default_config' => ['source' => 'test.orders'],
        ]);
});

it('exposes safe origin fields without leaking execution-only trigger data', function () {
    allowRunViewing();
    $this->run->update([
        'started_via' => 'test.fake',
        'trigger_data' => [
            'delivery' => 'd-1',
            'nested' => ['authorization' => 'Bearer secret-token'],
            'serialized' => json_encode(['private' => ['account' => 42]], JSON_THROW_ON_ERROR),
        ],
    ]);
    DB::table('nodeflow_runs')->where('id', $this->run->id)->update([
        'engine_entry_node_id' => 'private-entry-node',
        'engine_dispatch_status' => 'failed',
        'engine_dispatch_error' => 'private dispatch infrastructure detail',
    ]);

    $response = runPage($this, $this->run->id)->assertOk();
    $run = $response->json('props.run');

    expect($run['started_via'])->toBe('test.fake')
        ->and($run['trigger_node_id'])->toBe('trigger')
        ->and($run)->not->toHaveKeys([
            'trigger_data',
            'idempotency_key',
            'engine_workflow_id',
            'engine_entry_node_id',
            'engine_dispatch_status',
            'engine_dispatch_error',
        ])
        ->and($response->getContent())->not->toContain(
            'private-entry-node',
            'private dispatch infrastructure detail',
            'engine_dispatch_status',
        );
});

it('serves urls whose node sentinel survives route generation', function () {
    // E4: the client substitutes into these, so both the sentinel and the
    // host's chosen prefix must arrive intact. Counterfactual: build the URL by
    // string concatenation in the client and a host prefix breaks every run
    // view in the field with no test failing here.
    allowRunViewing();

    $urls = runPage($this, $this->run->id)->assertOk()->json('props.urls');

    expect($urls['overlay'])->toBe("http://localhost/nodeflow/runs/{$this->run->id}/overlay")
        ->and($urls['subjects'])
        ->toBe("http://localhost/nodeflow/runs/{$this->run->id}/nodes/__NODEFLOW_NODE__/subjects");
});
