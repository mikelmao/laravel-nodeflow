<?php

namespace Nodeflow\Nodes\Core;

use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Models\Run;
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
        // The context deliberately exposes only the run's id, not the Run model,
        // so the parent run is re-read here. SubFlowStarter needs the row's
        // tenant_id and correlation lineage, and this node runs at most once per
        // run per node, so the extra read costs nothing at cohort scale.
        $parentRun = Run::withoutTenancy()->findOrFail($context->runId());

        app(SubFlowStarter::class)->start(
            parentRun: $parentRun,
            flowId: (int) $context->config('flow_id'),
            subjectType: $context->subjectType(),
            subjectIds: $context->subjectIds(),
        );

        return $context->config('exit_this_flow', true)
            ? NodeResult::empty()
            : $context->all('default');
    }
}
