<?php

namespace Nodeflow\Triggers;

use Nodeflow\Execution\StartRun;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;

class SubFlowStarter
{
    public const MAX_DEPTH = 5;

    public function __construct(private StartRun $startRun) {}

    public function start(Run $parentRun, int $flowId, string $subjectType, array $subjectIds): ?Run
    {
        $lineage = array_filter(explode('>', (string) $parentRun->correlation_id));

        if (count($lineage) >= self::MAX_DEPTH) {
            return null;
        }

        $flow = Flow::withoutTenancy()
            ->where('id', $flowId)
            ->where('tenant_id', $parentRun->tenant_id)
            ->firstOrFail();

        return $this->startRun->forFlow($flow, $subjectType, $subjectIds, [
            'correlation_id' => trim(($parentRun->correlation_id ?? '').'>'.$parentRun->id, '>'),
            'is_test' => $parentRun->is_test,
        ]);
    }
}
