<?php

namespace Nodeflow\Facts;

use Nodeflow\Execution\AudienceContext;

final readonly class FactResolutionContext
{
    /** @param array<string, mixed> $triggerData */
    public function __construct(
        public int $runId,
        public string $nodeId,
        public string $subjectType,
        public ?string $correlationId,
        public array $triggerData,
        public bool $isTest,
    ) {}

    public static function fromAudience(AudienceContext $context): self
    {
        $triggerData = $context->triggerData();

        return new self(
            runId: $context->runId(),
            nodeId: $context->nodeId(),
            subjectType: $context->subjectType(),
            correlationId: $context->correlationId(),
            triggerData: is_array($triggerData) ? $triggerData : [],
            isTest: $context->isTest(),
        );
    }
}

