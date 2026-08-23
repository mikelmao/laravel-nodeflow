<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Contracts\TriggerDriver;
use RuntimeException;

class TriggerDriverRegistry
{
    /** @var array<string, class-string<TriggerDriver>> */
    private array $drivers = [];

    /** @var array<string, TriggerDriver> */
    private array $instances = [];

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (! is_a($class, TriggerDriver::class, true)) {
                throw new InvalidArgumentException(
                    "[{$class}] cannot be registered as a trigger driver: it does not implement ".TriggerDriver::class.'.'
                );
            }

            $key = $class::key();

            if (isset($this->drivers[$key]) && $this->drivers[$key] !== $class) {
                throw new InvalidArgumentException(
                    "Trigger driver key [{$key}] is already registered by [{$this->drivers[$key]}]."
                );
            }

            $this->drivers[$key] = $class;
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->drivers[$key]);
    }

    public function resolve(string $key): TriggerDriver
    {
        if (! isset($this->drivers[$key])) {
            throw new RuntimeException("Unknown nodeflow trigger driver [{$key}].");
        }

        return $this->instances[$key] ??= app($this->drivers[$key]);
    }

    /** @return array<string, class-string<TriggerDriver>> */
    public function all(): array
    {
        return $this->drivers;
    }
}
