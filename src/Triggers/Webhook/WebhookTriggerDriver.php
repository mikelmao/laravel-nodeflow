<?php

namespace Nodeflow\Triggers\Webhook;

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class WebhookTriggerDriver implements TriggerDriver
{
    public function __construct(
        private readonly TriggerSourceRegistry $sources,
    ) {}

    public static function key(): string
    {
        return 'webhook';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        // The webhook entry point is installed explicitly by the host in a later layer.
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the webhook driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The webhook source is not registered.']];
        }

        if (! $source instanceof WebhookTriggerSource) {
            return ['source' => ['The registered source is not a webhook trigger source.']];
        }

        return [];
    }
}
