<?php

namespace Tests\Support;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class FakeExitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.exit';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Exit')->outputs([]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return NodeResult::empty();
    }
}
