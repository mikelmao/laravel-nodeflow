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

    public static function forFlowMismatch(
        string $modelClass,
        string $attribute,
        mixed $versionId,
        mixed $versionFlowId,
        mixed $expectedFlowId,
    ): self {
        return new self(
            "Invalid FlowVersion reference: {$modelClass}.{$attribute} points to FlowVersion [{$versionId}], "
            ."which belongs to Flow [{$versionFlowId}] and does not belong to Flow [{$expectedFlowId}]. "
            .'The write or execution was refused.'
        );
    }
}
