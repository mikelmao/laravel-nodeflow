<?php

namespace Nodeflow\Triggers;

final readonly class TriggerActivationDescriptor
{
    public function __construct(
        public string $driver,
        public string $source,
        public ?string $qualifier,
        public array $metadata,
    ) {}

    public function toArray(): array
    {
        return [
            'driver' => $this->driver,
            'source' => $this->source,
            'qualifier' => $this->qualifier,
            'metadata' => $this->metadata,
        ];
    }
}
