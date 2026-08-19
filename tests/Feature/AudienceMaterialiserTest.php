<?php

use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Execution\AudienceMaterialiser;
use Nodeflow\Execution\CrossTenantSubjectException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;

beforeEach(function () {
    $this->owned = ['1', '2', '3'];

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return 'org-1';
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return in_array($subjectId, $this->test->owned, true);
        }
    });

    $flow = Flow::create(['name' => 'F', 'trigger_type' => 'manual', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'strategy' => 'cohort', 'status' => 'pending',
    ]);
});

it('materialises owned subjects into run_subjects', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2']);

    expect($count)->toBe(2)
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1', '2'])
        ->and($this->run->subjects()->first()->status)->toBe('active');
});

it('refuses a subject the tenant does not own', function () {
    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '999']))
        ->toThrow(CrossTenantSubjectException::class, '999');
});

it('writes nothing at all when any subject fails the check', function () {
    try {
        app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '999']);
    } catch (CrossTenantSubjectException) {
        // expected
    }

    expect($this->run->subjects()->count())->toBe(0);
});

it('deduplicates repeated subject ids', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '1', '2']);

    expect($count)->toBe(2);
});
