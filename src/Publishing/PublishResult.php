<?php

namespace Nodeflow\Publishing;

use Nodeflow\Models\FlowVersion;

final readonly class PublishResult
{
    public function __construct(
        public FlowVersion $version,
        public ?string $webhookUrl = null,
        public ?string $webhookSecret = null,
    ) {}
}
