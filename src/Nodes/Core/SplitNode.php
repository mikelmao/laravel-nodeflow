<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class SplitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.split';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Split')
            ->group('Flow')
            ->description('Send every subject down all connected branches.')
            ->outputs(['a', 'b']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->partition([
            'a' => $context->subjectIds(),
            'b' => $context->subjectIds(),
        ]);
    }
}
