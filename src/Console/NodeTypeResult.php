<?php

namespace Nodeflow\Console;

/**
 * What NodeTypeLiteral could prove about a node's type() method.
 *
 * A result object rather than a nullable string because the refusal message is
 * the product: E36 refuses several distinct shapes and each must name itself, so
 * an author can see which rule they hit and what to change.
 */
final class NodeTypeResult
{
    private function __construct(
        public readonly ?string $type,
        public readonly ?string $reason,
    ) {}

    public static function proven(string $type): self
    {
        return new self($type, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }

    public function ok(): bool
    {
        return $this->type !== null;
    }
}
