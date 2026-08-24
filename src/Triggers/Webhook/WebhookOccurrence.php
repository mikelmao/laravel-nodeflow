<?php

namespace Nodeflow\Triggers\Webhook;

final readonly class WebhookOccurrence
{
    public function __construct(
        public array $payload,
        public string $deliveryId,
        public int $timestamp,
    ) {}
}
