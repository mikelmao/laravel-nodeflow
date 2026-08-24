<?php

namespace Nodeflow\Nodes;

use Illuminate\Support\Facades\Validator;
use Nodeflow\Schema\NodeDefinition;

abstract class Node
{
    /** Stable `[a-z][a-z0-9._-]*` identifier (max 255). Never derive it from the class name. */
    abstract public static function type(): string;

    abstract public function definition(): NodeDefinition;

    public function defaultConfig(): array
    {
        return [];
    }

    /** Activity retry attempts for this node's body. */
    public int $tries = 3;

    /** @return array<string, array<string>> field key => messages */
    public function validate(array $config): array
    {
        return Validator::make($config, $this->definition()->rules())
            ->errors()
            ->toArray();
    }
}
