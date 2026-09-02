<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;

final readonly class FactDefinition
{
    /** @var list<string> */
    public array $capabilities;

    /** @var array<string, list<string>> */
    public array $operators;

    /** @var list<FactOption> */
    public array $options;

    /**
     * @param list<string> $capabilities
     * @param array<string, list<string>> $operators
     * @param list<FactOption> $options
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $label,
        public FactValueType $type,
        array $capabilities,
        array $operators,
        array $options = [],
        public MissingFactBehavior $missingBehavior = MissingFactBehavior::RouteNo,
    ) {
        StableKey::assert($key, 'fact key', 191);

        if ($version < 1) {
            throw new InvalidArgumentException('A fact version must be positive.');
        }

        if ($label === '' || trim($label) !== $label || mb_strlen($label) > 191 || ! mb_check_encoding($label, 'UTF-8')) {
            throw new InvalidArgumentException('A fact label must be a non-empty UTF-8 string of at most 191 characters.');
        }

        if ($capabilities === [] || ! array_is_list($capabilities)) {
            throw new InvalidArgumentException('Fact capabilities must be a non-empty list.');
        }

        foreach ($capabilities as $capability) {
            if (! is_string($capability)) {
                throw new InvalidArgumentException('Every fact capability must be a stable string key.');
            }
            StableKey::assert($capability, 'fact capability', 64);
        }

        $capabilities = array_values(array_unique($capabilities));
        sort($capabilities, SORT_STRING);

        if (array_keys($operators) !== $capabilities && array_diff(array_keys($operators), $capabilities) !== []) {
            throw new InvalidArgumentException('Fact operators must be declared only for supported capabilities.');
        }

        foreach ($capabilities as $capability) {
            $supported = $operators[$capability] ?? null;
            if (! is_array($supported) || ! array_is_list($supported) || $supported === []) {
                throw new InvalidArgumentException("Fact operators for [{$capability}] must be a non-empty list.");
            }

            foreach ($supported as $operator) {
                if (! is_string($operator)) {
                    throw new InvalidArgumentException('Every fact operator must be a stable string key.');
                }
                StableKey::assert($operator, 'fact operator', 64);
            }

            $supported = array_values(array_unique($supported));
            sort($supported, SORT_STRING);
            $operators[$capability] = $supported;
        }
        ksort($operators, SORT_STRING);

        if (! array_is_list($options)) {
            throw new InvalidArgumentException('Fact options must be a list.');
        }

        $seen = [];
        foreach ($options as $option) {
            if (! $option instanceof FactOption || ! $type->accepts($option->value)) {
                throw new InvalidArgumentException('Every fact option value must match the fact type.');
            }

            $identity = self::valueIdentity($option->value);
            if (isset($seen[$identity])) {
                throw new InvalidArgumentException('Fact option values must be unique.');
            }
            $seen[$identity] = true;
        }
        usort($options, static fn (FactOption $left, FactOption $right): int => strcmp(
            self::valueIdentity($left->value),
            self::valueIdentity($right->value),
        ));

        $this->capabilities = $capabilities;
        $this->operators = $operators;
        $this->options = $options;
    }

    public function supports(string $capability, string $operator): bool
    {
        return in_array($operator, $this->operators[$capability] ?? [], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'label' => $this->label,
            'type' => $this->type->value,
            'capabilities' => $this->capabilities,
            'operators' => $this->operators,
            'options' => array_map(static fn (FactOption $option): array => $option->toArray(), $this->options),
            'missing_behavior' => $this->missingBehavior->value,
        ];
    }

    public static function valueIdentity(bool|int|float|string $value): string
    {
        return get_debug_type($value).':'.json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

