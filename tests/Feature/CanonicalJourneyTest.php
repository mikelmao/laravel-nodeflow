<?php

use Nodeflow\Contracts\SubjectResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\InterpreterLoop;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Execution\StartRun;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Execution\SubjectExiter;
use Nodeflow\Graph\Graph;
use Nodeflow\Models\Flow;
use Nodeflow\Models\RunSubject;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\SubjectAttribute;
use Nodeflow\Schema\SubjectAttributeRegistry;
use Tests\Support\JourneySendNode;

/**
 * The end-to-end integration this branch was missing: InterpreterLoop ->
 * NodeRunner -> advance() over a complete published graph, driven exactly the
 * way Nodeflow\Workflows\FlowInterpreter drives it (step the generator, run a
 * node per RunNodeStep, send back the node ids that now hold subjects) minus
 * the durable engine and the queue.
 *
 * Every per-node review passed while three Critical defects lived in the seam
 * between these classes, because no test ever crossed it.
 */
beforeEach(function () {
    app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
    {
        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $t, string $ty, string $i): bool
        {
            return true;
        }
    });

    // '1' and '2' clicked; '4' did not. '3' exits mid-wait and is never evaluated.
    app()->bind(SubjectResolver::class, fn () => new class implements SubjectResolver
    {
        public function resolve(string $subjectType, array $subjectIds): array
        {
            return collect($subjectIds)
                ->mapWithKeys(fn ($id) => [$id => ['id' => $id, 'clicked' => in_array($id, ['1', '2'], true)]])
                ->all();
        }
    });

    app(SubjectAttributeRegistry::class)->register(
        SubjectAttribute::make('clicked', 'Clicked', 'boolean', fn ($s) => $s['clicked']),
    );

    JourneySendNode::reset();
    Nodeflow::register([JourneySendNode::class]);

    $this->graph = [
        'start' => 's1',
        'nodes' => [
            ['id' => 's1', 'type' => 'test.journey_send', 'config' => []],
            ['id' => 'w1', 'type' => 'core.wait', 'config' => ['duration' => '1 day']],
            ['id' => 's2', 'type' => 'test.journey_send', 'config' => []],
            ['id' => 'w2', 'type' => 'core.wait', 'config' => ['duration' => '2 days']],
            ['id' => 'c1', 'type' => 'core.condition', 'config' => ['attribute' => 'clicked', 'operator' => 'is_true', 'value' => null]],
            ['id' => 'f1', 'type' => 'test.journey_send', 'config' => []],
            ['id' => 'x1', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [
            ['from' => 's1', 'output' => 'sent', 'to' => 'w1'],
            ['from' => 'w1', 'output' => 'default', 'to' => 's2'],
            ['from' => 's2', 'output' => 'sent', 'to' => 'w2'],
            ['from' => 'w2', 'output' => 'default', 'to' => 'c1'],
            ['from' => 'c1', 'output' => 'yes', 'to' => 'f1'],
            ['from' => 'c1', 'output' => 'no', 'to' => 'x1'],
            ['from' => 'f1', 'output' => 'sent', 'to' => 'x1'],
        ],
    ];

    $flow = Flow::create(['name' => 'Flood alert journey', 'trigger_type' => 'manual', 'status' => 'draft']);

    app(PublishFlow::class)->publish($flow, $this->graph);

    $this->run = app(StartRun::class)->forFlow($flow->fresh(), 'user', ['1', '2', '3', '4']);
});

it('drives the canonical journey end to end and leaves no subject active', function () {
    $graph = Graph::fromArray($this->graph);
    $exitedDuringWait = [];

    // Exactly what FlowInterpreter::handle() does, with awaitWithTimeout replaced
    // by the thing a wait actually races: a subject leaving the flow.
    $loop = (new InterpreterLoop)->steps($graph, 100);
    $send = null;
    $order = [];

    while ($loop->valid()) {
        $step = $loop->current();

        if ($step instanceof WaitStep) {
            $order[] = 'wait:'.$step->nodeId;

            if ($step->nodeId === 'w1') {
                // The conversion event lands mid-wait. Subject 3 must never be
                // sent to again.
                app(SubjectExiter::class)->exit($this->run, ['3']);
                $exitedDuringWait[] = '3';
            }

            $send = null;
        } elseif ($step instanceof RunNodeStep) {
            $order[] = 'run:'.$step->nodeId;
            $send = app(NodeRunner::class)->run($this->run, $graph, $step->nodeId);
        }

        $loop->send($send);
    }

    // 1. Every node saw exactly the audience it should have.
    expect(JourneySendNode::sentAt('s1'))->toEqualCanonicalizing(['1', '2', '3', '4'], 'first send goes to the whole cohort');

    // 2. The subject who exited mid-wait stops receiving messages.
    expect(JourneySendNode::sentAt('s2'))->toEqualCanonicalizing(['1', '2', '4'])
        ->and(JourneySendNode::sentAt('s2'))->not->toContain('3')
        ->and(JourneySendNode::sentAt('f1'))->not->toContain('3');

    // 3. The condition partitions the remaining audience correctly.
    expect(JourneySendNode::sentAt('f1'))->toEqualCanonicalizing(['1', '2']);

    $executions = $this->run->nodeExecutions()->where('node_id', 'c1')->get();

    expect($executions->firstWhere('output', 'yes')->subject_count)->toBe(2)
        ->and($executions->firstWhere('output', 'no')->subject_count)->toBe(1);

    // 4. Every subject reached a terminal status.
    $statuses = RunSubject::where('run_id', $this->run->id)
        ->pluck('status', 'subject_id')
        ->all();

    ksort($statuses);

    expect($statuses)->toBe([
        1 => 'completed',
        2 => 'completed',
        3 => 'exited',
        4 => 'completed',
    ]);

    // 5. THE ASSERTION THAT CATCHES A STRANDED SUBJECT.
    //
    // A node that returns NodeResult::empty() (core.exit, or core.start_flow with
    // exit_this_flow) used to leave its subjects status='active' with
    // current_node_id still pointing at the finished node. That silently breaks
    // two documented behaviours: SubjectExiter can never see activeSubjectCount()
    // reach 0, so no later cohort wait ever wakes early (D10 / spec 7.3), and
    // CompleteRunActivity marks the run 'completed' while subjects sit 'active',
    // so the run view lies. If this assertion ever fails, the reconciliation
    // sweep in NodeRunner::advance() has regressed.
    expect(RunSubject::where('run_id', $this->run->id)->where('status', 'active')->count())
        ->toBe(0, 'no subject may be left active once the run has finished')
        ->and($this->run->fresh()->activeSubjectCount())->toBe(0);

    expect(RunSubject::where('run_id', $this->run->id)->whereNotNull('current_node_id')->count())
        ->toBe(0, 'a terminal subject holds no cursor');

    // The step order is part of the contract: each wait is yielded immediately
    // before its own node runs, so a wait can never be skipped or double-run.
    expect($order)->toBe([
        'run:s1',
        'wait:w1', 'run:w1',
        'run:s2',
        'wait:w2', 'run:w2',
        'run:c1',
        'run:f1', 'run:x1',
        'run:x1',
    ]);
})->group('integration');
