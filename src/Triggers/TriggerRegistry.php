<?php

namespace Nodeflow\Triggers;

use RuntimeException;

class TriggerRegistry
{
    /** @var array<string, class-string<Trigger>> */
    private array $types = [];

    public function register(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->types[$class::type()] = $class;
        }

        return $this;
    }

    public function has(string $type): bool
    {
        return isset($this->types[$type]);
    }

    public function resolve(string $type): Trigger
    {
        if (! isset($this->types[$type])) {
            throw new RuntimeException("Unknown nodeflow trigger type [{$type}].");
        }

        return app($this->types[$type]);
    }

    /** @return array<string, class-string<Trigger>> */
    public function all(): array
    {
        return $this->types;
    }

    /** @return Trigger[] */
    public function forEvent(string $eventClass): array
    {
        return array_values(array_map(
            fn (string $class) => app($class),
            array_filter($this->types, fn (string $class) => $class::event() === $eventClass),
        ));
    }

    public function palette(): array
    {
        return array_values(array_map(function (string $class, string $type) {
            return array_merge(app($class)->definition()->toArray(), ['type' => $type]);
        }, $this->types, array_keys($this->types)));
    }
}
