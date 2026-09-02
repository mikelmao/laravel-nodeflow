<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;

final readonly class FactPredicate
{
    public function __construct(
        public string $provider,
        public string $key,
        public int $version,
        public string $operator,
        public mixed $value,
    ) {
        StableKey::assert($provider, 'fact provider key', 64);
        StableKey::assert($key, 'fact key', 191);
        StableKey::assert($operator, 'fact operator', 64);
        if ($version < 1) {
            throw new InvalidArgumentException('A fact version must be positive.');
        }
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = ['key', 'operator', 'provider', 'value', 'version'];

        if ($keys !== $expected
            || ! is_string($value['provider'] ?? null)
            || ! is_string($value['key'] ?? null)
            || ! is_int($value['version'] ?? null)
            || ! is_string($value['operator'] ?? null)) {
            throw new InvalidArgumentException('An authored fact predicate must contain exactly provider, key, version, operator, and value.');
        }

        return new self($value['provider'], $value['key'], $value['version'], $value['operator'], $value['value']);
    }

    /** @return array{provider: string, key: string, version: int, operator: string, value: mixed} */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'key' => $this->key,
            'version' => $this->version,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }
}

