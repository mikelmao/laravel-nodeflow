<?php

namespace Nodeflow\Engine;

interface WorkflowEngine
{
    /**
     * Infrastructure contract: deterministic recovery callers supply a stable
     * instance id; other callers may omit it and retain engine-generated ids.
     *
     * @return string the engine's workflow id
     */
    public function start(string $workflowClass, array $args, ?string $instanceId = null): string;

    public function signal(string $workflowId, string $method, array $args = []): void;

    public function cancel(string $workflowId): void;

    public function isRunning(string $workflowId): bool;
}
