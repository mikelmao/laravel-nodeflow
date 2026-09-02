<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;
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
    ) {}

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

