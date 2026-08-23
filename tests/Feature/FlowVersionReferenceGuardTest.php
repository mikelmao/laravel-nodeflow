<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\InvalidFlowVersionReferenceException;

beforeEach(function () {
    $this->tenant = 'org-1';

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });

    $this->makeVersion = function (string $tenantId): FlowVersion {
        return TenancyGuardSuspension::run(function () use ($tenantId) {
            $flow = Flow::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'name' => "{$tenantId} flow",
                'trigger_type' => 'manual',
                'status' => 'active',
            ]);

            return FlowVersion::withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'flow_id' => $flow->id,
                'version' => 1,
                'graph' => [
                    'start' => 'n1',
                    'nodes' => [['id' => 'n1', 'type' => 'core.exit', 'config' => []]],
                    'edges' => [],
                ],
                'content_hash' => "hash-{$tenantId}",
            ]);
        });
    };
});

it('allows a null or same-tenant current version', function () {
    $draft = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);
    $version = ($this->makeVersion)('org-1');

    $flow = Flow::create([
        'name' => 'Published',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => $version->id,
    ]);

    expect($draft->current_version_id)->toBeNull()
        ->and($flow->current_version_id)->toBe($version->id);
});

it('refuses a missing current version on create and update', function () {
    expect(fn () => Flow::create([
        'name' => 'Missing',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => 999999,
    ]))->toThrow(InvalidFlowVersionReferenceException::class, 'current_version_id');

    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => $flow->update(['current_version_id' => 999999]))
        ->toThrow(InvalidFlowVersionReferenceException::class, '999999');
});

it('refuses a cross-tenant current version on create and update', function () {
    $foreign = ($this->makeVersion)('org-2');

    expect(fn () => Flow::create([
        'name' => 'Unsafe',
        'trigger_type' => 'manual',
        'status' => 'active',
        'current_version_id' => $foreign->id,
    ]))->toThrow(CrossTenantWriteException::class, 'current_version_id');

    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => $flow->update(['current_version_id' => $foreign->id]))
        ->toThrow(CrossTenantWriteException::class, "'org-2'");
});

it('does not let guard suspension create a contradictory flow reference', function () {
    $version = ($this->makeVersion)('org-1');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    expect(fn () => TenancyGuardSuspension::run(
        fn () => $flow->update(['tenant_id' => 'org-2', 'current_version_id' => $version->id])
    ))->toThrow(CrossTenantWriteException::class);
});

it('queries the version only when a flow write can change the invariant', function () {
    $version = ($this->makeVersion)('org-1');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);
    $versionQueries = [];

    DB::listen(function (QueryExecuted $query) use (&$versionQueries) {
        if (str_contains($query->sql, 'nodeflow_flow_versions')) {
            $versionQueries[] = $query->sql;
        }
    });

    $flow->update(['name' => 'Renamed']);
    expect($versionQueries)->toBe([]);

    $flow->update(['current_version_id' => $version->id]);
    expect($versionQueries)->toHaveCount(1);
});

it('documents that a query-builder flow update bypasses model events', function () {
    $foreign = ($this->makeVersion)('org-2');
    $flow = Flow::create(['name' => 'Draft', 'trigger_type' => 'manual', 'status' => 'draft']);

    Flow::withoutTenancy()->whereKey($flow->id)->update(['current_version_id' => $foreign->id]);

    expect(Flow::withoutTenancy()->findOrFail($flow->id)->current_version_id)->toBe($foreign->id);
});
