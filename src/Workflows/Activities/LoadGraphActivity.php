<?php

namespace Nodeflow\Workflows\Activities;

use Nodeflow\Models\Run;
use Workflow\V2\Activity;

class LoadGraphActivity extends Activity
{
    public function handle(int $runId): array
    {
        $run = Run::withoutTenancy()->with('flowVersion')->findOrFail($runId);

        $run->update(['status' => 'running', 'started_at' => now()]);

        return $run->flowVersion->graph;
    }
}
