<?php

namespace Nodeflow\Support;

use InvalidArgumentException;

/**
 * Validates identifiers that cross JSON-object and URL-path boundaries.
 *
 * The ASCII grammar deliberately excludes path separators, percent encoding,
 * whitespace, controls, and numeric-leading keys: [a-z][a-z0-9._-]*.
 */
final class StableKey
{
    public static function assert(string $key, string $name, int $maximum): string
    {
        if (preg_match('//u', $key) !== 1) {
            throw new InvalidArgumentException("The {$name} must contain valid UTF-8.");
        }

        if (strlen($key) > $maximum) {
            throw new InvalidArgumentException(
                "The {$name} must not be longer than {$maximum} characters."
            );
        }

        if (preg_match('/\A[a-z]/', $key) !== 1) {
            throw new InvalidArgumentException("The {$name} must start with a lowercase letter.");
        }

        if (preg_match('/\A[a-z][a-z0-9._-]*\z/D', $key) !== 1) {
            throw new InvalidArgumentException(
                "The {$name} may contain only lowercase letters, digits, dots, underscores, and hyphens."
            );
        }

        return $key;
    }
}
