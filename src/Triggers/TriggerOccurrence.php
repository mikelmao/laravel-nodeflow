<?php

namespace Nodeflow\Triggers;

final readonly class TriggerOccurrence
{
    public function __construct(
        public readonly string $driver,
        public readonly string $source,
        public readonly mixed $payload,
        public readonly ?string $qualifier = null,
        /** @var TriggerActivationSnapshot[]|null */
        public readonly ?array $activations = null,
    ) {}
}
