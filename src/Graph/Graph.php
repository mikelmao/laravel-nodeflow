<?php

namespace Nodeflow\Graph;

class Graph
{
    private function __construct(
        private string $start,
        private array $nodes,
        private array $edges,
    ) {}

    public static function fromArray(array $graph): self
    {
        $nodes = [];

        foreach ($graph['nodes'] ?? [] as $node) {
            $nodes[$node['id']] = $node;
        }

        return new self($graph['start'] ?? '', $nodes, $graph['edges'] ?? []);
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

    public function edges(): array
    {
        return $this->edges;
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
