<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class WaitNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.wait';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Wait')
            ->group('Flow')
            ->description('Pause. Subjects that exit the flow during the wait do not continue.')
            ->outputs(['default'])
            ->fields([
                Field::duration('duration')
                    ->label('Wait for')
                    ->help('A relative duration such as "5 minutes", "1 day", "2 weeks".')
                    ->required(),
            ]);
    }

    /**
     * The timer itself is the interpreter's business. By the time this runs, the
     * wait has already elapsed and the audience has already been re-resolved, so
     * everyone still active simply moves on.
     */
    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->all('default');
    }
}
