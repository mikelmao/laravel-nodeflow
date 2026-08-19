<?php

namespace Tests\Support;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;
use RuntimeException;

/**
 * forAudience() always throws. A bulk node's failure is not attributable to a
 * single subject, so NodeRunner must let it propagate rather than swallowing it.
 */
class FakeThrowingAudienceNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'test.throwing-audience';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Throwing Audience')->outputs(['ok']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        throw new RuntimeException('audience node exploded');
    }
}
