<?php

namespace Nodeflow\Execution;

use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\BatchTenantResolver;
use Nodeflow\Contracts\TenantResolver;
use UnexpectedValueException;
use Nodeflow\Models\Run;

class AudienceMaterialiser
{
    public function __construct(private TenantResolver $tenants) {}

    public function materialise(Run $run, string $subjectType, iterable $subjectIds, ?string $startNodeId = null): int
    {
        $chunkSize = max(1, (int) config('nodeflow.limits.materialise_chunk', 1000));

        return DB::transaction(function () use ($run, $subjectType, $subjectIds, $startNodeId, $chunkSize): int {
            $inserted = 0;
            $batch = [];

            foreach ($subjectIds as $subjectId) {
                $subjectId = (string) $subjectId;

                if (isset($batch[$subjectId])) {
                    continue;
                }

                $batch[$subjectId] = $subjectId;

                if (count($batch) === $chunkSize) {
                    $inserted += $this->flush($run, $subjectType, $batch, $startNodeId);
                    $batch = [];
                }
            }

            if ($batch !== []) {
                $inserted += $this->flush($run, $subjectType, $batch, $startNodeId);
            }

            return $inserted;
        });
    }

    /** @param array<string, string> $subjectIds */
    private function flush(Run $run, string $subjectType, array $subjectIds, ?string $startNodeId): int
    {
        if ($this->tenants instanceof BatchTenantResolver) {
            $owned = [];

            foreach ($this->tenants->ownedSubjectIds($run->tenant_id, $subjectType, array_values($subjectIds)) as $subjectId) {
                $subjectId = (string) $subjectId;

                if (! isset($subjectIds[$subjectId])) {
                    throw new UnexpectedValueException(
                        "Batch tenant resolver returned unrequested {$subjectType} [{$subjectId}]."
                    );
                }

                $owned[$subjectId] = true;
            }

            foreach ($subjectIds as $subjectId) {
                if (! isset($owned[$subjectId])) {
                    throw new CrossTenantSubjectException($run->tenant_id, $subjectType, $subjectId);
                }
            }
        } else {
            foreach ($subjectIds as $subjectId) {
                if (! $this->tenants->ownsSubject($run->tenant_id, $subjectType, $subjectId)) {
                    throw new CrossTenantSubjectException($run->tenant_id, $subjectType, $subjectId);
                }
            }
        }

        $rows = [];

        foreach ($subjectIds as $subjectId) {
            $rows[] = [
                'run_id' => $run->id,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'current_node_id' => $startNodeId,
                'status' => 'active',
            ];
        }

        return DB::table('nodeflow_run_subjects')->insertOrIgnore($rows);
    }
}
