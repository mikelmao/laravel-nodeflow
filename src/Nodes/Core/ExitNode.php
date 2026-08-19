<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class ExitNode extends Node implements HandlesAudience
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

    /**
     * Exit is a real, executed terminal node, not a marker the interpreter skips:
     * InterpreterLoop puts every advanced-to node in its cursor, so whatever is
     * here does run. Naming no subject in any output is the whole point — every
     * subject that arrived has left the flow successfully, and
     * NodeRunner::advance() reconciles them to status='completed' with no cursor.
     *
     * It is HandlesAudience rather than HandlesSubject so that a six-figure
     * cohort terminates in a handful of audience-sized chunks instead of one
     * pointless per-subject call each.
     */
    public function forAudience(AudienceContext $context): NodeResult
    {
        return NodeResult::empty();
    }
}
