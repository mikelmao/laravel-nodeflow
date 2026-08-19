<?php

namespace Nodeflow\Graph;

use Nodeflow\Nodes\NodeRegistry;

class GraphValidator
{
    public function __construct(private NodeRegistry $registry) {}

    public function validate(Graph $graph): GraphValidationResult
    {
        $errors = [];
        $warnings = [];

        if ($graph->startNodeId() === '') {
            $errors[] = 'The flow has no start node set. Choose a starting node before publishing.';
        } elseif ($graph->node($graph->startNodeId()) === null) {
            $errors[] = "The start node [{$graph->startNodeId()}] does not exist in this flow. ".
                'Set the start to one of the flow\'s existing nodes.';
        }

        foreach ($graph->duplicateNodeIds() as $id) {
            $errors[] = "Node id [{$id}] is used by more than one node. Node ids must be unique — ".
                'rename or remove the duplicate so each node keeps its own id.';
        }

        foreach ($graph->nodeIds() as $id) {
            $node = $graph->node($id);
            $type = $node['type'] ?? '';

            if (! $this->registry->has($type)) {
                $errors[] = "Node [{$id}] uses unknown type [{$type}].";

                continue;
            }

            $instance = $this->registry->resolve($type);

            foreach ($instance->validate($node['config'] ?? []) as $field => $messages) {
                $errors[] = "Node [{$id}] field [{$field}]: ".implode(' ', $messages);
            }
        }

        foreach ($graph->edges() as $edge) {
            if ($graph->node($edge['to']) === null) {
                $errors[] = "Edge from [{$edge['from']}] points at missing node [{$edge['to']}].";
            }

            $from = $graph->node($edge['from']);

            if ($from !== null && $this->registry->has($from['type'] ?? '')) {
                $outputs = $this->registry->resolve($from['type'])->definition()->outputNames();

                if (! in_array($edge['output'], $outputs, true)) {
                    $errors[] = "Node [{$edge['from']}] has no output [{$edge['output']}].";
                }
            }
        }

        if (($cycle = $this->findCycle($graph)) !== null) {
            $path = implode(' -> ', $cycle);
            $errors[] = "The flow contains a cycle: {$path}. Flows must be acyclic.";
        }

        if ($this->hasConcurrentWaits($graph)) {
            $warnings[] = 'Two or more branches contain waits. In this version, branch waits '.
                'run sequentially rather than concurrently, so total elapsed time is the sum, not the maximum.';
        }

        return new GraphValidationResult($errors, $warnings);
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
