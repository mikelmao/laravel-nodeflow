<?php

namespace Nodeflow\Execution;

use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Run;

class AudienceMaterialiser
{
    public function __construct(private TenantResolver $tenants) {}

    public function materialise(Run $run, string $subjectType, iterable $subjectIds, ?string $startNodeId = null): int
    {
        $seen = [];

        foreach ($subjectIds as $subjectId) {
            $subjectId = (string) $subjectId;

            if (isset($seen[$subjectId])) {
                continue;
            }

            if (! $this->tenants->ownsSubject($run->tenant_id, $subjectType, $subjectId)) {
                throw new CrossTenantSubjectException($run->tenant_id, $subjectType, $subjectId);
            }

            $seen[$subjectId] = true;
        }

        $rows = array_map(fn (string $id) => [
            'run_id' => $run->id,
            'subject_type' => $subjectType,
            'subject_id' => $id,
            'current_node_id' => $startNodeId,
            'status' => 'active',
        ], array_keys($seen));

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('nodeflow_run_subjects')->insert($chunk);
            }
        });

        return count($rows);
    }
}
