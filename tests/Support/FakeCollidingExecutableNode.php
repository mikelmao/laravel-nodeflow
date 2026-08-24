<?php

namespace Tests\Support;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class FakeCollidingExecutableNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'test.fake_trigger';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Colliding executable');
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return NodeResult::empty();
    }
}
