<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

class FakeSendNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.send';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Send')
            ->outputs(['sent', 'failed'])
            ->fields([Field::select('channel')->options(['sms' => 'SMS'])->required()]);
    }

    public function defaultConfig(): array
    {
        return ['channel' => 'sms'];
    }

    public function forSubject(SubjectContext $c): NodeResult
    {
        return $c->continue('sent');
    }
}
