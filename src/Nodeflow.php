<?php

namespace Nodeflow;

use Nodeflow\Nodes\NodeRegistry;

class Nodeflow
{
    public static function nodes(): NodeRegistry
    {
        return app(NodeRegistry::class);
    }

    public static function register(array $nodeClasses): void
    {
        static::nodes()->register(...$nodeClasses);
    }
}
