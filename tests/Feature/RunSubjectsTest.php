<?php

use Illuminate\Support\Facades\Schema;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Runs\RunSubjects;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string { return 'org-1'; }

        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => ['start' => 'wait', 'nodes' => [['id' => 'wait', 'type' => 'core.exit', 'config' => []]], 'edges' => []],
        'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
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
