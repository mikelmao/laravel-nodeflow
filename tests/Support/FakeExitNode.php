<?php

namespace Tests\Support;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class FakeExitNode extends Node
{
    public static function type(): string
    {
        return 'core.exit';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Exit')->outputs([]);
    }
}
