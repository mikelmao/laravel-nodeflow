<?php

namespace Nodeflow\Models;

use RuntimeException;

class InvalidTriggerActivationReferenceException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function forMissingFlow(mixed $flowId): self
    {
        $value = $flowId === null ? 'null' : "[{$flowId}]";

        return new self(
            "Invalid trigger activation reference: Flow {$value} does not exist. "
            .'The activation was refused before persistence.'
        );
    }

    public static function forVersionFlowMismatch(
        mixed $versionId,
        mixed $versionFlowId,
        mixed $activationFlowId,
    ): self {
        return new self(
            "Invalid trigger activation reference: FlowVersion [{$versionId}] belongs to Flow "
            ."[{$versionFlowId}] and does not belong to Flow [{$activationFlowId}]. "
            .'An activation must reference a version of its own Flow.'
        );
    }
}
