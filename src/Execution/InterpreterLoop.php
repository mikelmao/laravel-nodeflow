<?php

namespace Nodeflow\Execution;

use Generator;
use Nodeflow\Execution\Steps\RunNodeStep;
use Nodeflow\Execution\Steps\WaitStep;
use Nodeflow\Graph\Graph;

/**
 * Pure control flow, no I/O: cursor advancement, wait placement, and the step
 * guard, with nothing that touches the engine, the database, or the clock.
 * That is what makes it unit-testable without an engine or a queue; the
 * engine-facing translation lives in Nodeflow\Workflows\FlowInterpreter.
 */
class InterpreterLoop
{
    /**
     * Yields WaitStep and RunNodeStep. The caller sends back, for each
     * RunNodeStep, the array of node ids that now hold subjects.
     */
    public function steps(Graph $graph, int $maxSteps): Generator
    {
        // Published graphs start at a declarative trigger. A trigger records
        // why a run exists; it is not executable workflow code. Run creation
        // seeds subjects at the one `started` target, and the interpreter must
        // begin at that same entry instead of trying to resolve the trigger
        // through NodeRegistry. The fallback preserves this pure loop's
        // compatibility with the small executable-only graphs used directly
        // by hosts and older tests.
        $entryTargets = $graph->targetsFor($graph->startNodeId(), 'started');
        $cursor = count($entryTargets) === 1
            ? [$entryTargets[0]]
            : [$graph->startNodeId()];
        $steps = 0;

        while ($cursor !== [] && $steps < $maxSteps) {
            $next = [];

            foreach ($cursor as $nodeId) {
                $node = $graph->node($nodeId);

                if ($node === null) {
                    continue;
                }

                if (($node['type'] ?? '') === 'core.wait') {
                    yield new WaitStep($nodeId, (string) ($node['config']['duration'] ?? '1 minute'));
                }

                $produced = yield new RunNodeStep($nodeId);

                $next = array_merge($next, $produced ?? []);

                $steps++;

                if ($steps >= $maxSteps) {
                    return;
                }
            }

            $cursor = array_values(array_unique($next));
        }
    }
}
