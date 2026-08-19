<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class RecordingSendNode extends Node implements HandlesSubject
{
    public static array $sent = [];

    public static array $wouldHaveSent = [];

    public static function type(): string
    {
        return 'test.recording';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Recording Send')->outputs(['sent']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->isTest()) {
            static::$wouldHaveSent[] = $context->subjectId();
        } else {
            static::$sent[] = $context->subjectId();
        }

        return $context->continue('sent');
    }
}
