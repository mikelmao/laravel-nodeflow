<?php

namespace Nodeflow\Publishing;

interface GraphCompiler
{
    public function key(): string;

    public function priority(): int;

    /** @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    public function compile(GraphCompilerContext $context, array $graph): array;
}

