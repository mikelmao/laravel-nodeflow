<?php

use Nodeflow\Contracts\BatchTenantResolver;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceMaterialiser;
use Nodeflow\Execution\CreateRun;
use Nodeflow\Execution\ReplayableSubjectIds;
use Nodeflow\Models\Flow;
use Nodeflow\Publishing\PublishFlow;

it('admits a lazy audience in fixed ownership batches', function () {
    $total = scaleSubjectCount();
    config()->set('nodeflow.limits.materialise_chunk', 1000);

    $resolver = new RecordingBatchTenantResolver;
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    $flow = Flow::create(['tenant_id' => 'org-1', 'name' => 'Scale audience', 'status' => 'draft']);
    $version = app(PublishFlow::class)->publish($flow, triggeredExitGraph())->version;

    $run = app(CreateRun::class)->forVersion(
        $version,
        'yaya-user',
        ReplayableSubjectIds::from(function () use ($total): iterable {
            for ($id = 1; $id <= $total; $id++) {
                yield (string) $id;
            }
        }),
        'first-action',
        [
            'started_via' => 'scale-test',
            'trigger_node_id' => 'trigger',
        ],
    );

    expect($run->subjects()->count())->toBe($total)
        ->and($resolver->largestBatch)->toBeLessThanOrEqual(1000)
        ->and($resolver->scalarCalls)->toBe(0);
})->group('scale');

function scaleSubjectCount(): int
{
    $value = getenv('NODEFLOW_SCALE_SUBJECTS');

    if ($value === false || $value === '') {
        test()->markTestSkipped('Set a positive NODEFLOW_SCALE_SUBJECTS value to run the opt-in scale proof.');
    }

    if (! is_string($value)
        || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
        || strlen($value) > strlen((string) PHP_INT_MAX)
        || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
        throw new \InvalidArgumentException('NODEFLOW_SCALE_SUBJECTS must be a positive base-10 integer within PHP integer range.');
    }

    return (int) $value;
}

final class RecordingBatchTenantResolver implements BatchTenantResolver
{
    public int $largestBatch = 0;

    public int $scalarCalls = 0;

    public function currentTenantId(): ?string
    {
        return 'org-1';
    }

    public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
    {
        $this->scalarCalls++;

        return true;
    }

    public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
    {
        $this->largestBatch = max($this->largestBatch, count($subjectIds));

        return $subjectIds;
    }
}
