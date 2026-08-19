<?php

namespace Nodeflow\Execution\Steps;

class RunNodeStep
{
    public function __construct(public readonly string $nodeId) {}
}
