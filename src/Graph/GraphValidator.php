<?php

namespace Nodeflow\Graph;

use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\NodeRegistry;

class GraphValidator
{
    public function __construct(private NodeRegistry $registry) {}

    public function validate(Graph $graph): GraphValidationResult
    {
        $errors = [];
        $warnings = [];
        $nodeErrors = [];

        if ($graph->startNodeId() === '') {
            $errors[] = 'The flow has no start node set. Choose a starting node before publishing.';
            // No node exists yet to pin this to — the graph itself has no start.
            $nodeErrors[] = ['node' => null, 'field' => null, 'message' => end($errors)];
        } elseif ($graph->node($graph->startNodeId()) === null) {
            $errors[] = "The start node [{$graph->startNodeId()}] does not exist in this flow. ".
                'Set the start to one of the flow\'s existing nodes.';
            $nodeErrors[] = ['node' => $graph->startNodeId(), 'field' => null, 'message' => end($errors)];
        }

        foreach ($graph->duplicateNodeIds() as $id) {
            $errors[] = "Node id [{$id}] is used by more than one node. Node ids must be unique — ".
                'rename or remove the duplicate so each node keeps its own id.';
            $nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];
        }

        foreach ($graph->nodeIds() as $id) {
            $node = $graph->node($id);
            $type = $node['type'] ?? '';

            if (! $this->registry->has($type)) {
                $errors[] = "Node [{$id}] uses unknown type [{$type}].";
                $nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];

                continue;
            }

            $instance = $this->registry->resolve($type);

            if (! $instance instanceof HandlesSubject && ! $instance instanceof HandlesAudience) {
                $errors[] = "Node [{$id}] uses type [{$type}], whose class ".$instance::class.' implements '
                    .'neither HandlesSubject nor HandlesAudience and therefore cannot be executed. '
                    .'The node class must implement at least one cardinality interface.';
                $nodeErrors[] = ['node' => $id, 'field' => null, 'message' => end($errors)];
            }

            foreach ($instance->validate($node['config'] ?? []) as $field => $messages) {
                $errors[] = "Node [{$id}] field [{$field}]: ".implode(' ', $messages);
                // The bare field message, not the prefixed string: the editor already
                // knows the node and field from the structure, so re-prefixing here
                // would make the client strip its own node id back off.
                $nodeErrors[] = ['node' => $id, 'field' => $field, 'message' => implode(' ', $messages)];
            }
        }

        $seenOutputs = [];

        foreach ($graph->edges() as $edge) {
            if ($graph->node($edge['to']) === null) {
                $errors[] = "Edge from [{$edge['from']}] points at missing node [{$edge['to']}].";
                $nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];
            }

            $from = $graph->node($edge['from']);

            if ($from !== null && $this->registry->has($from['type'] ?? '')) {
                $outputs = $this->registry->resolve($from['type'])->definition()->outputNames();

                if (! in_array($edge['output'], $outputs, true)) {
                    $errors[] = "Node [{$edge['from']}] has no output [{$edge['output']}].";
                    $nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];
                }
            }

            $key = $edge['from'].':'.$edge['output'];

            if (isset($seenOutputs[$key])) {
                // Fan-out to parallel branches is deferred, not merely unimplemented:
                // nodeflow_run_subjects carries unique(run_id, subject_type,
                // subject_id) and a single current_node_id, so one subject cannot
                // occupy two nodes. See spec section 7.
                $errors[] = "Node [{$edge['from']}] output [{$edge['output']}] has more than one outgoing edge. ".
                    'An output may lead to exactly one node. Use a Condition node to send each subject '.
                    'down one of several branches; sending the same subject down two branches at once is '.
                    'not supported in this version.';
                $nodeErrors[] = ['node' => $edge['from'], 'field' => null, 'message' => end($errors)];
            }

            $seenOutputs[$key] = true;
        }

        if (($cycle = $this->findCycle($graph)) !== null) {
            $path = implode(' -> ', $cycle);
            $errors[] = "The flow contains a cycle: {$path}. Flows must be acyclic.";
            // A cycle spans every node on its path, not one node in particular —
            // attributing it to a single id would put a red badge on an innocent card.
            $nodeErrors[] = ['node' => null, 'field' => null, 'message' => end($errors)];
        }

        if ($this->hasConcurrentWaits($graph)) {
            $warnings[] = 'Two or more branches contain waits. In this version, branch waits '.
                'run sequentially rather than concurrently, so total elapsed time is the sum, not the maximum.';
        }

        return new GraphValidationResult($errors, $warnings, $nodeErrors);
    }

    /**
     * Depth-first search that reports the node ids forming a cycle, not just whether one exists.
     * Returns null when the graph is acyclic. Recursion is bounded by the node count: each node
     * moves from 'new' to 'visiting' to 'done' at most once, and edges to ids absent from the
     * graph are never followed (the missing-target rule reports those separately).
     *
     * @return string[]|null
     */
    private function findCycle(Graph $graph): ?array
    {
        $state = [];
        $path = [];

        $visit = function (string $id) use (&$visit, &$state, &$path, $graph): ?array {
            if (($state[$id] ?? 'new') === 'visiting') {
                $start = array_search($id, $path, true);
                $cycle = array_slice($path, $start);
                $cycle[] = $id;

                return $cycle;
            }

            if (($state[$id] ?? 'new') === 'done') {
                return null;
            }

            $state[$id] = 'visiting';
            $path[] = $id;

            foreach ($graph->edges() as $edge) {
                if ($edge['from'] === $id && $graph->node($edge['to']) !== null) {
                    $found = $visit($edge['to']);

                    if ($found !== null) {
                        return $found;
                    }
                }
            }

            array_pop($path);
            $state[$id] = 'done';

            return null;
        };

        foreach ($graph->nodeIds() as $id) {
            if (($state[$id] ?? 'new') === 'new') {
                $found = $visit($id);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function hasConcurrentWaits(Graph $graph): bool
    {
        $branching = [];

        foreach ($graph->edges() as $edge) {
            $branching[$edge['from']][$edge['output']] = $edge['to'];
        }

        foreach ($branching as $outputs) {
            if (count($outputs) < 2) {
                continue;
            }

            $withWaits = array_filter(
                $outputs,
                fn (string $target) => ($graph->node($target)['type'] ?? '') === 'core.wait',
            );

            if (count($withWaits) >= 2) {
                return true;
            }
        }

        return false;
    }
}
