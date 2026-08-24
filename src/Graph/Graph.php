<?php

namespace Nodeflow\Graph;

use RuntimeException;

class Graph
{
    private function __construct(
        private string $start,
        private array $nodes,
        private array $edges,
        private array $duplicateIds = [],
    ) {}

    public static function fromArray(array $graph): self
    {
        $nodes = [];
        $seen = [];
        $duplicates = [];

        foreach ($graph['nodes'] ?? [] as $node) {
            $id = $node['id'];

            if (isset($seen[$id])) {
                $duplicates[$id] = true;
            }

            $seen[$id] = true;
            $nodes[$id] = $node;
        }

        return new self($graph['start'] ?? '', $nodes, $graph['edges'] ?? [], array_keys($duplicates));
    }

    public function startNodeId(): string
    {
        return $this->start;
    }

    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function nodeIds(): array
    {
        return array_keys($this->nodes);
    }

    /** @return string[] */
    public function triggerNodeIds(GraphTypeCatalog $types): array
    {
        return array_values(array_filter(
            $this->nodeIds(),
            fn (string $id): bool => $types->family($this->nodes[$id]['type'] ?? '') === 'trigger',
        ));
    }

    public function edges(): array
    {
        return $this->edges;
    }

    public function incomingEdges(string $id): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (array $edge): bool => ($edge['to'] ?? null) === $id,
        ));
    }

    public function entryNodeId(GraphTypeCatalog $types): string
    {
        $triggers = $this->triggerNodeIds($types);

        if (count($triggers) !== 1) {
            throw new RuntimeException('The graph must contain exactly one trigger node.');
        }

        $trigger = $triggers[0];
        $targets = $this->targetsFor($trigger, 'started');

        if (count($targets) !== 1) {
            throw new RuntimeException("Trigger node [{$trigger}] must have exactly one [started] edge target.");
        }

        $entry = $targets[0];

        if ($types->family($this->node($entry)['type'] ?? '') !== 'executable') {
            throw new RuntimeException("Trigger node [{$trigger}] must start an executable node.");
        }

        return $entry;
    }

    /**
     * Node ids that appeared more than once in the raw input. Because nodes are keyed by id
     * internally, a duplicate silently collapses to "last one wins" unless callers check this.
     *
     * @return string[]
     */
    public function duplicateNodeIds(): array
    {
        return $this->duplicateIds;
    }

    /** @return string[] */
    public function targetsFor(string $nodeId, string $output): array
    {
        return array_values(array_map(
            fn (array $e) => $e['to'],
            array_filter(
                $this->edges,
                fn (array $e) => $e['from'] === $nodeId && $e['output'] === $output,
            ),
        ));
    }

    public function toArray(): array
    {
        return [
            'start' => $this->start,
            'nodes' => array_values($this->nodes),
            'edges' => $this->edges,
        ];
    }
}
