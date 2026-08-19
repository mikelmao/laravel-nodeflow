<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;
use Nodeflow\Triggers\SubFlowStarter;

class StartFlowNode extends Node implements HandlesAudience
{
    public static function type(): string
    {
        return 'core.start_flow';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Start Another Flow')
            ->group('Flow')
            ->outputs(['default'])
            ->fields([
                Field::select('flow_id')->label('Flow to start')->required(),
                Field::boolean('exit_this_flow')->label('Leave this flow afterwards')->default(true),
            ]);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        app(SubFlowStarter::class)->start(
            parentRun: $context->run(),
            flowId: (int) $context->config('flow_id'),
            subjectType: $context->subjectType(),
            subjectIds: $context->subjectIds(),
        );

        return $context->config('exit_this_flow', true)
            ? NodeResult::empty()
            : $context->all('default');
    }
}
