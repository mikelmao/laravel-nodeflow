<?php

namespace Tests\Support;

use Nodeflow\Execution\NodeResult;
use Nodeflow\Execution\SubjectContext;
use Nodeflow\Nodes\HandlesSubject;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

/**
 * Records, per node id, the subject ids this node was actually asked to send to.
 * That per-node granularity is what lets the canonical-journey test prove a
 * subject who exited mid-wait receives no later message.
 */
class JourneySendNode extends Node implements HandlesSubject
{
    /** @var array<string, string[]> nodeId => subjectIds, in the order seen */
    public static array $sends = [];

    public static function reset(): void
    {
        static::$sends = [];
    }

    public static function sentAt(string $nodeId): array
    {
        return static::$sends[$nodeId] ?? [];
    }

    public static function type(): string
    {
        return 'test.journey_send';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Journey Send')->outputs(['sent']);
    }

    public function forSubject(SubjectContext $context): NodeResult
    {
        static::$sends[$context->nodeId()][] = $context->subjectId();

        return $context->continue('sent');
    }
}
