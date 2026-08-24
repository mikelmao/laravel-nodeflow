<?php

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\StartRun;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;

beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $t, string $ty, string $i): bool { return true; }
    });

    app()->bind(SubjectResolver::class, fn () => new class implements SubjectResolver {
        public function resolve(string $subjectType, array $subjectIds): array
        {
            return collect($subjectIds)
                ->mapWithKeys(fn ($id) => [$id => ['id' => $id]])
                ->all();
        }
    });

    \Tests\Support\RecordingSendNode::$sent = [];
    \Tests\Support\RecordingSendNode::$wouldHaveSent = [];
});

it('propagates test mode into the node context so nodes can suppress side effects', function () {
    Nodeflow::register([\Tests\Support\RecordingSendNode::class]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    $graph = triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]],
        'edges' => [],
    ]);

    app(PublishFlow::class)->publish($flow, $graph);

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1'], ['is_test' => true]);

    app(NodeRunner::class)->run($run, Graph::fromArray($graph), 'n1');

    expect(\Tests\Support\RecordingSendNode::$sent)->toBe([])
        ->and(\Tests\Support\RecordingSendNode::$wouldHaveSent)->toBe(['1']);
});

it('records a real send for a run that is not a test', function () {
    // The positive case above asserts only the is_test = true branch, so it would
    // pass unchanged if isTest() were hardwired to return true. This is the case
    // that fails if it ever is.
    Nodeflow::register([\Tests\Support\RecordingSendNode::class]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    $graph = triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]],
        'edges' => [],
    ]);

    app(PublishFlow::class)->publish($flow, $graph);

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1']);

    expect($run->is_test)->toBeFalse();

    app(NodeRunner::class)->run($run, Graph::fromArray($graph), 'n1');

    expect(\Tests\Support\RecordingSendNode::$sent)->toBe(['1'])
        ->and(\Tests\Support\RecordingSendNode::$wouldHaveSent)->toBe([]);
});

it('does not suppress sends merely because is_test was omitted from the options', function () {
    // Guards the default: an absent is_test must mean "live", not "unknown".
    Nodeflow::register([\Tests\Support\RecordingSendNode::class]);

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'draft']);

    $graph = triggeredGraph([
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]],
        'edges' => [],
    ]);

    app(PublishFlow::class)->publish($flow, $graph);

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1'], ['is_test' => false]);

    app(NodeRunner::class)->run($run, Graph::fromArray($graph), 'n1');

    expect(\Tests\Support\RecordingSendNode::$sent)->toBe(['1']);
});
