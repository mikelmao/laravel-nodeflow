<?php

namespace Nodeflow\Engine;

interface WorkflowEngine
{
    /** @return string the engine's workflow id */
    public function start(string $workflowClass, array $args): string;

    public function signal(string $workflowId, string $method, array $args = []): void;

    public function cancel(string $workflowId): void;

    public function isRunning(string $workflowId): bool;
}
