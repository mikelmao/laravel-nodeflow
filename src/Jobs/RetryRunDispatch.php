<?php

namespace Nodeflow\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Nodeflow\Execution\CreateRun;

final class RetryRunDispatch implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(public int|string $runId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(CreateRun $createRun): void
    {
        $createRun->resume($this->runId);
    }
}
