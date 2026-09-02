<?php

namespace Nodeflow\Publishing;

use Nodeflow\Models\Flow;
use Nodeflow\Triggers\TriggerDefinitionContext;

final readonly class GraphCompilerContext
{
    public function __construct(
        public Flow $flow,
        public int $expectedDraftRevision,
        public TriggerDefinitionContext $triggerDefinitions,
    ) {}
}
