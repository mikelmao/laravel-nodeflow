<?php

namespace Tests\Support;

use InvalidArgumentException;
use Nodeflow\Execution\AudienceContext;
use Nodeflow\Execution\NodeResult;
use Nodeflow\Nodes\HandlesAudience;
use Nodeflow\Nodes\Node;
use Nodeflow\Schema\NodeDefinition;

class FakeRetryingAudienceNode extends Node implements HandlesAudience
{
    public int $tries = 5;

    public int|array $backoff = [1, 5, 30, 120];

    public ?int $timeout = 90;

    /** @var list<class-string<\Throwable>> */
    public array $nonRetryableErrorTypes = [InvalidArgumentException::class];

    public static function type(): string
    {
        return 'test.retrying-audience';
    }

    public function definition(): NodeDefinition
    {
        return NodeDefinition::make('Retrying audience')->outputs(['accepted']);
    }

    public function forAudience(AudienceContext $context): NodeResult
    {
        return $context->all('accepted');
    }
}
