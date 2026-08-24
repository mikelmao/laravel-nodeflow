<?php

namespace Nodeflow\Nodes;

use Illuminate\Support\Facades\Validator;
use Nodeflow\Schema\NodeDefinition;

abstract class Node
{
    public const DEFAULT_TRIES = 3;

    /** @var list<int> */
    public const DEFAULT_BACKOFF = [1, 2, 5, 10, 15, 30, 60, 120];

    /** Stable `[a-z][a-z0-9._-]*` identifier (max 255). Never derive it from the class name. */
    abstract public static function type(): string;

    abstract public function definition(): NodeDefinition;

    public function defaultConfig(): array
    {
        return [];
    }

    /** Activity retry attempts for this node's body. */
    public int $tries = self::DEFAULT_TRIES;

    /** Activity retry backoff seconds for this node's body. */
    public int|array $backoff = self::DEFAULT_BACKOFF;

    /** Per-attempt activity start-to-close timeout in seconds. */
    public ?int $timeout = null;

    /** @var list<class-string<\Throwable>> Error types that must not be retried. */
    public array $nonRetryableErrorTypes = [];

    /** @return array<string, array<string>> field key => messages */
    public function validate(array $config): array
    {
        return Validator::make($config, $this->definition()->rules())
            ->errors()
            ->toArray();
    }
}
