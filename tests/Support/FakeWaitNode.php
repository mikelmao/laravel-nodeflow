<?php

namespace Tests\Support;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FakeWaitNode extends Node
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
}
