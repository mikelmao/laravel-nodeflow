<?php

namespace Nodeflow\Facts;

use InvalidArgumentException;

final readonly class FactOption
{
    public function __construct(
        public bool|int|float|string $value,
        public string $label,
        public bool $active = true,
    ) {
        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('A fact option number must be finite.');
        }

        if ($label === '' || trim($label) !== $label || mb_strlen($label) > 255 || ! mb_check_encoding($label, 'UTF-8')) {
            throw new InvalidArgumentException('A fact option label must be a non-empty UTF-8 string of at most 255 characters.');
        }
    }

    /** @return array{value: bool|int|float|string, label: string, active: bool} */
    public function toArray(): array
    {
        return ['value' => $this->value, 'label' => $this->label, 'active' => $this->active];
    }
}
