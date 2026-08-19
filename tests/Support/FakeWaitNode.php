<?php

namespace Tests\Support;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FakeWaitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.wait';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Wait')
            ->outputs(['default'])
            ->fields([Field::duration('duration')->required()]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->all('default');
    }
}
