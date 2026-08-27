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

    public static int $callCount = 0;

    public static int $totalSubjectCount = 0;

    public static int $maxChunkSize = 0;

    public static string $rollingHash = '';

    private static bool $retainChunks = true;

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
        $subjectIds = $context->subjectIds();
        self::$callCount++;
        self::$totalSubjectCount += count($subjectIds);
        self::$maxChunkSize = max(self::$maxChunkSize, count($subjectIds));

        foreach ($subjectIds as $subjectId) {
            self::$rollingHash = hash('sha256', self::$rollingHash."\0".$subjectId);
        }

        if (self::$retainChunks) {
            self::$chunks[] = $subjectIds;
        }

        return self::$handler instanceof Closure
            ? (self::$handler)($context, self::$callCount)
            : $context->all(self::$output);
    }

    public static function recordOnlyScalarMetrics(): void
    {
        self::$chunks = [];
        self::$callCount = 0;
        self::$totalSubjectCount = 0;
        self::$maxChunkSize = 0;
        self::$rollingHash = '';
        self::$retainChunks = false;
    }

    public static function reset(): void
    {
        self::$output = 'sent';
        self::$chunks = [];
        self::$handler = null;
        self::$callCount = 0;
        self::$totalSubjectCount = 0;
        self::$maxChunkSize = 0;
        self::$rollingHash = '';
        self::$retainChunks = true;
    }
}
