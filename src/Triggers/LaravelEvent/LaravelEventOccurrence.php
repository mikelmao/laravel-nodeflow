<?php

namespace Nodeflow\Triggers\LaravelEvent;

use InvalidArgumentException;

final readonly class LaravelEventOccurrence
{
    /** @param  array<string|int, mixed>  $data */
    public function __construct(
        public string $eventClass,
        public array $data,
    ) {
        if (trim($eventClass) === '') {
            throw new InvalidArgumentException('A Laravel event occurrence must name its event class.');
        }

        self::assertValueData($data);
    }

    private static function assertValueData(mixed $value): void
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return;
        }

        if (is_float($value)) {
            if (is_finite($value)) {
                return;
            }

            throw new InvalidArgumentException(
                'Laravel event occurrences may contain only finite JSON-safe value data.'
            );
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertValueData($item);
            }

            return;
        }

        throw new InvalidArgumentException(
            'Laravel event occurrences may contain only immutable JSON-safe value data.'
        );
    }
}
