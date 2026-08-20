<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\NodeDefinition;

/** Declares a source that does not implement OptionSource, to prove that fails loudly. */
class BadSourceNode extends Node implements HandlesSubject
{
    public static function type(): string
    {
        return 'test.bad_source';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Bad')->fields([
            Field::select('template')->optionsFrom(NotAnOptionSource::class),
        ]);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        return $context->continue();
    }
}
