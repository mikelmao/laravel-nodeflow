<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class ExitNode extends Node
{
    public static function type(): string
    {
        return 'core.exit';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Exit')
            ->group('Flow')
            ->description('Subjects reaching this node leave the flow successfully.')
            ->outputs([]);
    }
}
