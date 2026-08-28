<?php

namespace Nodeflow\Publishing;

use Nodeflow\Execution\NodeActivityPolicy;
use Nodeflow\Nodes\NodeRegistry;

class CompileNodeActivityPolicies
{
    public function __construct(
        private readonly NodeRegistry $nodes,
    ) {}

    public function compile(array $graph): array
    {
        foreach ($graph['nodes'] ?? [] as $index => $definition) {
            $type = $definition['type'] ?? null;

            if (! is_string($type) || ! $this->nodes->has($type)) {
                continue;
            }

            $runtime = is_array($definition['runtime'] ?? null)
                ? $definition['runtime']
                : [];
            $runtime['activity'] = NodeActivityPolicy::fromNode($this->nodes->resolve($type))->toArray();
            $definition['runtime'] = $runtime;
            $graph['nodes'][$index] = $definition;
        }

        return $graph;
    }
}
