<?php

namespace Nodeflow\Triggers;

use InvalidArgumentException;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Support\StableKey;
use RuntimeException;

class TriggerSourceRegistry
{
    /** @var array<string, class-string<TriggerSource>> */
    private array $sources = [];

    /** @var array<string, TriggerSource> */
    private array $instances = [];

    public function __construct(
        private readonly TriggerDriverRegistry $drivers,
    ) {}

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            if (! is_a($class, TriggerSource::class, true)) {
                throw new InvalidArgumentException(
                    "[{$class}] cannot be registered as a trigger source: it does not implement ".TriggerSource::class.'.'
                );
            }

            $driverKey = StableKey::assert($class::driver(), 'trigger source driver key', 191);
            $sourceKey = StableKey::assert($class::key(), 'trigger source key', 191);
            $key = $this->compositeKey($driverKey, $sourceKey);

            if (! $this->drivers->has($driverKey)) {
                throw new InvalidArgumentException(
                    "Trigger source [{$sourceKey}] cannot be registered before its driver [{$driverKey}]."
                );
            }

            if (isset($this->sources[$key])) {
                if ($this->sources[$key] !== $class) {
                    throw new InvalidArgumentException(
                        "Trigger source [{$driverKey}:{$sourceKey}] is already registered by [{$this->sources[$key]}]."
                    );
                }

                continue;
            }

            $source = app($class);
            $this->sources[$key] = $class;
            $this->instances[$key] = $source;

            try {
                $this->drivers->resolve($driverKey)->sourceRegistered($source);
            } catch (\Throwable $e) {
                unset($this->sources[$key], $this->instances[$key]);

                throw $e;
            }
        }

        return $this;
    }

    public function has(string $driver, string $source): bool
    {
        return isset($this->sources[$this->compositeKey($driver, $source)]);
    }

    public function resolve(string $driver, string $source): TriggerSource
    {
        $key = $this->compositeKey($driver, $source);

        if (! isset($this->sources[$key])) {
            throw new RuntimeException("Unknown nodeflow trigger source [{$driver}:{$source}].");
        }

        return $this->instances[$key] ??= app($this->sources[$key]);
    }

    /** @return array<string, class-string<TriggerSource>> */
    public function all(): array
    {
        return $this->sources;
    }

    /** @return TriggerSource[] */
    public function forDriver(string $driver): array
    {
        return array_values(array_map(
            fn (string $key): TriggerSource => $this->resolve($driver, substr($key, strlen($driver) + 1)),
            array_keys(array_filter(
                $this->sources,
                fn (string $class): bool => $class::driver() === $driver,
            )),
        ));
    }

    private function compositeKey(string $driver, string $source): string
    {
        return $driver."\0".$source;
    }
}
