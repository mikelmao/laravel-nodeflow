<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;
use RuntimeException;

/**
 * forSubject() throws for a specific subject id, so tests can verify that
 * NodeRunner isolates the failure to that subject rather than aborting the chunk.
 */
class FakeThrowingSubjectNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.throwing-subject';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Throwing Subject')->outputs(['ok']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        if ($context->subjectId() === '2') {
            throw new RuntimeException('boom for subject 2');
        }

        return $context->continue('ok');
    }
}
