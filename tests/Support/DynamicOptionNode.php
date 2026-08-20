<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

/** One field with a dynamic source, one with static options, so both paths are reachable. */
class DynamicOptionNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.dynamic_options';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Dynamic')->fields([
            Field::select('template')->optionsFrom(FakeOptionSource::class),
            Field::select('channel')->options(['sms' => 'SMS']),
        ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        return $context->continue();
    }
}
