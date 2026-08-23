<?php

namespace Nodeflow\Graph;

use InvalidArgumentException;

final class InvalidGraphTypeRegistration extends InvalidArgumentException
{
    /**
     * @param  array{string, string}  $registered
     * @param  array{string, string}  $candidate
     */
    public static function collision(string $type, array $registered, array $candidate): self
    {
        return new self(
            "Graph type [{$type}] is already registered by {$registered[0]} node [{$registered[1]}] "
            ."and cannot be claimed by {$candidate[0]} node [{$candidate[1]}]."
        );
    }
}
