<?php

namespace Nodeflow\Nodes;

use RuntimeException;

class UnknownNodeTypeException extends RuntimeException
{
    public function __construct(public readonly string $type)
    {
        parent::__construct("Unknown nodeflow node type [{$type}]. It is not registered and has no alias.");
    }
}
