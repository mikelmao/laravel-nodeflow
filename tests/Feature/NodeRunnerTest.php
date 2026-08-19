<?php

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeThrowingAudienceNode;
use Tests\Support\FakeThrowingSubjectNode;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    app()->bind(SubjectResolver::class, fn () => new class implements SubjectResolver {
        public function resolve(string $subjectType, array $subjectIds): array
        {
            return collect($subjectIds)
                ->mapWithKeys(fn ($id) => [$id => ['id' => $id, 'clicked' => $id === '1']])
                ->all();
        }
    });

    Nodeflow::register([FakeSendNode::class]);

    $this->graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'core.condition', 'config' => ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null]],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
            ['id' => 'n3', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 'n1', 'output' => 'yes', 'to' => 'n2'],
            ['from' => 'n1', 'output' => 'no', 'to' => 'n3'],
        ],
    ]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => $this->graph->toArray(), 'content_hash' => 'h']);
    $this->run = Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'strategy' => 'cohort', 'status' => 'running']);

    foreach (['1', '2', '3'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    app(\Nodeflow\Schema\SubjectAttributeRegistry::class)->register(
        \Nodeflow\Schema\SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn ($s) => $s['clicked']),
    );
});

it('partitions subjects across outputs and advances each to its target node', function () {
    $next = app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    expect($next)->toEqualCanonicalizing(['n2', 'n3']);

    $atN2 = RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n2')->pluck('subject_id')->all();
    $atN3 = RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n3')->pluck('subject_id')->all();

    expect($atN2)->toBe(['1'])
        ->and($atN3)->toEqualCanonicalizing(['2', '3']);
});

it('writes one node execution row per output with counts, not per subject', function () {
    app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    $rows = $this->run->nodeExecutions()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('output', 'yes')->subject_count)->toBe(1)
        ->and($rows->firstWhere('output', 'no')->subject_count)->toBe(2);
});

it('completes subjects whose output has no outgoing edge', function () {
    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'core.condition', 'config' => ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null]]],
        'edges' => [],
    ]);

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect($next)->toBe([])
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->count())->toBe(3);
});

it('ignores subjects that have exited', function () {
    RunSubject::where('run_id', $this->run->id)
        ->where('subject_id', '2')
        ->update(['status' => 'exited', 'exited_at' => now()]);

    app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    expect(RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n3')->pluck('subject_id')->all())
        ->toBe(['3']);
});

it('records a per-subject failure without aborting the rest of the chunk', function () {
    Nodeflow::register([FakeThrowingSubjectNode::class]);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.throwing-subject', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'ok', 'to' => 'n2']],
    ]);

    app(NodeRunner::class)->run($this->run, $graph, 'n1');

    $failed = RunSubject::where('run_id', $this->run->id)->where('subject_id', '2')->first();

    expect($failed->status)->toBe('failed')
        ->and($failed->last_error)->toContain('boom for subject 2');

    $atN2 = RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n2')->pluck('subject_id')->all();

    expect($atN2)->toEqualCanonicalizing(['1', '3']);
});

it('lets a forAudience failure propagate out of NodeRunner::run', function () {
    Nodeflow::register([FakeThrowingAudienceNode::class]);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.throwing-audience', 'config' => []]],
        'edges' => [],
    ]);

    expect(fn () => app(NodeRunner::class)->run($this->run, $graph, 'n1'))
        ->toThrow(RuntimeException::class, 'audience node exploded');
});
