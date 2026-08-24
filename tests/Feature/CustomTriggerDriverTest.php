<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerActivationRepository;
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

    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return null;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
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
    app()->instance(TriggerActivationRepository::class, new class extends TriggerActivationRepository
    {
        public function forDriverSource(string $driver, string $source, ?string $qualifier = null): array
        {
            throw new RuntimeException('Repository must not be queried for supplied activations.');
        }
    });
    $reported = captureReportedExceptions();
    $mismatched = snapshotWith($snapshot, source: 'test.returns');
    $wrongQualifier = snapshotWith($snapshot, qualifier: 'different');

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake',
        'test.orders',
        ['tenant_id' => 'org-1', 'subject_id' => '42', 'occurrence_id' => 'supplied-1'],
        activations: [$mismatched, $wrongQualifier, $snapshot],
    ));

    expect($runs)->toHaveCount(1)
        ->and(Run::withoutTenancy()->count())->toBe(1)
        ->and($reported->reported)->toHaveCount(2)
        ->and($reported->reported[0])->toBeInstanceOf(InvalidArgumentException::class)
        ->and($reported->reported[0]->getMessage())->toContain('source')
        ->and($reported->reported[1]->getMessage())->toContain('qualifier');
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

it('reports an unknown source once at the occurrence boundary', function () {
    $reported = captureReportedExceptions();

    $runs = app(TriggerOccurrenceDispatcher::class)->dispatch(new TriggerOccurrence(
        'test.fake', 'test.unknown', [], activations: [],
    ));

    expect($runs)->toBe([])
        ->and($reported->reported)->toHaveCount(1)
        ->and($reported->reported[0])->toBeInstanceOf(RuntimeException::class)
        ->and($reported->reported[0]->getMessage())->toContain('Unknown nodeflow trigger source');
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
