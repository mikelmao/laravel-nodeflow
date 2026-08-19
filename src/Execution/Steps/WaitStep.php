<?php

namespace Nodeflow\Execution\Steps;

class WaitStep
{
    public function __construct(
        public readonly string $nodeId,
        public readonly string $duration,
    ) {}
}
