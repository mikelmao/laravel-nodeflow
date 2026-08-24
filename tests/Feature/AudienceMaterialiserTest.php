<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\BatchTenantResolver;
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

    $flow = Flow::create(['name' => 'F', 'status' => 'active']);
    $version = FlowVersion::create([
        'flow_id' => $flow->id, 'version' => 1,
        'graph' => ['nodes' => [], 'edges' => []], 'content_hash' => 'h',
    ]);
    $this->run = Run::create([
        'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'strategy' => 'cohort', 'status' => 'pending',
    ]);
});

it('materialises owned subjects into run_subjects', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2']);

    expect($count)->toBe(2)
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1', '2'])
        ->and($this->run->subjects()->first()->status)->toBe('active')
        ->and($this->run->subjects()->pluck('current_node_id')->unique()->all())->toBe([null]);
});

it('places subjects at the given start node', function () {
    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2'], 'node-start');

    expect($count)->toBe(2)
        ->and($this->run->subjects()->pluck('current_node_id')->unique()->all())->toBe(['node-start']);
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

it('materialises audiences in bounded tenant-checked batches', function () {
    config()->set('nodeflow.limits.materialise_chunk', 2);
    $resolver = new class implements BatchTenantResolver {
        public array $calls = [];

        public function currentTenantId(): ?string { return 'org-1'; }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            throw new RuntimeException('Scalar ownership must not be called for a batch resolver.');
        }

        public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
        {
            $this->calls[] = $subjectIds;

            return $subjectIds;
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2', '3', '1', '4']);

    expect($count)->toBe(4)
        ->and($resolver->calls)->toBe([['1', '2'], ['3', '1'], ['4']])
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1', '2', '3', '4']);
});

it('rolls back all batches when the batch resolver omits an id', function () {
    config()->set('nodeflow.limits.materialise_chunk', 2);
    $resolver = new class implements BatchTenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
        public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
        {
            return array_values(array_filter($subjectIds, fn (string $id): bool => $id !== '3'));
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2', '3']))
        ->toThrow(CrossTenantSubjectException::class, '3');

    expect($this->run->subjects()->count())->toBe(0);
});

it('rejects batch resolver ids that were not requested', function () {
    $resolver = new class implements BatchTenantResolver {
        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
        public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
        {
            return [...$subjectIds, 'unexpected'];
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1']))
        ->toThrow(UnexpectedValueException::class, 'unexpected');

    expect($this->run->subjects()->count())->toBe(0);
});

it('deduplicates repeated ids across batches with the inserted count', function () {
    config()->set('nodeflow.limits.materialise_chunk', 1);

    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '1', '1']);

    expect($count)->toBe(1)
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1']);
});

it('rejects identifiers that cannot be persisted losslessly before batch ownership', function (string $subjectId, string $message) {
    $resolver = new class implements BatchTenantResolver {
        public int $calls = 0;

        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
        public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
        {
            $this->calls++;

            return $subjectIds;
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', [$subjectId]))
        ->toThrow(InvalidArgumentException::class, $message);

    expect($resolver->calls)->toBe(0)
        ->and($this->run->subjects()->count())->toBe(0);
})->with([
    'overlong' => [str_repeat('x', 256), '255 Unicode characters'],
    'invalid utf-8' => ["\xB1\x31", 'valid UTF-8'],
    'nul byte' => ["valid\0id", 'NUL byte'],
]);

it('rejects an unrepresentable subject type before consuming its audience', function () {
    $resolver = new class implements BatchTenantResolver {
        public int $calls = 0;

        public function currentTenantId(): ?string { return 'org-1'; }
        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool { return true; }
        public function ownedSubjectIds(string $tenantId, string $subjectType, array $subjectIds): array
        {
            $this->calls++;

            return $subjectIds;
        }
    };
    app()->instance(TenantResolver::class, $resolver);
    app()->forgetInstance(AudienceMaterialiser::class);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, str_repeat('t', 256), ['1']))
        ->toThrow(InvalidArgumentException::class, 'subject type');

    expect($resolver->calls)->toBe(0)
        ->and($this->run->subjects()->count())->toBe(0);
});

it('rolls back earlier batches when a later identifier is not lossless', function () {
    config()->set('nodeflow.limits.materialise_chunk', 1);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', "later\0id"]))
        ->toThrow(InvalidArgumentException::class, 'NUL byte');

    expect($this->run->subjects()->count())->toBe(0);
});

it('counts only newly inserted rows when a batch collides with existing subjects', function () {
    DB::table('nodeflow_run_subjects')->insert([
        'run_id' => $this->run->id,
        'subject_type' => 'user',
        'subject_id' => '1',
        'current_node_id' => null,
        'status' => 'active',
    ]);

    $count = app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['1', '2']);

    expect($count)->toBe(1)
        ->and($this->run->subjects()->pluck('subject_id')->all())->toBe(['1', '2']);
});

it('does not swallow non-unique subject insertion failures', function () {
    $this->owned[] = 'reject';
    DB::unprepared(<<<'SQL'
        CREATE TRIGGER reject_run_subject_insert
        BEFORE INSERT ON nodeflow_run_subjects
        WHEN NEW.subject_id = 'reject'
        BEGIN
            SELECT RAISE(FAIL, 'subject insert rejected');
        END
        SQL);

    expect(fn () => app(AudienceMaterialiser::class)->materialise($this->run, 'user', ['reject']))
        ->toThrow(QueryException::class, 'subject insert rejected');

    expect($this->run->subjects()->count())->toBe(0);
});
