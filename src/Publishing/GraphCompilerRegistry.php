<?php

namespace Nodeflow\Publishing;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;

final class GraphCompilerRegistry
{
    /** @var array<string, GraphCompiler> */
    private array $compilers = [];

    public function register(GraphCompiler ...$compilers): self
    {
        foreach ($compilers as $compiler) {
            $key = StableKey::assert($compiler->key(), 'graph compiler key', 64);
            if (isset($this->compilers[$key])) {
                throw new InvalidArgumentException("Duplicate graph compiler [{$key}].");
            }
            $this->compilers[$key] = $compiler;
        }

        uasort($this->compilers, static fn (GraphCompiler $left, GraphCompiler $right): int =>
            $left->priority() <=> $right->priority() ?: strcmp($left->key(), $right->key())
        );

        return $this;
    }

    /** @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    public function compile(GraphCompilerContext $context, array $graph): array
    {
        foreach ($this->compilers as $compiler) {
            $graph = $compiler->compile($context, $graph);
        }

        return $graph;
    }

    /** @return array<string, GraphCompiler> */
    public function all(): array
    {
        return $this->compilers;
    }
}
