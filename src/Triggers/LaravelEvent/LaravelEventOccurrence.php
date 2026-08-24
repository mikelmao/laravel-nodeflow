<?php

namespace Nodeflow\Triggers\LaravelEvent;

use InvalidArgumentException;
use ReflectionReference;

final readonly class LaravelEventOccurrence
{
    private const MAX_DEPTH = 64;

    private const MAX_VALUES = 10000;

    public string $eventClass;

    /** @var array<string|int, mixed> */
    public array $data;

    /** @param  array<string|int, mixed>  $data */
    public function __construct(
        string $eventClass,
        array $data,
    ) {
        if (trim($eventClass) === '') {
            throw new InvalidArgumentException('A Laravel event occurrence must name its event class.');
        }

        $activeReferences = [];
        $values = 0;

        $this->eventClass = $eventClass;
        $this->data = self::normalizeArray(
            $data,
            depth: 0,
            activeReferences: $activeReferences,
            values: $values,
        );
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @param  array<string, true>  $activeReferences
     * @return array<string|int, mixed>
     */
    private static function normalizeArray(
        array $value,
        int $depth,
        array &$activeReferences,
        int &$values,
    ): array {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Laravel event occurrence value data exceeds the maximum depth.'
            );
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $values++;

            if ($values > self::MAX_VALUES) {
                throw new InvalidArgumentException(
                    'Laravel event occurrence value data exceeds the maximum value count.'
                );
            }

            $reference = ReflectionReference::fromArrayElement($value, $key);

            if ($reference === null) {
                $normalized[$key] = self::normalizeValue(
                    $item,
                    $depth + 1,
                    $activeReferences,
                    $values,
                );

                continue;
            }

            $referenceId = $reference->getId();

            if (isset($activeReferences[$referenceId])) {
                throw new InvalidArgumentException(
                    'Laravel event occurrence value data contains a recursive reference.'
                );
            }

            $activeReferences[$referenceId] = true;

            try {
                $normalized[$key] = self::normalizeValue(
                    $item,
                    $depth + 1,
                    $activeReferences,
                    $values,
                );
            } finally {
                unset($activeReferences[$referenceId]);
            }
        }

        return $normalized;
    }

    /** @param  array<string, true>  $activeReferences */
    private static function normalizeValue(
        mixed $value,
        int $depth,
        array &$activeReferences,
        int &$values,
    ): mixed
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return $value;
            }

            throw new InvalidArgumentException(
                'Laravel event occurrences may contain only finite JSON-safe value data.'
            );
        }

        if (is_array($value)) {
            return self::normalizeArray($value, $depth, $activeReferences, $values);
        }

        throw new InvalidArgumentException(
            'Laravel event occurrences may contain only immutable JSON-safe value data.'
        );
    }
}
