<?php

namespace Nodeflow\Engine;

use LogicException;
use Workflow\V2\StartOptions;
use Workflow\V2\WorkflowStub;

/**
 * Adapts durable-workflow/workflow's v2 stub API to Nodeflow's engine facade.
 *
 * The installed package (2.0.0-rc.32) ships two parallel stub classes:
 * Workflow\WorkflowStub (v1, namespace-root) and Workflow\V2\WorkflowStub.
 * Only the V2 stub exposes generic, workflow-class-agnostic cancel()/signal()/
 * running() methods; the v1 stub only supports signalling/cancelling through
 * per-workflow #[SignalMethod]-attributed methods invoked via __call, which
 * silently no-ops for unrecognised method names. Nodeflow needs a facade that
 * works uniformly across arbitrary workflow classes, so this adapter is
 * pinned to Workflow\V2\WorkflowStub.
 */
class DurableWorkflowEngine implements WorkflowEngine
{
    public function start(string $workflowClass, array $args, ?string $instanceId = null): string
    {
        $stub = WorkflowStub::make($workflowClass, $instanceId);

        if ($instanceId === null) {
            $stub->start(...array_values($args));

            return $stub->id();
        }

        $arguments = [...array_values($args), StartOptions::returnExistingActive()];
        $result = $stub->attemptStart(...$arguments);

        if ($result->rejected() && ! $result->rejectedDuplicate()) {
            throw new LogicException(
                $result->message() ?? "Workflow instance [{$instanceId}] could not be started."
            );
        }

        return $stub->id();
    }

    public function signal(string $workflowId, string $method, array $args = []): void
    {
        WorkflowStub::load($workflowId)->signal($method, ...$args);
    }

    public function cancel(string $workflowId): void
    {
        WorkflowStub::load($workflowId)->cancel();
    }

    public function isRunning(string $workflowId): bool
    {
        return WorkflowStub::load($workflowId)->running();
    }
}
