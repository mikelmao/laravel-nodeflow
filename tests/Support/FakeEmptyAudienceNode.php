<?php

namespace Tests\Support;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

/**
 * Stands in for core.start_flow with exit_this_flow => true: hands the audience
 * somewhere else and names nobody in any output.
 */
class FakeEmptyAudienceNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'test.empty-audience';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Empty Audience')->outputs(['default']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return NodeResult::empty();
    }
}
