<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\CrossTenantExecutionException;
use Nodeflow\Execution\NodeRunner;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Workflows\Activities\RunNodeActivity;
use Tests\Support\RecordingNodeRunner;

beforeEach(function () {
    $this->makeVersion = function (string $tenantId): FlowVersion {
        return TenancyGuardSuspension::run(function () use ($tenantId) {
            $flow = Flow::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'name' => "{$tenantId} flow",
                'status' => 'active',
            ]);

            return FlowVersion::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'version' => 1,
                'graph' => triggeredGraph([
                    'start' => 'n1',
                    'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
                    'edges' => [],
                ]),
                'content_hash' => "hash-{$tenantId}",
            ]);
        });
    };

    $this->insertRun = function (int $versionId, string $tenantId, int $stepsTaken): int {
        return DB::table('nodeflow_runs')->insertGetId([
            'flow_version_id' => $versionId,
            'tenant_id' => $tenantId,
            'started_via' => 'manual',
            'trigger_node_id' => 'trigger',
            'trigger_data' => null,
            'strategy' => 'cohort',
            'status' => 'running',
            'is_test' => false,
            'steps_taken' => $stepsTaken,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    };

    $this->bindNullResolver = function (): void {
        app()->bind(TenantResolver::class, fn () => new class implements TenantResolver
        {
            public function currentTenantId(): ?string
            {
                return null;
            }

            public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
            {
                return true;
            }
        });
    };
});

it('refuses a persisted tenant mismatch before any mutation or node call', function () {
    $version = ($this->makeVersion)('org-2');
    $runId = ($this->insertRun)($version->id, 'org-1', 7);
    $runner = new RecordingNodeRunner(['next']);
    app()->instance(NodeRunner::class, $runner);

    expect(fn () => app(RunNodeActivity::class)->handle($runId, 'n1'))
        ->toThrow(CrossTenantExecutionException::class, "Run [{$runId}]");

    expect(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(7)
        ->and($runner->calls)->toBe(0);
});

it('fails a missing pinned version before incrementing or calling the runner', function () {
    $runId = ($this->insertRun)(999999, 'org-1', 4);
    $runner = new RecordingNodeRunner;
    app()->instance(NodeRunner::class, $runner);

    expect(fn () => app(RunNodeActivity::class)->handle($runId, 'n1'))
        ->toThrow(ModelNotFoundException::class, 'FlowVersion');

    expect(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(4)
        ->and($runner->calls)->toBe(0);
});

it('executes a matching persisted pair once without an ambient tenant', function () {
    config()->set('nodeflow.tenancy', 'resolver');
    ($this->bindNullResolver)();
    $version = ($this->makeVersion)('org-1');
    $runId = ($this->insertRun)($version->id, 'org-1', 0);
    $runner = new RecordingNodeRunner(['next']);
    app()->instance(NodeRunner::class, $runner);

    $result = app(RunNodeActivity::class)->handle($runId, 'n1');

    expect($result)->toBe(['next'])
        ->and(Run::withoutTenancy()->findOrFail($runId)->steps_taken)->toBe(1)
        ->and($runner->calls)->toBe(1);
});
