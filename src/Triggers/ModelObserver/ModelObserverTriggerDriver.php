<?php

namespace Nodeflow\Triggers\ModelObserver;

use Nodeflow\Contracts\TriggerDriver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerSourceRegistry;

class ModelObserverTriggerDriver implements TriggerDriver
{
    public function __construct(
        private readonly TriggerSourceRegistry $sources,
    ) {}

    public static function key(): string
    {
        return 'model';
    }

    public function sourceRegistered(TriggerSource $source): void
    {
        // Model listeners are installed by the runtime implementation.
    }

    public function validate(TriggerActivationDescriptor $descriptor): array
    {
        if ($descriptor->driver !== self::key()) {
            return ['driver' => ['The activation descriptor does not use the model driver.']];
        }

        try {
            $source = $this->sources->resolve(self::key(), $descriptor->source);
        } catch (\RuntimeException) {
            return ['source' => ['The model source is not registered.']];
        }

        if (! $source instanceof ModelObserverTriggerSource) {
            return ['source' => ['The registered source is not a model observer trigger source.']];
        }

        return [];
    }
}
