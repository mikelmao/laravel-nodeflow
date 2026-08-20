<?php

namespace Nodeflow\Schema;

use RuntimeException;

class UnknownOptionSourceException extends RuntimeException
{
    public static function notAnOptionSource(string $class): self
    {
        return new self(
            "[{$class}] is declared as a field's option source but does not implement "
            .OptionSource::class.'. Implement it and return an array of value => label. '
            .'This is refused rather than treated as an empty option list, because an '
            .'empty list looks identical to a tenant that genuinely has no options yet.'
        );
    }
}
