<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerActivationRepository;
use Nodeflow\Triggers\TriggerActivationRepository as BaseTriggerActivationRepository;
use Nodeflow\Triggers\TriggerActivationSnapshot;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\TriggerOccurrenceDispatcher;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Tests\Support\FakeTriggerSource;

class AlternateFakeTriggerSource implements TriggerSource
{
    public static function key(): string
    {
        return 'test.returns';
    }

    public static function driver(): string
    {
        return 'test.fake';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Alternate fake source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        $payload = $occurrence->payload;
        $occurrenceId = (string) $payload['occurrence_id'];

        return TriggerMatch::make()->forTenant(
            (string) $payload['tenant_id'],
            'user',
            [(string) $payload['subject_id']],
            ['occurrence' => $occurrenceId],
            $occurrenceId,
        );
    }
}

class RecordingTriggerExceptionHandler implements ExceptionHandler
{
    /** @var Throwable[] */
    public array $reported = [];

    public function __construct(private readonly bool $throwWhileReporting = false) {}

    public function report(Throwable $e)
    {
        $this->reported[] = $e;

        if ($this->throwWhileReporting) {
            throw new RuntimeException('host reporter failed');
        }
    }

    public function shouldReport(Throwable $e)
    {
        return true;
    }

    public function render($request, Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, Throwable $e): void {}
}

beforeEach(function () {
    FakeTriggerSource::$resolver = null;
    $this->unownedSubjects = [];
    $this->ownershipChecks = 0;

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return null;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            $this->test->ownershipChecks++;

            return ! in_array($subjectId, $this->test->unownedSubjects, true);
        }
    });
});

afterEach(function () {
    FakeTriggerSource::$resolver = null;
});

it('starts a custom-driver flow at the exact activation version without package type switches', function () {
    $flow = publishCustomTriggerFlow('org-1', 'Custom trigger', 'test.orders', ['account' => 'retail']);
    $activation = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];

    app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph('test.orders', ['account' => 'later']));

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        driver: 'test.fake',
        source: 'test.orders',
        payload: ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'occ-9'],
        activations: [$activation],
    ));

    $run = Run::withoutTenancy()->sole();

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->is($run))->toBeTrue()
        ->and($run->flow_version_id)->toBe($activation->flowVersionId)
        ->and($run->started_via)->toBe('test.fake')
        ->and($run->trigger_data)->toBe(['occurrence' => 'occ-9']);
});

it('isolates a candidate audience failure and continues multi-tenant fan-out', function () {
    publishCustomTriggerFlow('org-1', 'Broken audience');
    publishCustomTriggerFlow('org-2', 'Healthy audience');
    $activations = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders');
    $this->unownedSubjects = ['666'];
    $reported = captureReportedExceptions(throwWhileReporting: true);

    FakeTriggerSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('org-1', 'user', ['666'], ['tenant' => 'org-1'], 'multi-1')
        ->forTenant('org-2', 'user', ['3'], ['tenant' => 'org-2'], 'multi-1');

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake',
        'test.orders',
        ['unused' => true],
        activations: $activations,
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->tenant_id)->toBe('org-2')
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBeInstanceOf(\Nodeflow\Execution\CrossTenantSubjectException::class);
});

it('namespaces the same raw occurrence identity by registered source identity', function () {
    app(TriggerSourceRegistry::class)->register(AlternateFakeTriggerSource::class);
    publishCustomTriggerFlow('org-1', 'Orders', 'test.orders');
    publishCustomTriggerFlow('org-1', 'Returns', 'test.returns');
    $dispatcher = app(TriggerOccurrenceDispatcher::class);
    $payload = ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'same-id'];

    $orders = $dispatcher->dispatch(new TriggerOccurrence('test.fake', 'test.orders', $payload))[0];
    $returns = $dispatcher->dispatch(new TriggerOccurrence('test.fake', 'test.returns', $payload))[0];

    expect($orders->id)->not->toBe($returns->id)
        ->and($orders->idempotency_key)->not->toBe($returns->idempotency_key)
        ->and($orders->idempotency_key)->toHaveLength(64)
        ->and($returns->idempotency_key)->toHaveLength(64);
});

it('uses supplied snapshots without repository lookup and enforces their routing identity', function () {
    publishCustomTriggerFlow('org-1', 'Supplied activation');
    $snapshot = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    app()->instance(TriggerActivationRepository::class, new class extends BaseTriggerActivationRepository
    {
        public function forDriverSource(string $driver, string $source, ?string $qualifier = null): array
        {
            throw new RuntimeException('Repository must not be queried for supplied activations.');
        }
    });
    $reported = captureReportedExceptions();
    $mismatched = snapshotWith($snapshot, source: 'test.returns');
    $wrongQualifier = snapshotWith($snapshot, qualifier: 'different');

    $dispatcher = app(TriggerOccurrenceDispatcher::class);
    $occurrence = fn (TriggerActivationSnapshot $activation) => new TriggerOccurrence(
        'test.fake',
        'test.orders',
        ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'supplied-1'],
        activations: [$activation],
    );

    $sourceMismatch = $dispatcher->dispatch($occurrence($mismatched));
    $qualifierMismatch = $dispatcher->dispatch($occurrence($wrongQualifier));
    $runs = $dispatcher->dispatch($occurrence($snapshot));

    expect($sourceMismatch)->toBe([])
        ->and($qualifierMismatch)->toBe([])
        ->and($runs)->toHaveCount(1)
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and($reported->reported)->toHaveCount(2)
        ->and($reported->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($reported->reported[0]->getMessage())->toContain('source')
        ->and($reported->reported[1]->getMessage())->toContain('qualifier');
});

it('rejects a supplied snapshot whose routing metadata contradicts its pinned graph', function () {
    publishCustomTriggerFlow('org-1', 'Pinned authority', 'test.orders', ['account' => 'retail']);
    $snapshot = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    $forged = new TriggerActivationSnapshot(
        activationId: 999_999,
        flowId: $snapshot->flowId,
        flowVersionId: $snapshot->flowVersionId,
        tenantId: $snapshot->tenantId,
        driver: 'test.fake',
        source: 'test.orders',
        qualifier: null,
        triggerNodeId: $snapshot->triggerNodeId,
        descriptor: ['account' => 'forged'],
    );
    $reported = captureReportedExceptions();
    $resolutions = 0;
    FakeTriggerSource::$resolver = function () use (&$resolutions): TriggerMatch {
        $resolutions++;

        return TriggerMatch::make()->forTenant('org-1', 'user', ['42']);
    };

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake',
        'test.orders',
        ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'forged-1'],
        activations: [$forged],
    ));

    expect($runs)->toBe([])
        ->and(Run::withoutTenancy()->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->started())->toBe([])
        ->and($resolutions)->toBe(0)
        ->and($this->ownershipChecks)->toBe(0)
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($reported->reported[0]->getMessage())->toContain('pinned graph');
});

it('isolates an invalid pinned snapshot before extension code and continues valid candidates', function () {
    publishCustomTriggerFlow('org-1', 'Invalid pinned snapshot', 'test.orders', ['mode' => 'valid']);
    publishCustomTriggerFlow('org-2', 'Valid pinned snapshot', 'test.orders', ['mode' => 'valid']);
    $snapshots = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders');
    $forged = new TriggerActivationSnapshot(
        activationId: $snapshots[0]->activationId,
        flowId: $snapshots[0]->flowId,
        flowVersionId: $snapshots[0]->flowVersionId,
        tenantId: $snapshots[0]->tenantId,
        driver: $snapshots[0]->driver,
        source: $snapshots[0]->source,
        qualifier: $snapshots[0]->qualifier,
        triggerNodeId: $snapshots[0]->triggerNodeId,
        descriptor: ['mode' => 'forged'],
    );
    $resolutions = 0;
    FakeTriggerSource::$resolver = function () use (&$resolutions): TriggerMatch {
        $resolutions++;

        return TriggerMatch::make()
            ->forTenant('org-1', 'user', ['1'])
            ->forTenant('org-2', 'user', ['2']);
    };
    $reported = captureReportedExceptions();

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: [$forged, $snapshots[1]],
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->tenant_id)->toBe('org-2')
        ->and($resolutions)->toBe(1)
        // The valid run is checked by both TriggerRunStarter and the shared
        // audience materializer; the forged candidate contributes zero checks.
        ->and($this->ownershipChecks)->toBe(2)
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0]->getMessage())->toContain('pinned graph');
});

it('repository dispatch ignores inactive activations', function () {
    publishCustomTriggerFlow('org-1', 'Active');
    $inactive = publishCustomTriggerFlow('org-2', 'Inactive');
    $inactive->update(['status' => 'paused']);

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake',
        'test.orders',
        ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'active-only'],
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->tenant_id)->toBe('org-1')
        ->and(Run::withoutTenancy()->count())->toBe(1);
});

it('treats zero matches as a no-op and reports a tenant-only mismatch', function () {
    publishCustomTriggerFlow('org-1', 'Tenant match');
    $activation = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    $reported = captureReportedExceptions();
    FakeTriggerSource::$resolver = fn () => TriggerMatch::make();

    $empty = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: [$activation],
    ));

    FakeTriggerSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('org-2', 'user', ['3']);
    $wrongTenant = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: [$activation],
    ));

    expect($empty)->toBe([])
        ->and($wrongTenant)->toBe([])
        ->and(Run::withoutTenancy()->count())->toBe(0)
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($reported->reported[0]->getMessage())->toContain('org-1');
});

it('uses the last immutable match for a duplicate tenant and ignores other tenant matches', function () {
    publishCustomTriggerFlow('org-1', 'Duplicate match');
    $activation = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    FakeTriggerSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('org-1', 'user', ['1'], ['position' => 'first'])
        ->forTenant('org-2', 'user', ['2'], ['position' => 'other'])
        ->forTenant('org-1', 'user', ['3'], ['position' => 'last']);

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: [$activation],
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->trigger_data)->toBe(['position' => 'last'])
        ->and($runs[0]->subjects()->pluck('subject_id')->all())->toBe(['3']);
});

it('deduplicates exact supplied candidates before resolving or starting them', function () {
    publishCustomTriggerFlow('org-1', 'Duplicate candidate', 'test.orders', [
        'filters' => ['country' => 'RO', 'tier' => 'gold'],
        'steps' => [['field' => 'email', 'operator' => 'present']],
    ]);
    $snapshot = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    $resolutions = 0;
    FakeTriggerSource::$resolver = function () use (&$resolutions): TriggerMatch {
        $resolutions++;

        return TriggerMatch::make()->forTenant('org-1', 'user', ['1']);
    };

    $sameLogicalSnapshot = new TriggerActivationSnapshot(
        activationId: $snapshot->activationId + 100,
        flowId: $snapshot->flowId,
        flowVersionId: $snapshot->flowVersionId,
        tenantId: $snapshot->tenantId,
        driver: $snapshot->driver,
        source: $snapshot->source,
        qualifier: $snapshot->qualifier,
        triggerNodeId: $snapshot->triggerNodeId,
        descriptor: [
            'steps' => [['operator' => 'present', 'field' => 'email']],
            'filters' => ['tier' => 'gold', 'country' => 'RO'],
        ],
    );

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: [$snapshot, $sameLogicalSnapshot, $sameLogicalSnapshot],
    ));

    expect($runs)->toHaveCount(1)
        ->and($resolutions)->toBe(1)
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1);
});

it('rejects conflicting supplied candidates as one occurrence-level failure before fan-out', function () {
    publishCustomTriggerFlow('org-1', 'Conflicting candidate', 'test.orders', [
        'sequence' => ['first', 'second'],
    ]);
    $snapshot = app(TriggerActivationRepository::class)
        ->forDriverSource('test.fake', 'test.orders')[0];
    $sameIdConflict = snapshotWith($snapshot, source: 'test.conflict');
    $tupleConflict = new TriggerActivationSnapshot(
        activationId: $snapshot->activationId + 100,
        flowId: $snapshot->flowId,
        flowVersionId: $snapshot->flowVersionId,
        tenantId: 'org-2',
        driver: $snapshot->driver,
        source: $snapshot->source,
        qualifier: $snapshot->qualifier,
        triggerNodeId: $snapshot->triggerNodeId,
        descriptor: $snapshot->descriptor,
    );
    $listOrderConflict = new TriggerActivationSnapshot(
        activationId: $snapshot->activationId + 101,
        flowId: $snapshot->flowId,
        flowVersionId: $snapshot->flowVersionId,
        tenantId: $snapshot->tenantId,
        driver: $snapshot->driver,
        source: $snapshot->source,
        qualifier: $snapshot->qualifier,
        triggerNodeId: $snapshot->triggerNodeId,
        descriptor: ['sequence' => ['second', 'first']],
    );
    $resolutions = 0;
    FakeTriggerSource::$resolver = function () use (&$resolutions): TriggerMatch {
        $resolutions++;

        return TriggerMatch::make()->forTenant('org-1', 'user', ['1']);
    };
    $reported = captureReportedExceptions();

    foreach ([
        [$snapshot, $sameIdConflict],
        [$snapshot, $tupleConflict],
        [$snapshot, $listOrderConflict],
    ] as $candidates) {
        expect(fn () => app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
            'test.fake', 'test.orders', [], activations: $candidates,
        )))->toThrow(InvalidArgumentException::class, 'Conflicting trigger activation snapshots');
    }

    expect($resolutions)->toBe(0)
        ->and(Run::withoutTenancy()->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->started())->toBe([])
        ->and($reported->reported)->toHaveCount(3);
});

it('rejects malformed supplied candidates before resolving any source match', function () {
    $reported = captureReportedExceptions();
    $resolutions = 0;
    FakeTriggerSource::$resolver = function () use (&$resolutions): TriggerMatch {
        $resolutions++;

        return TriggerMatch::make();
    };

    expect(fn () => app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [], activations: ['not-a-snapshot'],
    )))->toThrow(InvalidArgumentException::class, 'snapshot');

    expect($resolutions)->toBe(0)
        ->and($reported->reported)->toHaveCount(1);
});

it('isolates source resolution failures per activation descriptor', function () {
    publishCustomTriggerFlow('org-1', 'Broken descriptor', 'test.orders', ['mode' => 'fail']);
    publishCustomTriggerFlow('org-2', 'Healthy descriptor', 'test.orders', ['mode' => 'ok']);
    $reported = captureReportedExceptions();
    FakeTriggerSource::$resolver = function (TriggerOccurrence $occurrence, array $config): TriggerMatch {
        if (($config['mode'] ?? null) === 'fail') {
            throw new RuntimeException('source descriptor failed');
        }

        return TriggerMatch::make()->forTenant('org-2', 'user', ['3'], ['resolved' => true]);
    };

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [],
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->tenant_id)->toBe('org-2')
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0]->getMessage())->toBe('source descriptor failed');
});

it('reports and rethrows an unknown source at the occurrence boundary', function () {
    $reported = captureReportedExceptions(throwWhileReporting: true);
    $caught = null;

    try {
        app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
            'test.fake', 'test.unknown', [], activations: [],
        ));
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('Unknown nodeflow trigger source')
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBe($caught);
});

it('reports and rethrows the original repository failure even when reporting fails', function () {
    $failure = new RuntimeException('activation repository unavailable');
    app()->instance(TriggerActivationRepository::class, new class($failure) extends BaseTriggerActivationRepository
    {
        public function __construct(private readonly Throwable $failure) {}

        public function forDriverSource(string $driver, string $source, ?string $qualifier = null): array
        {
            throw $this->failure;
        }
    });
    $reported = captureReportedExceptions(throwWhileReporting: true);
    $caught = null;

    try {
        app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
            'test.fake', 'test.orders', [],
        ));
    } catch (Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->toBe($failure)
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBe($failure);
});

it('isolates malformed extension matches before creating a run', function (Closure $resolver, string $message) {
    publishCustomTriggerFlow('org-1', 'Malformed match');
    $reported = captureReportedExceptions();
    FakeTriggerSource::$resolver = $resolver;

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [],
    ));

    expect($runs)->toBe([])
        ->and(Run::withoutTenancy()->count())->toBe(0)
        ->and(app(WorkflowEngine::class)->started())->toBe([])
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($reported->reported[0]->getMessage())->toContain($message);
})->with([
    'blank tenant' => [fn () => TriggerMatch::make()->forTenant('  ', 'user', ['1']), 'tenant'],
    'blank subject type' => [fn () => TriggerMatch::make()->forTenant('org-1', ' ', ['1']), 'subject type'],
    'blank subject id' => [fn () => TriggerMatch::make()->forTenant('org-1', 'user', ['1', '  ']), 'subject ID'],
    'blank occurrence id' => [fn () => TriggerMatch::make()->forTenant('org-1', 'user', ['1'], [], ' '), 'occurrence ID'],
]);

it('allows an explicitly empty extension audience to record an occurrence', function () {
    publishCustomTriggerFlow('org-1', 'Empty audience');
    FakeTriggerSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('org-1', 'user', [], ['empty' => true], 'empty-occurrence');

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.orders', [],
    ));

    expect($runs)->toHaveCount(1)
        ->and($runs[0]->subjects()->count())->toBe(0)
        ->and($runs[0]->trigger_data)->toBe(['empty' => true])
        ->and(app(WorkflowEngine::class)->started())->toHaveCount(1);
});

function publishCustomTriggerFlow(
    string $tenantId,
    string $name,
    string $source = 'test.orders',
    array $config = [],
): Flow {
    $flow = Flow::create([
        'tenant_id' => $tenantId,
        'name' => $name,
        'status' => 'draft',
    ]);
    app(PublishFlow::class)->publish($flow, triggeredExitGraph($source, $config));

    return $flow;
}

function snapshotWith(
    TriggerActivationSnapshot $snapshot,
    ?string $driver = null,
    ?string $source = null,
    ?string $qualifier = null,
): TriggerActivationSnapshot {
    return new TriggerActivationSnapshot(
        activationId: $snapshot->activationId,
        flowId: $snapshot->flowId,
        flowVersionId: $snapshot->flowVersionId,
        tenantId: $snapshot->tenantId,
        driver: $driver ?? $snapshot->driver,
        source: $source ?? $snapshot->source,
        qualifier: $qualifier ?? $snapshot->qualifier,
        triggerNodeId: $snapshot->triggerNodeId,
        descriptor: $snapshot->descriptor,
    );
}

function captureReportedExceptions(bool $throwWhileReporting = false): RecordingTriggerExceptionHandler
{
    $handler = new RecordingTriggerExceptionHandler($throwWhileReporting);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}
