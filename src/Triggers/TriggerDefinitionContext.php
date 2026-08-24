<?php

namespace Nodeflow\Triggers;

use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Schema\TriggerDefinition;

/** Operation-local trigger definition snapshots. */
final class TriggerDefinitionContext
{
    /** @var array<class-string, TriggerDefinition> */
    private array $nodes = [];

    /** @var array<class-string, TriggerDefinition> */
    private array $sources = [];

    public function node(TriggerNode $node): TriggerDefinition
    {
        return $this->nodes[$node::class] ??= $node->definition();
    }

    public function source(TriggerSource $source): TriggerDefinition
    {
        return $this->sources[$source::class] ??= $source->definition();
    }
}
