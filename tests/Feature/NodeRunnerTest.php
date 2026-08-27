<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\UniformAudienceResultValidator;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Tests\Support\FakeSelfExitingNode;
use Tests\Support\FakeSendNode;
use Tests\Support\FakeThrowingAudienceNode;
use Tests\Support\FakeThrowingSubjectNode;
use Tests\Support\FakeUniformAudienceNode;

class UniformAudienceMemoryProbe extends UniformAudienceResultValidator
{
    public int $sampledChunks = 0;

    public int $firstBytes = 0;

    public int $lastBytes = 0;

    public int $maxBytes = 0;

    private int $validatedChunks = 0;

    public function __construct(private readonly int $warmupChunks) {}

    public function assertValid(
        string $nodeType,
        string $nodeId,
        string $expectedOutput,
        array $declaredOutputs,
        array $expectedSubjectIds,
        NodeResult $result,
    ): void {
        parent::assertValid($nodeType, $nodeId, $expectedOutput, $declaredOutputs, $expectedSubjectIds, $result);

        $this->afterValidation($expectedSubjectIds);
    }

    protected function afterValidation(array $expectedSubjectIds): void
    {
        $this->validatedChunks++;

        if ($this->validatedChunks <= $this->warmupChunks) {
            return;
        }

        $bytes = memory_get_usage(false);
        $this->sampledChunks++;

        if ($this->sampledChunks === 1) {
            $this->firstBytes = $bytes;
        }

        $this->lastBytes = $bytes;
        $this->maxBytes = max($this->maxBytes, $bytes);
    }
}

class RetainingUniformAudienceMemoryControl extends UniformAudienceMemoryProbe
{
    private array $retainedExpectedChunks = [];

    protected function afterValidation(array $expectedSubjectIds): void
    {
        $this->retainedExpectedChunks[] = $expectedSubjectIds;

        parent::afterValidation($expectedSubjectIds);
    }
}

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

    FakeUniformAudienceNode::reset();
    Nodeflow::register([FakeSendNode::class, FakeUniformAudienceNode::class]);

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

    $flow = Flow::create(['name' => 'F', 'status' => 'active']);
    $version = FlowVersion::create(['flow_id' => $flow->id, 'version' => 1, 'graph' => $this->graph->toArray(), 'content_hash' => 'h']);
    $this->run = Run::create(['flow_version_id' => $version->id, 'tenant_id' => 'org-1', 'started_via' => 'manual', 'trigger_node_id' => 'trigger', 'trigger_data' => null, 'strategy' => 'cohort', 'status' => 'running']);

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
    // Force at least three chunks over five subjects so this test can actually fail if
    // node_executions rows were mistakenly written per chunk instead of once per output:
    // with a single chunk (the previous version of this test), duplicated per-chunk rows
    // would be indistinguishable from one row per output.
    config()->set('nodeflow.limits.subject_chunk', 2);

    foreach (['4', '5'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    app(NodeRunner::class)->run($this->run, $this->graph, 'n1');

    $rows = $this->run->nodeExecutions()->get();

    // 5 subjects at chunk size 2 forces chunks [1,2], [3,4], [5] — three chunk callbacks.
    // Only subject '1' is clicked (per the fake SubjectResolver), so 'yes' must still tally
    // to 1 and 'no' to 4 even though the matching subjects are split across chunk boundaries.
    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('output', 'yes')->subject_count)->toBe(1)
        ->and($rows->firstWhere('output', 'no')->subject_count)->toBe(4);
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
        ->and($failed->last_error)->toContain('boom for subject 2')
        ->and($failed->last_error)->toContain('RuntimeException');

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

it('completes subjects a node named in no output, so NodeResult::empty() is terminal', function () {
    // core.exit names nobody. Those subjects have left the flow; leaving them
    // status='active' on a finished run is what stranded them before.
    $graph = Graph::fromArray([
        'start' => 'x1',
        'nodes' => [['id' => 'x1', 'type' => 'core.exit', 'config' => []]],
        'edges' => [],
    ]);

    RunSubject::where('run_id', $this->run->id)->update(['current_node_id' => 'x1']);

    $next = app(NodeRunner::class)->run($this->run, $graph, 'x1');

    expect($next)->toBe([])
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->count())->toBe(3)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->count())->toBe(0)
        ->and(RunSubject::where('run_id', $this->run->id)->whereNotNull('current_node_id')->count())->toBe(0);
});

it('reconciles only the subjects that were at this node, never the rest of the run', function () {
    // '3' is parked at an unrelated node in the same run. Sweeping departures at
    // x1 must not touch it, or the sweep would silently terminate live subjects.
    $graph = Graph::fromArray([
        'start' => 'x1',
        'nodes' => [
            ['id' => 'x1', 'type' => 'core.exit', 'config' => []],
            ['id' => 'elsewhere', 'type' => 'test.send', 'config' => ['channel' => 'sms']],
        ],
        'edges' => [],
    ]);

    RunSubject::where('run_id', $this->run->id)->whereIn('subject_id', ['1', '2'])->update(['current_node_id' => 'x1']);
    RunSubject::where('run_id', $this->run->id)->where('subject_id', '3')->update(['current_node_id' => 'elsewhere']);

    app(NodeRunner::class)->run($this->run, $graph, 'x1');

    $elsewhere = RunSubject::where('run_id', $this->run->id)->where('subject_id', '3')->first();

    expect($elsewhere->status)->toBe('active')
        ->and($elsewhere->current_node_id)->toBe('elsewhere')
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->pluck('subject_id')->all())
        ->toEqualCanonicalizing(['1', '2']);
});

it('completes the subjects of a start_flow node that exits this flow', function () {
    // Fix 2's headline case: canonical step 4 hands the cohort to a sub-flow and
    // the default exit_this_flow => true returns NodeResult::empty().
    Nodeflow::register([\Tests\Support\FakeEmptyAudienceNode::class]);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.empty-audience', 'config' => []]],
        'edges' => [],
    ]);

    app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->count())->toBe(0)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->count())->toBe(3);
});

it('does not strand a subject when the node exits another subject mid-chunk (chunkById, not offset chunk)', function () {
    // Reproduces the reviewer's probe: 6 subjects, subject_chunk = 2, node body
    // exits subject '1' mid-loop. Under offset-based chunk() over a query
    // filtered on status='active', subject 1's departure shifts every later
    // page's offset window and subject '3' is skipped entirely — never passed
    // to the node, left status='active'/current_node_id='n1' on a run that
    // otherwise finishes, which starves activeSubjectCount() of zero forever.
    FakeSelfExitingNode::$seen = [];
    FakeSelfExitingNode::$exitSubjectId = '1';

    config()->set('nodeflow.limits.subject_chunk', 2);

    Nodeflow::register([FakeSelfExitingNode::class]);

    foreach (['4', '5', '6'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.self-exiting', 'config' => []]],
        'edges' => [],
    ]);

    app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect(FakeSelfExitingNode::$seen)->toEqualCanonicalizing(['1', '2', '3', '4', '5', '6']);

    expect(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->whereNotNull('current_node_id')->count())
        ->toBe(0);
});

it('streams a uniform audience in bounded chunks and records one aggregate execution', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    foreach (['4', '5'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect(FakeUniformAudienceNode::$chunks)->toBe([['1', '2'], ['3', '4'], ['5']])
        ->and($next)->toBe(['n2'])
        ->and($this->run->nodeExecutions()->count())->toBe(1)
        ->and($this->run->nodeExecutions()->first()->subject_count)->toBe(5)
        ->and(RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n2')->count())->toBe(5);
});

it('leaves every uniform audience cursor unchanged after a late failure and replays the whole audience', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    foreach (['4', '5'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $chunkHashes = [];
    FakeUniformAudienceNode::$handler = function (AudienceContext $context, int $call) use (&$chunkHashes): NodeResult {
        $chunkHashes[] = hash('sha256', json_encode($context->subjectIds(), JSON_THROW_ON_ERROR));

        if ($call === 3) {
            throw new RuntimeException('late uniform failure');
        }

        return $context->all('sent');
    };

    expect(fn () => app(NodeRunner::class)->run($this->run, $graph, 'n1'))
        ->toThrow(RuntimeException::class, 'late uniform failure');

    $expectedChunks = [['1', '2'], ['3', '4'], ['5']];
    $expectedHashes = array_map(
        fn (array $ids): string => hash('sha256', json_encode($ids, JSON_THROW_ON_ERROR)),
        $expectedChunks,
    );

    expect(FakeUniformAudienceNode::$chunks)->toBe($expectedChunks)
        ->and($chunkHashes)->toBe($expectedHashes)
        ->and($this->run->nodeExecutions()->count())->toBe(0)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->where('current_node_id', 'n1')->count())->toBe(5);

    FakeUniformAudienceNode::$handler = null;
    FakeUniformAudienceNode::$chunks = [];

    expect(app(NodeRunner::class)->run($this->run, $graph, 'n1'))->toBe(['n2'])
        ->and(FakeUniformAudienceNode::$chunks)->toBe($expectedChunks)
        ->and($this->run->nodeExecutions()->count())->toBe(1)
        ->and($this->run->nodeExecutions()->first()->subject_count)->toBe(5)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->where('current_node_id', 'n2')->count())->toBe(5);
});

it('preserves two-argument NodeRunner construction for a uniform audience', function () {
    $runner = null;
    $constructionError = null;

    try {
        $runner = new NodeRunner(Nodeflow::nodes(), app(SubjectResolver::class));
    } catch (ArgumentCountError $exception) {
        $constructionError = $exception;
    }

    expect($constructionError)->toBeNull();

    if (! $runner instanceof NodeRunner) {
        return;
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    expect($runner->run($this->run, $graph, 'n1'))->toBe(['n2'])
        ->and($this->run->nodeExecutions()->first()->subject_count)->toBe(3);
});

it('rejects an invalid uniform audience output before invoking the node', function () {
    FakeUniformAudienceNode::$output = '   ';
    $handlerCalled = false;
    FakeUniformAudienceNode::$handler = function (AudienceContext $context) use (&$handlerCalled): NodeResult {
        $handlerCalled = true;

        return $context->all('sent');
    };

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []]],
        'edges' => [],
    ]);

    expect(fn () => app(NodeRunner::class)->run($this->run, $graph, 'n1'))
        ->toThrow(RuntimeException::class, 'invalid_output');

    expect($handlerCalled)->toBeFalse()
        ->and(FakeUniformAudienceNode::$chunks)->toBe([])
        ->and($this->run->nodeExecutions()->count())->toBe(0);
});

it('rejects an invalid uniform audience chunk result without mutating cursors or projections', function (Closure $result, string $category) {
    FakeUniformAudienceNode::$handler = fn (AudienceContext $context): NodeResult => $result($context);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    expect(fn () => app(NodeRunner::class)->run($this->run, $graph, 'n1'))
        ->toThrow(RuntimeException::class, $category);

    expect($this->run->nodeExecutions()->count())->toBe(0)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->where('current_node_id', 'n1')->count())->toBe(3);
})->with([
    'wrong output key' => [
        fn (AudienceContext $context): NodeResult => NodeResult::partition(['wrong' => $context->subjectIds()]),
        'unexpected_output_keys',
    ],
    'failure' => [
        fn (AudienceContext $context): NodeResult => NodeResult::failed($context->subjectIds()[0], 'rejected'),
        'failures',
    ],
    'duplicate ID' => [
        fn (AudienceContext $context): NodeResult => NodeResult::partition([
            'sent' => [...$context->subjectIds(), $context->subjectIds()[0]],
        ]),
        'duplicate_ids',
    ],
    'missing ID' => [
        fn (AudienceContext $context): NodeResult => NodeResult::partition([
            'sent' => array_slice($context->subjectIds(), 0, -1),
        ]),
        'missing_or_extra_ids',
    ],
    'extra ID' => [
        fn (AudienceContext $context): NodeResult => NodeResult::partition([
            'sent' => [...$context->subjectIds(), '99'],
        ]),
        'missing_or_extra_ids',
    ],
]);

it('rejects mixed subject types before invoking the uniform audience for the affected chunk', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    RunSubject::create([
        'run_id' => $this->run->id,
        'subject_type' => 'account',
        'subject_id' => '4',
        'current_node_id' => 'n1',
        'status' => 'active',
    ]);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    expect(fn () => app(NodeRunner::class)->run($this->run, $graph, 'n1'))
        ->toThrow(RuntimeException::class, 'mixed_subject_types');

    expect(FakeUniformAudienceNode::$chunks)->toBe([['1', '2']])
        ->and($this->run->nodeExecutions()->count())->toBe(0)
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->where('current_node_id', 'n1')->count())->toBe(4);
});

it('completes a terminal uniform audience with null cursors', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []]],
        'edges' => [],
    ]);

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect($next)->toBe([])
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'completed')->count())->toBe(3)
        ->and(RunSubject::where('run_id', $this->run->id)->whereNotNull('current_node_id')->count())->toBe(0);
});

it('uses one bounded set update for a large uniform audience', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    RunSubject::where('run_id', $this->run->id)->delete();

    $rows = [];
    foreach (range(1, 2001) as $id) {
        $rows[] = [
            'run_id' => $this->run->id,
            'subject_type' => 'user',
            'subject_id' => (string) $id,
            'current_node_id' => 'n1',
            'status' => 'active',
        ];
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        RunSubject::insert($chunk);
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $subjectUpdates = [];
    DB::listen(function (QueryExecuted $query) use (&$subjectUpdates): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'update')
            && str_contains(strtolower($query->sql), 'nodeflow_run_subjects')) {
            $subjectUpdates[] = $query;
        }
    });

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect($next)->toBe(['n2'])
        ->and($subjectUpdates)->toHaveCount(1)
        ->and($subjectUpdates[0]->sql)->not->toMatch('/["`]?subject_id["`]?\s+in\s*\(/i')
        ->and($subjectUpdates[0]->bindings)->toHaveCount(6)
        ->and($this->run->nodeExecutions()->first()->subject_count)->toBe(2001);
});

it('keeps uniform audience memory growth bounded relative to a retaining control', function () {
    $chunkSize = 250;
    $subjectCount = 30_000;
    $warmupChunks = 10;
    config()->set('nodeflow.limits.audience_chunk', $chunkSize);

    RunSubject::where('run_id', $this->run->id)->delete();

    $expectedHash = '';
    foreach (array_chunk(range(1, $subjectCount), 500) as $batch) {
        $rows = [];
        foreach ($batch as $id) {
            $subjectId = 'memory-subject-'.$id;
            $expectedHash = hash('sha256', $expectedHash."\0".$subjectId);
            $rows[] = [
                'run_id' => $this->run->id,
                'subject_type' => 'user',
                'subject_id' => $subjectId,
                'current_node_id' => 'n1',
                'status' => 'active',
            ];
        }
        RunSubject::insert($rows);
    }

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $resolver = new class implements SubjectResolver
    {
        public int $resolveCalls = 0;

        public function resolve(string $subjectType, array $subjectIds): array
        {
            $this->resolveCalls++;

            throw new RuntimeException('Uniform audience execution must not resolve subject models.');
        }
    };

    FakeUniformAudienceNode::recordOnlyScalarMetrics();
    $productionProbe = new UniformAudienceMemoryProbe($warmupChunks);
    $productionRunner = new NodeRunner(Nodeflow::nodes(), $resolver, $productionProbe);
    $productionRunner->run($this->run, $graph, 'n1');

    $productionGrowth = $productionProbe->lastBytes - $productionProbe->firstBytes;
    $productionPeakGrowth = $productionProbe->maxBytes - $productionProbe->firstBytes;
    $expectedSamples = intdiv($subjectCount, $chunkSize) - $warmupChunks;

    expect(FakeUniformAudienceNode::$callCount)->toBe(intdiv($subjectCount, $chunkSize))
        ->and(FakeUniformAudienceNode::$totalSubjectCount)->toBe($subjectCount)
        ->and(FakeUniformAudienceNode::$maxChunkSize)->toBe($chunkSize)
        ->and(FakeUniformAudienceNode::$rollingHash)->toBe($expectedHash)
        ->and(FakeUniformAudienceNode::$chunks)->toBe([])
        ->and($productionProbe->sampledChunks)->toBe($expectedSamples)
        ->and($resolver->resolveCalls)->toBe(0);

    RunSubject::where('run_id', $this->run->id)
        ->where('current_node_id', 'n2')
        ->update(['current_node_id' => 'n1']);
    $this->run->nodeExecutions()->delete();

    FakeUniformAudienceNode::recordOnlyScalarMetrics();
    $controlProbe = new RetainingUniformAudienceMemoryControl($warmupChunks);
    $controlRunner = new NodeRunner(Nodeflow::nodes(), $resolver, $controlProbe);
    $controlRunner->run($this->run, $graph, 'n1');

    $controlGrowth = $controlProbe->lastBytes - $controlProbe->firstBytes;

    expect(FakeUniformAudienceNode::$callCount)->toBe(intdiv($subjectCount, $chunkSize))
        ->and(FakeUniformAudienceNode::$totalSubjectCount)->toBe($subjectCount)
        ->and(FakeUniformAudienceNode::$maxChunkSize)->toBe($chunkSize)
        ->and(FakeUniformAudienceNode::$rollingHash)->toBe($expectedHash)
        ->and(FakeUniformAudienceNode::$chunks)->toBe([])
        ->and($controlProbe->sampledChunks)->toBe($expectedSamples)
        ->and($controlGrowth)->toBeGreaterThan(1_000_000)
        ->and($productionGrowth)->toBeLessThan($controlGrowth * 0.10)
        ->and($productionPeakGrowth)->toBeLessThan($controlGrowth * 0.10)
        ->and($resolver->resolveCalls)->toBe(0);
});

it('keeps uniform audience cancellation pagination stable and excludes rows above the high-water mark', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    foreach (['4', '5'] as $id) {
        RunSubject::create(['run_id' => $this->run->id, 'subject_type' => 'user', 'subject_id' => $id, 'current_node_id' => 'n1', 'status' => 'active']);
    }

    FakeUniformAudienceNode::$handler = function (AudienceContext $context, int $call): NodeResult {
        if ($call === 1) {
            RunSubject::where('run_id', $this->run->id)
                ->where('subject_id', '1')
                ->update(['status' => 'exited', 'exited_at' => now()]);

            RunSubject::create([
                'run_id' => $this->run->id,
                'subject_type' => 'user',
                'subject_id' => '99',
                'current_node_id' => 'n1',
                'status' => 'active',
            ]);
        }

        return $context->all('sent');
    };

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    app(NodeRunner::class)->run($this->run, $graph, 'n1');

    $exited = RunSubject::where('run_id', $this->run->id)->where('subject_id', '1')->firstOrFail();
    $late = RunSubject::where('run_id', $this->run->id)->where('subject_id', '99')->firstOrFail();
    $visited = collect(FakeUniformAudienceNode::$chunks)->flatten()->all();

    expect(FakeUniformAudienceNode::$chunks)->toBe([['1', '2'], ['3', '4'], ['5']])
        ->and($visited)->toEqualCanonicalizing(['1', '2', '3', '4', '5'])
        ->and(array_count_values($visited))->toBe(['1' => 1, '2' => 1, '3' => 1, '4' => 1, '5' => 1])
        ->and($exited->status)->toBe('exited')
        ->and($exited->current_node_id)->toBe('n1')
        ->and($late->status)->toBe('active')
        ->and($late->current_node_id)->toBe('n1')
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->where('current_node_id', 'n2')->pluck('subject_id')->all())
        ->toEqualCanonicalizing(['2', '3', '4', '5']);
});

it('returns no work when a uniform audience is cancelled after high-water capture', function () {
    config()->set('nodeflow.limits.audience_chunk', 2);

    $graph = Graph::fromArray([
        'start' => 'n1',
        'nodes' => [
            ['id' => 'n1', 'type' => 'test.uniform-audience', 'config' => []],
            ['id' => 'n2', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'n1', 'output' => 'sent', 'to' => 'n2']],
    ]);

    $armed = true;
    $captureRunnerUpdates = false;
    $runnerUpdates = [];
    $run = $this->run;

    DB::listen(function (QueryExecuted $query) use (&$armed, &$captureRunnerUpdates, &$runnerUpdates, $run): void {
        $sql = strtolower($query->sql);

        if ($armed && str_starts_with(ltrim($sql), 'select')
            && str_contains($sql, 'max(')
            && str_contains($sql, 'nodeflow_run_subjects')) {
            $armed = false;
            RunSubject::where('run_id', $run->id)
                ->where('current_node_id', 'n1')
                ->where('status', 'active')
                ->update(['status' => 'exited', 'exited_at' => now()]);
            $captureRunnerUpdates = true;

            return;
        }

        if ($captureRunnerUpdates && str_starts_with(ltrim($sql), 'update')
            && str_contains($sql, 'nodeflow_run_subjects')) {
            $runnerUpdates[] = $query;
        }
    });

    $next = app(NodeRunner::class)->run($this->run, $graph, 'n1');

    expect($armed)->toBeFalse()
        ->and(FakeUniformAudienceNode::$chunks)->toBe([])
        ->and($next)->toBe([])
        ->and($this->run->nodeExecutions()->count())->toBe(0)
        ->and($runnerUpdates)->toBe([])
        ->and(RunSubject::where('run_id', $this->run->id)->where('status', 'exited')->count())->toBe(3)
        ->and(RunSubject::where('run_id', $this->run->id)->where('current_node_id', 'n1')->count())->toBe(3);
});
