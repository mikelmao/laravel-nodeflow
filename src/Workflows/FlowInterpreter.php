<?php

namespace Nodeflow\Workflows;

use Nodeflow\Execution\InterpreterLoop;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;
use Nodeflow\Workflows\Activities\CompleteRunActivity;
use Nodeflow\Workflows\Activities\LoadGraphActivity;
use Nodeflow\Workflows\Activities\ResolveRunEntryNodeActivity;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Workflow\V2\Attributes\Signal;
use Workflow\V2\Workflow;
use Workflow\V2\WorkflowStub;

/**
 * Control flow only. No DB, no HTTP, no clock reads: the engine's boot-time
 * guardrail scan rejects those in workflow code, and replay determinism
 * depends on it. Everything with a side effect lives in an activity.
 *
 * This is a v2 workflow: `handle()` is ordinary (non-generator) PHP code
 * that runs inside the engine's fiber. Calls such as `self::activity(...)`
 * and `self::awaitWithTimeout(...)` suspend that fiber directly and return
 * the resolved value to the caller — there is no `yield` in v2 workflow
 * bodies (unlike the v1 `Workflow\Workflow` API this class's predecessor
 * brief was written against).
 *
 * The unified wait: every `core.wait` node compiles to
 * `awaitWithTimeout($duration, 'audienceEmptied')`, racing the node's own
 * timer against a single named signal that `SubjectExiter` fires only when
 * the run's active subject count reaches zero — never once per exiting
 * subject. A cohort of one is therefore not a special case: the single
 * subject exiting *is* the audience emptying, so the wait wakes early with
 * exact cancellation semantics, and the engine's 5,000 pending-signal cap
 * stays structurally unreachable regardless of audience size.
 *
 * Known v1 limitation, deliberately accepted: InterpreterLoop advances its
 * cursor sequentially, so two branches that both contain waits elapse in
 * sequence rather than concurrently — their durations sum instead of
 * overlapping. GraphValidator already warns about this at publish time.
 * Making branch waits run concurrently needs nested generators under the
 * engine's `all()`/`parallel()` and is deliberately deferred to a later plan.
 */
#[Signal('audienceEmptied')]
class FlowInterpreter extends Workflow
{
    public function handle(int $runId, int $maxSteps = 1000, ?string $entryNodeId = null): void
    {
        $graph = Graph::fromArray(self::activity(LoadGraphActivity::class, $runId));

        if ($entryNodeId === null) {
            $entryVersion = self::getVersion(
                'nodeflow.resolve-missing-entry',
                WorkflowStub::DEFAULT_VERSION,
                1,
            );

            if ($entryVersion === 1) {
                $entryNodeId = self::activity(ResolveRunEntryNodeActivity::class, $runId);
            } else {
                $legacyTargets = $graph->targetsFor($graph->startNodeId(), 'started');
                $entryNodeId = count($legacyTargets) === 1
                    ? $legacyTargets[0]
                    : $graph->startNodeId();
            }
        }

        $loop = (new InterpreterLoop)->steps($graph, $maxSteps, $entryNodeId);
        $send = null;

        while ($loop->valid()) {
            $step = $loop->current();

            if ($step instanceof WaitStep) {
                self::awaitWithTimeout($step->duration, 'audienceEmptied');
                $send = null;
            } elseif ($step instanceof RunNodeStep) {
                $send = self::activity(RunNodeActivity::class, $runId, $step->nodeId);
            }

            $loop->send($send);
        }

        self::activity(CompleteRunActivity::class, $runId);
    }
}
