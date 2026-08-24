<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Nodeflow\Runs\RunSubjects;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string { return 'org-1'; }

        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());
    $this->user = new User;
    $this->user->id = 1;

    $flow = Flow::create(['name' => 'F', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => triggeredGraph(['start' => 'wait', 'nodes' => [['id' => 'wait', 'type' => 'core.exit', 'config' => []]], 'edges' => []]),
        'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'waiting',
    ]);

    foreach (['1', '2', '3', '4', '5'] as $id) {
        RunSubject::create([
            'run_id' => $this->run->id, 'subject_type' => 'user',
            'subject_id' => $id, 'current_node_id' => 'wait', 'status' => 'active',
        ]);
    }
});

it('lists the subjects at a node one page at a time', function () {
    // Counterfactual: return every row and a six-figure node serialises 100k
    // subjects into one response.
    config(['nodeflow.limits.subject_page' => 2]);

    $page = app(RunSubjects::class)->atNode($this->run, 'wait');

    expect($page['data'])->toHaveCount(2)
        ->and(array_column($page['data'], 'subject_id'))->toBe(['1', '2'])
        ->and($page['next_cursor'])->not->toBeNull()
        ->and($page['data'][0]['status'])->toBe('active')
        ->and($page['data'][0]['current_node_id'])->toBe('wait');
});

it('walks the whole population through its cursor without repeating or skipping a subject', function () {
    // Counterfactual: paginate with offset and a subject that leaves the node
    // mid-walk shifts the window, silently skipping whoever moved into the gap.
    config(['nodeflow.limits.subject_page' => 2]);

    $seen = [];
    $cursor = null;

    do {
        $page = app(RunSubjects::class)->atNode($this->run, 'wait', $cursor);
        $seen = array_merge($seen, array_column($page['data'], 'subject_id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    expect($seen)->toBe(['1', '2', '3', '4', '5']);
});

/**
 * The counterfactual the previous test's comment names but does not kill: an
 * OFFSET/LIMIT walk against this fixture's *static* five rows happens to
 * return the identical sequence a cursor does, so that test would pass
 * unchanged even if `atNode()` paginated by offset. It proves the walk
 * terminates and covers the set; it says nothing about offset vs. cursor,
 * which is the entire reason E15 chose a cursor.
 *
 * This test makes the population move mid-walk. Subject '1' — already
 * returned on page 1 — leaves the node the same way every terminal
 * transition does (`current_node_id` nulled). Offset pagination re-evaluates
 * `OFFSET 2 LIMIT 2` against the now-four-row population for page 2: the
 * window shifts down by one, so it would return ['4', '5'] and silently skip
 * '3', who moved into the gap the departure left behind. Cursor pagination
 * here is keyed on `id` (`->orderBy('id')->cursorPaginate(...)`), a strictly
 * increasing column no departure renumbers, so removing an earlier row cannot
 * shift a later one's position — page 2 must still return '3' unharmed.
 */
it('does not skip a subject who leaves the node mid-walk, unlike an offset would', function () {
    config(['nodeflow.limits.subject_page' => 2]);

    $page1 = app(RunSubjects::class)->atNode($this->run, 'wait');
    expect(array_column($page1['data'], 'subject_id'))->toBe(['1', '2']);

    // The mid-walk departure: subject '1', already counted on page 1, leaves
    // the node between page 1 and page 2 — exactly what a terminal transition
    // does to current_node_id.
    RunSubject::where('run_id', $this->run->id)
        ->where('subject_id', '1')
        ->update(['current_node_id' => null, 'status' => 'completed']);

    $seen = array_column($page1['data'], 'subject_id');
    $cursor = $page1['next_cursor'];

    do {
        $page = app(RunSubjects::class)->atNode($this->run, 'wait', $cursor);
        $seen = array_merge($seen, array_column($page['data'], 'subject_id'));
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    // '3' is the one an offset-based walk would have skipped. It must still
    // show up, and nobody still active at the node is missing from the walk.
    expect($seen)->toBe(['1', '2', '3', '4', '5']);
});

it('excludes subjects that are not active at that node', function () {
    // Every terminal transition nulls current_node_id, so these rows can only
    // arrive by a bug or a host write. Counterfactual: drop the predicates and
    // the panel lists a subject who left hours ago as though still present.
    RunSubject::create([
        'run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => '6',
        'current_node_id' => 'wait', 'status' => 'failed',
    ]);
    RunSubject::create([
        'run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => '7',
        'current_node_id' => 'elsewhere', 'status' => 'active',
    ]);

    $page = app(RunSubjects::class)->atNode($this->run, 'wait');

    expect(array_column($page['data'], 'subject_id'))->toBe(['1', '2', '3', '4', '5']);
});

it('keeps the id column on the subjects index so the ordered read is not a filesort', function () {
    // The six-figure claim rests on this index, and nothing else would notice
    // if it were dropped: every test above passes on 5 rows either way.
    // Counterfactual: revert the migration's index to three columns and this
    // fails, which is the only signal that the drill-down just became a sort
    // over a node's entire population on Postgres and SQLite.
    $columns = collect(Schema::getIndexes('nodeflow_run_subjects'))
        ->pluck('columns')
        ->map(fn (array $c) => implode(',', $c));

    expect($columns)->toContain('run_id,current_node_id,status,id');
});

it('denies the subjects endpoint when the host has defined no gates', function () {
    $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/nodes/wait/subjects")
        ->assertForbidden();
});

it('four-oh-fours a node id that is not in the pinned graph', function () {
    // Counterfactual: pass {node} straight into the query and an unknown id
    // returns 200 with an empty list, which reads to an operator as "nobody is
    // here" rather than "that node does not exist."
    Gate::define('nodeflow.viewAny', fn ($user, $subject = null) => true);

    $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/nodes/nosuchnode/subjects")
        ->assertNotFound();
});

/**
 * Open issue G-3, as a test.
 *
 * 'other' is a perfectly real node id — in a different run's graph. The graph
 * check this test defends is what turns "unknown to any graph" into 404 for
 * an id that a permissive implementation would otherwise accept as valid.
 * Without it, the request would still resolve 200 with an *empty* list rather
 * than the other run's subjects — RunSubjects::atNode() is always scoped
 * through the route-bound $run, so 'other' simply matches nothing in this
 * run's rows. That is still the wrong answer: an operator reading an empty
 * list cannot tell "this node doesn't exist" from "nobody is here right now",
 * and accepting a raw key as equivalent to authorization is exactly what G-3
 * warns about. Counterfactual: validate {node} against any graph, or not at
 * all, and this returns 200 with an empty list instead of 404.
 */
it('four-oh-fours a node id that is only valid in another runs graph', function () {
    Gate::define('nodeflow.viewAny', fn ($user, $subject = null) => true);

    $otherVersion = FlowVersion::create([
        'flow_id' => $this->run->flowVersion->flow_id, 'version' => 2, 'content_hash' => 'h2',
        'graph' => triggeredGraph(['start' => 'other', 'nodes' => [['id' => 'other', 'type' => 'core.exit', 'config' => []]], 'edges' => []]),
    ]);
    $otherRun = Run::create([
        'flow_version_id' => $otherVersion->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'running',
    ]);
    RunSubject::create([
        'run_id' => $otherRun->id, 'subject_type' => 'user', 'subject_id' => '99',
        'current_node_id' => 'other', 'status' => 'active',
    ]);

    $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/nodes/other/subjects")
        ->assertNotFound();
});

it('serves a page of subjects with its node and cursor', function () {
    Gate::define('nodeflow.viewAny', fn ($user, $subject = null) => true);
    config(['nodeflow.limits.subject_page' => 2]);

    $body = $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/nodes/wait/subjects")
        ->assertOk()
        ->json();

    expect($body['node'])->toBe('wait')
        ->and($body['data'])->toHaveCount(2)
        ->and($body['next_cursor'])->not->toBeNull();

    $second = $this->actingAs($this->user)
        ->getJson("/nodeflow/runs/{$this->run->id}/nodes/wait/subjects?cursor=".urlencode($body['next_cursor']))
        ->assertOk()
        ->json();

    expect(array_column($second['data'], 'subject_id'))->toBe(['3', '4']);
});
