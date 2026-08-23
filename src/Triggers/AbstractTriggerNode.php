<?php

namespace Nodeflow\Triggers;

use Illuminate\Support\Facades\Validator;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Contracts\TriggerSource;

abstract class AbstractTriggerNode implements TriggerNode
{
    public function defaultConfig(): array
    {
        return [];
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return $this->validateForSourceType($config, $sources, TriggerSource::class);
    }

    /** @param  class-string<TriggerSource>  $sourceType */
    protected function validateForSourceType(
        array $config,
        TriggerSourceRegistry $sources,
        string $sourceType,
        array $additionalRules = [],
    ): array {
        $errors = Validator::make(
            $config,
            array_merge($this->definition()->rules(), $additionalRules),
        )
            ->errors()
            ->toArray();

        if (isset($errors['source']) || ! isset($config['source']) || ! is_string($config['source'])) {
            return $errors;
        }

        try {
            $source = $sources->resolve($this->driver(), $config['source']);
        } catch (\RuntimeException) {
            $errors['source'][] = "The selected source is not registered for driver [{$this->driver()}].";

            return $errors;
        }

        if (! $source instanceof $sourceType) {
            $errors['source'][] = "The selected source is not compatible with driver [{$this->driver()}].";
        }

        return $errors;
    }
}
