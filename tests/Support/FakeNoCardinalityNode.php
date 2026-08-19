<?php

namespace Tests\Support;

use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

/**
 * Written exactly the way spec section 5's canonical example used to read: a
 * forSubject() method and no `implements HandlesSubject`. It must be impossible
 * to register or publish, because the runtime dispatches on the interface and
 * not on the method name.
 */
class FakeNoCardinalityNode extends Node
{
    public static function type(): string
    {
        return 'test.no-cardinality';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('No Cardinality')->outputs(['sent']);
    }

    public function forSubject($context)
    {
        return null;
    }
}
