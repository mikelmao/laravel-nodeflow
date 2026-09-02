<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
use Nodeflow\Support\StableKey;
use Normalizer;

final readonly class CompiledFactPredicate
{
    public function __construct(
        public string $provider,
        public string $key,
        public int $version,
        public FactValueType $type,
        public string $operator,
        public mixed $value,
        public MissingFactBehavior $missingBehavior,
        public string $catalogueRevision,
    ) {
        StableKey::assert($provider, 'fact provider key', 64);
        StableKey::assert($key, 'fact key', 255);
        StableKey::assert($operator, 'fact operator', 64);
        if ($version < 1 || $catalogueRevision === '' || strlen($catalogueRevision) > 191 || preg_match('//u', $catalogueRevision) !== 1) {
            throw new InvalidArgumentException('A compiled fact predicate has invalid pinned metadata.');
        }

        if ($operator === 'in') {
            if (! is_array($value) || ! array_is_list($value) || $value === []) {
                throw new InvalidArgumentException('A compiled in predicate must contain a non-empty list.');
            }
            foreach ($value as $item) {
                if (! $type->accepts($item)) {
                    throw new InvalidArgumentException('A compiled fact predicate value does not match its type.');
                }
            }
        } elseif (! $type->accepts($value)) {
            throw new InvalidArgumentException('A compiled fact predicate value does not match its type.');
        }
    }

    public static function fromArray(array $value): self
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        $expected = ['catalogue_revision', 'key', 'missing_behavior', 'operator', 'provider', 'type', 'value', 'version'];
        if ($keys !== $expected
            || ! is_string($value['provider'] ?? null)
            || ! is_string($value['key'] ?? null)
            || ! is_int($value['version'] ?? null)
            || ! is_string($value['type'] ?? null)
            || ! is_string($value['operator'] ?? null)
            || ! is_string($value['missing_behavior'] ?? null)
            || ! is_string($value['catalogue_revision'] ?? null)) {
            throw new InvalidArgumentException('A compiled fact predicate must contain exactly the pinned predicate fields.');
        }

        $type = FactValueType::tryFrom($value['type']);
        $missing = MissingFactBehavior::tryFrom($value['missing_behavior']);
        if ($type === null || $missing === null) {
            throw new InvalidArgumentException('A compiled fact predicate contains unsupported pinned metadata.');
        }

        return new self(
            $value['provider'],
            $value['key'],
            $value['version'],
            $type,
            $value['operator'],
            $value['value'],
            $missing,
            $value['catalogue_revision'],
        );
    }

    public static function compile(
        FactPredicate $predicate,
        FactDefinition $definition,
        string $capability,
        string $catalogueRevision,
    ): self {
        if ($predicate->key !== $definition->key || $predicate->version !== $definition->version) {
            throw new InvalidArgumentException('The fact predicate does not match the selected definition.');
        }
        if (! $definition->supports($capability, $predicate->operator)) {
            throw new InvalidArgumentException('The fact operator is unavailable for this capability.');
        }

        $value = $predicate->operator === 'in'
            ? self::canonicalList($predicate->value, $definition->type)
            : self::canonicalScalar($predicate->value, $definition->type);

        if ($definition->options !== []) {
            $known = array_map(static fn (FactOption $option): mixed => $option->value, $definition->options);
            foreach (is_array($value) ? $value : [$value] as $candidate) {
                if (! in_array($candidate, $known, true)) {
                    throw new InvalidArgumentException('The fact predicate contains an unavailable option.');
                }
            }
        }

        return new self(
            $predicate->provider,
            $predicate->key,
            $predicate->version,
            $definition->type,
            $predicate->operator,
            $value,
            $definition->missingBehavior,
            $catalogueRevision,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'key' => $this->key,
            'version' => $this->version,
            'type' => $this->type->value,
            'operator' => $this->operator,
            'value' => $this->value,
            'missing_behavior' => $this->missingBehavior->value,
            'catalogue_revision' => $this->catalogueRevision,
        ];
    }

    private static function canonicalList(mixed $value, FactValueType $type): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
            throw new InvalidArgumentException('The in operator requires a non-empty list of at most 100 values.');
        }

        $canonical = array_map(static fn (mixed $item): mixed => self::canonicalScalar($item, $type), $value);
        $byIdentity = [];
        foreach ($canonical as $item) {
            $byIdentity[FactDefinition::valueIdentity($item)] = $item;
        }
        ksort($byIdentity, SORT_STRING);

        return array_values($byIdentity);
    }

    private static function canonicalScalar(mixed $value, FactValueType $type): bool|int|float|string
    {
        if (! $type->accepts($value)) {
            throw new InvalidArgumentException('The fact predicate value does not match the fact type.');
        }

        if ($type !== FactValueType::Text) {
            return $value;
        }

        if (! mb_check_encoding($value, 'UTF-8') || $value === '' || trim($value) !== $value || str_contains($value, "\0") || mb_strlen($value) > 255) {
            throw new InvalidArgumentException('A text fact value must be a non-empty UTF-8 string of at most 255 characters.');
        }

        $normalised = Normalizer::normalize($value, Normalizer::FORM_C);
        if (! is_string($normalised)) {
            throw new InvalidArgumentException('The text fact value cannot be normalized.');
        }

        return $normalised;
    }
}
