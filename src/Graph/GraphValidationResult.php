<?php

namespace Nodeflow\Graph;

class GraphValidationResult
{
    /**
     * @param  string[]  $errors  human-readable summaries, one per problem
     * @param  string[]  $warnings
     * @param  array<int, array{node: ?string, field: ?string, message: string}>  $nodeErrors
     *                                                                                       the same problems, keyed so an editor can render each beside its node
     */
    public function __construct(
        private array $errors = [],
        private array $warnings = [],
        private array $nodeErrors = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /** @return string[] */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return string[] */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * The same failures as errors(), structured.
     *
     * Kept alongside rather than replacing the strings: the strings are a fine
     * human-readable summary and the existing suite asserts on them, while an
     * editor needs to pin a message to a node card without parsing prose out of
     * "Node [w1] field [duration]: ...". `node` is null for a graph-level problem —
     * a cycle or a missing start node belongs to no single node, and attributing it
     * to one would put a red badge on an innocent card.
     *
     * @return array<int, array{node: ?string, field: ?string, message: string}>
     */
    public function nodeErrors(): array
    {
        return $this->nodeErrors;
    }
}
