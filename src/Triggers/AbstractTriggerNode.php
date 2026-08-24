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

    public function source(array $config): string
    {
        return is_string($config['source'] ?? null) ? $config['source'] : '';
    }

    public function supportsSource(TriggerSource $source): bool
    {
        $sourceType = $this->sourceType();

        return $source::driver() === $this->driver() && $source instanceof $sourceType;
    }

    /** @return class-string<TriggerSource> */
    protected function sourceType(): string
    {
        return TriggerSource::class;
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return $this->validateForSourceType($config, $sources);
    }

    protected function validateForSourceType(
        array $config,
        TriggerSourceRegistry $sources,
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

        if (! $this->supportsSource($source)) {
            $errors['source'][] = "The selected source is not compatible with driver [{$this->driver()}].";
        }

        return $errors;
    }
}
