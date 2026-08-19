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

        if ($graph->startNodeId() === '' || $graph->node($graph->startNodeId()) === null) {
            $errors[] = 'The flow has no valid start node.';
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

        if ($this->hasCycle($graph)) {
            $errors[] = 'The flow contains a cycle. Flows must be acyclic.';
        }

        if ($this->hasConcurrentWaits($graph)) {
            $warnings[] = 'Two or more branches contain waits. In this version, branch waits '.
                'run sequentially rather than concurrently, so total elapsed time is the sum, not the maximum.';
        }

        return new GraphValidationResult($errors, $warnings);
    }

    private function hasCycle(Graph $graph): bool
    {
        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $graph): bool {
            if (($state[$id] ?? 'new') === 'visiting') {
                return true;
            }

            if (($state[$id] ?? 'new') === 'done') {
                return false;
            }

            $state[$id] = 'visiting';

            foreach ($graph->edges() as $edge) {
                if ($edge['from'] === $id && $graph->node($edge['to']) !== null && $visit($edge['to'])) {
                    return true;
                }
            }

            $state[$id] = 'done';

            return false;
        };

        foreach ($graph->nodeIds() as $id) {
            if ($visit($id)) {
                return true;
            }
        }

        return false;
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
