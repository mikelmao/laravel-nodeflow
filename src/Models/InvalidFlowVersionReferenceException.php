<?php

namespace Nodeflow\Models;

use RuntimeException;

class InvalidFlowVersionReferenceException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forMissing(string $modelClass, string $attribute, mixed $attemptedId): self
    {
        $value = $attemptedId === null ? 'null' : "[{$attemptedId}]";

        return new self(
            "Invalid FlowVersion reference: {$modelClass}.{$attribute} points to {$value}, "
            .'but that FlowVersion does not exist. The write was refused before persistence.'
        );
    }
}
