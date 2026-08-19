<?php

namespace Nodeflow\Nodes;

use InvalidArgumentException;

/**
 * A node class that cannot be executed by the runtime as written. Thrown at
 * registration rather than at execution: the alternative — discovering it when
 * the first real subject reaches the node, days into a live journey — is exactly
 * the failure this exception exists to prevent.
 */
class InvalidNodeException extends InvalidArgumentException
{
    public static function noCardinality(string $class): self
    {
        return new self(
            "Node [{$class}] implements neither ".HandlesSubject::class.' nor '.HandlesAudience::class.'. '
            .'Every node must declare at least one cardinality: implement HandlesSubject for per-subject '
            .'work (the runtime handles chunking, iteration and per-subject failure isolation), '
            .'HandlesAudience for nodes that batch natively, or both. Declaring the method without the '
            .'interface is not enough — the runtime dispatches on the interface.'
        );
    }
}
