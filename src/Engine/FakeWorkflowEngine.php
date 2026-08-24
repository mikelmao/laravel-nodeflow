<?php

namespace Nodeflow\Engine;

class FakeWorkflowEngine implements WorkflowEngine
{
    private array $started = [];

    private array $signals = [];

    private array $cancelled = [];

    private int $nextId = 1;

    public function start(string $workflowClass, array $args, ?string $instanceId = null): string
    {
        $id = $instanceId ?? 'fake-'.$this->nextId++;

        if ($instanceId !== null && collect($this->started)->contains(fn ($start) => $start['id'] === $id)) {
            return $id;
        }

        $this->started[] = ['workflow' => $workflowClass, 'args' => $args, 'id' => $id];

        return $id;
    }

    public function signal(string $workflowId, string $method, array $args = []): void
    {
        $this->signals[] = ['id' => $workflowId, 'method' => $method, 'args' => $args];
    }

    public function cancel(string $workflowId): void
    {
        $this->cancelled[] = $workflowId;
    }

    public function isRunning(string $workflowId): bool
    {
        $wasStarted = collect($this->started)->contains(fn ($s) => $s['id'] === $workflowId);

        return $wasStarted && ! in_array($workflowId, $this->cancelled, true);
    }

    public function started(): array
    {
        return $this->started;
    }

    public function signals(): array
    {
        return $this->signals;
    }

    public function cancelled(): array
    {
        return $this->cancelled;
    }
}
