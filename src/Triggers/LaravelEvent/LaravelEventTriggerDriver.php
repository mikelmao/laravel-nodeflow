<?php

namespace Nodeflow\Triggers\LaravelEvent;

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class LaravelEventTriggerDriver implements TriggerDriver
{
    public function __construct(
        private readonly TriggerSourceRegistry $sources,
    ) {}

    public static function key(): string
    {
        return 'event';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        // Event listeners are installed by the runtime implementation.
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the event driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The Laravel event source is not registered.']];
        }

        if (! $source instanceof LaravelEventTriggerSource) {
            return ['source' => ['The registered source is not a Laravel event trigger source.']];
        }

        return [];
    }
}
