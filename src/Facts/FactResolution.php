<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;

final readonly class FactResolution
{
    private function __construct(
        public string $subjectId,
        public bool|int|float|string|null $value,
        public bool $missing,
    ) {
        if ($subjectId === '' || strlen($subjectId) > 191 || preg_match('//u', $subjectId) !== 1 || str_contains($subjectId, "\0")) {
            throw new InvalidArgumentException('A fact resolution subject ID must be a non-empty UTF-8 string of at most 191 characters.');
        }
        if (! $missing && $value === null) {
            throw new InvalidArgumentException('A present fact resolution must contain a value.');
        }
        if ($missing && $value !== null) {
            throw new InvalidArgumentException('A missing fact resolution cannot contain a value.');
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('A fact resolution number must be finite.');
        }
    }

    public static function present(string $subjectId, bool|int|float|string $value): self
    {
        return new self($subjectId, $value, false);
    }

    public static function missing(string $subjectId): self
    {
        return new self($subjectId, null, true);
    }

    /** @return array{subject_id: string, value: bool|int|float|string|null, missing: bool} */
    public function toArray(): array
    {
        return [
            'subject_id' => $this->subjectId,
            'value' => $this->value,
            'missing' => $this->missing,
        ];
    }
}

