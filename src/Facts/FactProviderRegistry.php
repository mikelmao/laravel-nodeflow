<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;

final class FactProviderRegistry
{
    /** @var array<string, FactProvider> */
    private array $providers = [];

    public function register(FactProvider ...$providers): self
    {
        foreach ($providers as $provider) {
            $key = StableKey::assert($provider->key(), 'fact provider key', 64);
            if (isset($this->providers[$key])) {
                throw new InvalidArgumentException("Duplicate fact provider [{$key}].");
            }
            $this->providers[$key] = $provider;
        }

        ksort($this->providers, SORT_STRING);

        return $this;
    }

    public function get(string $key): FactProvider
    {
        return $this->providers[$key]
            ?? throw new InvalidArgumentException("Fact provider [{$key}] is not registered.");
    }

    /** @return array<string, FactProvider> */
    public function all(): array
    {
        return $this->providers;
    }
}

