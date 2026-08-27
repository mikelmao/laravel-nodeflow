<?php

namespace Tests\Support;

use Closure;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesUniformAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class FakeUniformAudienceNode extends Node implements HandlesUniformAudience
{
    public static string $output = 'sent';

    public static array $chunks = [];

    public static ?Closure $handler = null;

    public static function type(): string
    {
        return 'test.uniform-audience';
    }

    public function audienceOutput(): string
    {
        return self::$output;
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Uniform audience')->outputs(['sent']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        self::$chunks[] = $context->subjectIds();

        return self::$handler instanceof Closure
            ? (self::$handler)($context, count(self::$chunks))
            : $context->all(self::$output);
    }

    public static function reset(): void
    {
        self::$output = 'sent';
        self::$chunks = [];
        self::$handler = null;
    }
}
