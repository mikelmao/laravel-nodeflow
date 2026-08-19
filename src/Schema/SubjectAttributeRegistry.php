<?php

namespace Nodeflow\Schema;

use RuntimeException;

class SubjectAttributeRegistry
{
    /** @var array<string, SubjectAttribute> */
    private array $attributes = [];

    public function register(SubjectAttribute ...$attributes): self
    {
        foreach ($attributes as $attribute) {
            $this->attributes[$attribute->key] = $attribute;
        }

        return $this;
    }

    public function options(): array
    {
        return array_map(fn (SubjectAttribute $a) => $a->label, $this->attributes);
    }

    public function has(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    public function value(string $key, mixed $subject): mixed
    {
        if (! isset($this->attributes[$key])) {
            throw new RuntimeException("Unknown subject attribute [{$key}].");
        }

        return $this->attributes[$key]->value($subject);
    }

    public function get(string $key): ?SubjectAttribute
    {
        return $this->attributes[$key] ?? null;
    }
}
