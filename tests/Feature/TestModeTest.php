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

    $graph = [
        'start' => 'n1',
        'nodes' => [['id' => 'n1', 'type' => 'test.recording', 'config' => []]],
        'edges' => [],
    ];

    app(PublishFlow::class)->publish($flow, $graph);

    $run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1'], ['is_test' => true]);

    app(NodeRunner::class)->run($run, Graph::fromArray($graph), 'n1');

    expect(\Tests\Support\RecordingSendNode::$sent)->toBe([])
        ->and(\Tests\Support\RecordingSendNode::$wouldHaveSent)->toBe(['1']);
});
