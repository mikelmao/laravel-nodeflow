<?php

namespace Nodeflow\Triggers;

final readonly class TriggerActivationSnapshot
{
    public function __construct(
        public int $activationId,
        public int $flowId,
        public int $flowVersionId,
        public string $tenantId,
        public string $driver,
        public string $source,
        public ?string $qualifier,
        public string $triggerNodeId,
        public array $descriptor,
    ) {}
}
