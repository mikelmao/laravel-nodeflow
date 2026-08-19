<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Models\Run;
use Workflow\V2\Activity;

class CompleteRunActivity extends Activity
{
    public function handle(int $runId): void
    {
        Run::withoutTenancy()->where('id', $runId)
            ->update(['status' => 'completed', 'ended_at' => now()]);
    }
}
