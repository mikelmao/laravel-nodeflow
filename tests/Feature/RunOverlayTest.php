<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\NodeExecution;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Nodeflow\Runs\RunOverlay;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string { return 'org-1'; }

        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());
    $this->user = new User;
    $this->user->id = 1;

    // Four nodes, deliberately one of each kind the overlay must tell apart:
    //   sent    — reached, released 2 subjects down 'sent'
    //   segment — reached, released NOBODY down 'unmatched' (a zero-count row)
    //   parked  — never executed, but 3 subjects are sitting on it right now
    //   nobody  — never touched at all
    $this->graph = Graph::fromArray([
        'start' => 'sent',
        'nodes' => [
            ['id' => 'sent', 'type' => 'core.exit', 'config' => []],
            ['id' => 'segment', 'type' => 'core.exit', 'config' => []],
            ['id' => 'parked', 'type' => 'core.exit', 'config' => []],
            ['id' => 'nobody', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [],
    ]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => $this->graph->toArray(), 'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'running',
    ]);

    NodeExecution::create(['run_id' => $this->run->id, 'node_id' => 'sent', 'output' => 'sent', 'subject_count' => 2]);
    NodeExecution::create(['run_id' => $this->run->id, 'node_id' => 'segment', 'output' => 'unmatched', 'subject_count' => 0]);

    foreach (['1', '2', '3'] as $id) {
        RunSubject::create([
            'run_id' => $this->run->id, 'subject_type' => 'user',
            'subject_id' => $id, 'current_node_id' => 'parked', 'status' => 'active',
        ]);
    }

    $this->snapshot = fn () => app(RunOverlay::class)->snapshot($this->run->fresh(), $this->graph);
});

/**
 * The counterfactual this test exists to kill: `reached = array_sum($byOutput) > 0`.
 *
 * That implementation passes any test asserting both nodes render "0", because
 * both DO render 0 — 'segment' released nobody and 'nobody' was never touched.
 * The only thing that separates them is `reached`, so this asserts the two
 * differ in that field rather than agreeing on their counts. A fixture with
 * only one of the two kinds cannot detect the collapse at all.
 */
it('distinguishes a never-reached node from a node reached with zero subjects', function () {
    $nodes = (array) ($this->snapshot)()['nodes'];

    expect($nodes['segment']['reached'])->toBeTrue()
        ->and((array) $nodes['segment']['byOutput'])->toBe(['unmatched' => 0])
        ->and($nodes['nobody']['reached'])->toBeFalse()
        ->and((array) $nodes['nobody']['byOutput'])->toBe([])
        // The distinction is in `reached`, and the counts agree — which is
        // precisely why `reached` cannot be derived from them.
        ->and(array_sum((array) $nodes['segment']['byOutput']))
        ->toBe(array_sum((array) $nodes['nobody']['byOutput']));
});

/**
 * E13's second half. Counterfactual: derive `reached` from execution rows only,
 * and the node holding the entire audience mid-wait renders dimmed with no
 * badge — the single most important state this whole view exists to display.
 */
it('reaches a node that has no execution row but is holding active subjects', function () {
    $nodes = (array) ($this->snapshot)()['nodes'];

    expect($nodes['parked']['reached'])->toBeTrue()
        ->and($nodes['parked']['waiting'])->toBe(3)
        ->and((array) $nodes['parked']['byOutput'])->toBe([]);
});

it('sums subject_count per output across two visits to the same node', function () {
    // A diamond graph can run one node twice. Counterfactual: take the last row
    // per (node, output) instead of SUM() and the second visit erases the first.
    NodeExecution::create(['run_id' => $this->run->id, 'node_id' => 'sent', 'output' => 'sent', 'subject_count' => 5]);
    NodeExecution::create(['run_id' => $this->run->id, 'node_id' => 'sent', 'output' => 'failed', 'subject_count' => 1]);

    $nodes = (array) ($this->snapshot)()['nodes'];

    expect((array) $nodes['sent']['byOutput'])->toBe(['sent' => 7, 'failed' => 1]);
});

it('counts only active subjects as waiting', function () {
    // Counterfactual: drop the status filter and a subject that already failed
    // at this node is reported as still waiting there.
    RunSubject::create([
        'run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => '9',
        'current_node_id' => 'parked', 'status' => 'failed',
    ]);

    expect(((array) ($this->snapshot)()['nodes'])['parked']['waiting'])->toBe(3);
});

it('reports failures and one representative error from the null-output rows', function () {
    // NodeRunner::advance() writes failures as one row with output = null and
    // the messages joined into `error`. Counterfactual: fold null-output rows
    // into byOutput and the failure count appears as an output the node never
    // declared, which GraphValidator would never have accepted.
    NodeExecution::create([
        'run_id' => $this->run->id, 'node_id' => 'sent', 'output' => null,
        'subject_count' => 4, 'error' => 'TimeoutException: gateway did not answer',
    ]);

    $nodes = (array) ($this->snapshot)()['nodes'];

    expect($nodes['sent']['failed'])->toBe(4)
        ->and($nodes['sent']['error'])->toBe('TimeoutException: gateway did not answer')
        ->and((array) $nodes['sent']['byOutput'])->toBe(['sent' => 2])
        ->and($nodes['segment']['error'])->toBeNull();
});

it('ignores execution rows for a node id that is not in the pinned graph', function () {
    // A row can name a node that a later version removed. Counterfactual: key
    // the overlay off the rows instead of the graph and the client receives an
    // entry for a node it cannot draw.
    NodeExecution::create(['run_id' => $this->run->id, 'node_id' => 'ghost', 'output' => 'default', 'subject_count' => 9]);

    expect(array_keys((array) ($this->snapshot)()['nodes']))
        ->toBe(['sent', 'segment', 'parked', 'nobody']);
});

it('marks only a completed run terminal', function () {
    // E17 and C-1: 'completed' is the only status the engine ever writes as an
    // end state. Counterfactual: treat 'running' as terminal and polling stops
    // on the first response, so the view never updates.
    expect(($this->snapshot)()['terminal'])->toBeFalse();

    $this->run->update(['status' => 'completed']);

    expect(($this->snapshot)()['terminal'])->toBeTrue()
        ->and(($this->snapshot)()['status'])->toBe('completed');
});

it('aggregates with exactly two queries regardless of how many nodes the graph has', function () {
    // The D4/D11 payoff. Counterfactual: loop the graph's nodes issuing a count
    // per node and this is 4 queries here and 400 on a real graph.
    $this->run->fresh();
    $queries = [];
    DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });

    app(RunOverlay::class)->snapshot($this->run, $this->graph);

    expect($queries)->toHaveCount(2);
});

it('denies the overlay endpoint when the host has defined no gates', function () {
    // Counterfactual: omit authorize() from the polling endpoint and a run's
    // live counts are readable by anyone who can guess an id — the page having
    // been authorized says nothing about the endpoint the page polls.
    $this->actingAs($this->user)->getJson("/nodeflow/runs/{$this->run->id}/overlay")->assertForbidden();
});

it('four-oh-fours another tenants overlay rather than forbidding it', function () {
    Gate::define('nodeflow.viewAny', fn ($user, $subject = null) => true);

    $theirs = TenancyGuardSuspension::run(function () {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => 'org-2', 'name' => 'T', 'trigger_type' => 'manual', 'status' => 'active',
        ]);
        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id, 'tenant_id' => 'org-2', 'version' => 1, 'content_hash' => 'h',
            'graph' => ['start' => 'x', 'nodes' => [], 'edges' => []],
        ]);

        return Run::withoutTenancy()->create([
            'flow_version_id' => $version->id, 'tenant_id' => 'org-2',
            'strategy' => 'cohort', 'status' => 'running',
        ]);
    });

    $this->actingAs($this->user)->getJson("/nodeflow/runs/{$theirs->id}/overlay")->assertNotFound();
});

it('returns the snapshot alone, with no graph or palette to re-send on every poll', function () {
    // Counterfactual: reuse the page's prop array here and every 5-second poll
    // ships the whole graph and the entire node palette. Nothing else in the
    // suite would notice; the client would still work, just expensively.
    Gate::define('nodeflow.viewAny', fn ($user, $subject = null) => true);

    $body = $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/overlay")
        ->assertOk()
        ->json();

    expect(array_keys($body))->toBe(['status', 'terminal', 'nodes'])
        ->and($body['nodes']['parked']['waiting'])->toBe(3);
});
