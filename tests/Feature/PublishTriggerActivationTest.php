<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Contracts\TriggerNode;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Models\WebhookEndpoint;
use Nodeflow\Publishing\CompileTriggerActivation;
use Nodeflow\Publishing\GraphInvalidException;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Publishing\PublishResult;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerActivationDescriptor;
use Nodeflow\Triggers\TriggerActivationRepository;
use Nodeflow\Triggers\TriggerSourceRegistry;

class UnsafeMetadataPublicationTriggerNode implements TriggerNode
{
    public static function type(): string
    {
        return 'test.unsafe-metadata-trigger';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Unsafe metadata');
    }

    public function driver(): string
    {
        return 'test.fake';
    }

    public function defaultConfig(): array
    {
        return [];
    }

    public function validate(array $config, TriggerSourceRegistry $sources): array
    {
        return [];
    }

    public function compile(array $config): TriggerActivationDescriptor
    {
        return new TriggerActivationDescriptor(
            driver: 'test.fake',
            source: 'test.orders',
            qualifier: null,
            metadata: ['unsafe' => fopen('php://memory', 'r')],
        );
    }
}

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

    $this->flow = Flow::create(['name' => 'Published flow', 'status' => 'draft']);
});

it('publishes a typed result and compiles one activation from trusted records', function () {
    $graph = triggeredExitGraph('test.orders', ['account' => 'retail']);

    $result = app(PublishFlow::class)->publish($this->flow, $graph, 'publisher-1');
    $activation = TriggerActivation::withoutTenancy()->sole();

    expect($result)->toBeInstanceOf(PublishResult::class)
        ->and($result->version)->toBeInstanceOf(FlowVersion::class)
        ->and($result->webhookUrl)->toBeNull()
        ->and($result->webhookSecret)->toBeNull()
        ->and($activation->flow_id)->toBe($this->flow->id)
        ->and($activation->tenant_id)->toBe('org-1')
        ->and($activation->flow_version_id)->toBe($result->version->id)
        ->and($activation->trigger_node_id)->toBe('trigger')
        ->and($activation->driver)->toBe('test.fake')
        ->and($activation->source)->toBe('test.orders')
        ->and($activation->qualifier)->toBeNull()
        ->and($activation->descriptor)->toBe(['account' => 'retail'])
        ->and(json_encode($activation->descriptor))->not->toContain('Tests\\Support');
});

it('replaces the old activation when republishing and keeps one row per flow', function () {
    $first = app(PublishFlow::class)->publish(
        $this->flow,
        triggeredExitGraph('test.orders', ['account' => 'first']),
    );
    $firstActivation = TriggerActivation::withoutTenancy()->sole();
    $flow = $this->flow->fresh()->load('activation');

    $second = app(PublishFlow::class)->publish(
        $flow,
        triggeredExitGraph('test.orders', ['account' => 'second']),
    );
    $replacement = TriggerActivation::withoutTenancy()->sole();

    expect($replacement->id)->not->toBe($firstActivation->id)
        ->and($replacement->flow_version_id)->toBe($second->version->id)
        ->and($replacement->descriptor)->toBe(['account' => 'second'])
        ->and($first->version->fresh())->not->toBeNull()
        ->and($flow->activation->is($replacement))->toBeTrue()
        ->and(TriggerActivation::withoutTenancy()->where('flow_id', $this->flow->id)->count())->toBe(1);
});

it('rolls back a version when activation compilation fails', function () {
    $old = app(PublishFlow::class)->publish($this->flow, triggeredExitGraph());
    $oldActivation = TriggerActivation::withoutTenancy()->sole();

    $compiler = $this->mock(CompileTriggerActivation::class);
    $compiler->shouldReceive('compile')->once()->andThrow(
        new GraphInvalidException(['Trigger activation compilation could not be completed.'])
    );

    expect(fn () => app(PublishFlow::class)->publish($this->flow->fresh(), triggeredExitGraph()))
        ->toThrow(GraphInvalidException::class);

    $flow = $this->flow->fresh();
    expect($flow->current_version_id)->toBe($old->version->id)
        ->and($flow->status)->toBe('active')
        ->and($flow->versions()->count())->toBe(1)
        ->and(TriggerActivation::withoutTenancy()->sole()->is($oldActivation))->toBeTrue();
});

it('rolls back deletion and the new version when activation persistence fails', function () {
    $old = app(PublishFlow::class)->publish($this->flow, triggeredExitGraph());
    $oldActivation = TriggerActivation::withoutTenancy()->sole();

    TriggerActivation::creating(function () {
        throw new RuntimeException('simulated activation persistence failure');
    });

    expect(fn () => app(PublishFlow::class)->publish($this->flow->fresh(), triggeredExitGraph()))
        ->toThrow(RuntimeException::class, 'simulated activation persistence failure');

    expect($this->flow->fresh()->current_version_id)->toBe($old->version->id)
        ->and($this->flow->versions()->count())->toBe(1)
        ->and(TriggerActivation::withoutTenancy()->sole()->is($oldActivation))->toBeTrue();
});

it('rolls back activation replacement when the final flow update fails', function () {
    $old = app(PublishFlow::class)->publish($this->flow, triggeredExitGraph());
    $oldActivation = TriggerActivation::withoutTenancy()->sole();

    Flow::updating(function () {
        throw new RuntimeException('simulated flow update failure');
    });

    expect(fn () => app(PublishFlow::class)->publish($this->flow->fresh(), triggeredExitGraph()))
        ->toThrow(RuntimeException::class, 'simulated flow update failure');

    expect($this->flow->fresh()->current_version_id)->toBe($old->version->id)
        ->and($this->flow->fresh()->status)->toBe('active')
        ->and($this->flow->versions()->count())->toBe(1)
        ->and(TriggerActivation::withoutTenancy()->sole()->is($oldActivation))->toBeTrue();
});

it('turns unsafe extension metadata into a structured graph error without leaking details', function () {
    app(\Nodeflow\Triggers\TriggerNodeRegistry::class)->register(UnsafeMetadataPublicationTriggerNode::class);
    $graph = [
        'start' => 'unsafe-trigger',
        'nodes' => [
            ['id' => 'unsafe-trigger', 'type' => UnsafeMetadataPublicationTriggerNode::type(), 'config' => []],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'unsafe-trigger', 'output' => 'started', 'to' => 'exit']],
    ];

    try {
        app(PublishFlow::class)->publish($this->flow, $graph);
        $exception = null;
    } catch (GraphInvalidException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull()
        ->and($exception->errors())->toHaveCount(1)
        ->and($exception->errors()[0])->toContain('metadata')
        ->and($exception->getMessage())->not->toContain('resource')
        ->and($exception->nodeErrors()[0]['node'])->toBe('unsafe-trigger')
        ->and($this->flow->versions()->count())->toBe(0)
        ->and(TriggerActivation::withoutTenancy()->count())->toBe(0);
});

it('finds active activations by exact driver source and nullable qualifier without tenant scope', function () {
    $nullQualifier = makeRepositoryActivation('org-1', 'active', 'event', 'orders', null);
    $qualified = makeRepositoryActivation('org-2', 'active', 'event', 'orders', 'premium');
    makeRepositoryActivation('org-3', 'paused', 'event', 'orders', null);
    makeRepositoryActivation('org-4', 'draft', 'event', 'orders', null);

    $this->tenant = 'org-9';
    $repository = app(TriggerActivationRepository::class);

    $withoutQualifier = $repository->forDriverSource('event', 'orders');
    $withQualifier = $repository->forDriverSource('event', 'orders', 'premium');

    expect($withoutQualifier)->toHaveCount(1)
        ->and($withoutQualifier[0]->activationId)->toBe($nullQualifier->id)
        ->and($withoutQualifier[0]->tenantId)->toBe('org-1')
        ->and($withQualifier)->toHaveCount(1)
        ->and($withQualifier[0]->activationId)->toBe($qualified->id)
        ->and($withQualifier[0]->tenantId)->toBe('org-2');
});

it('returns pinned immutable snapshots in one query even if current version later changes', function () {
    $activation = makeRepositoryActivation('org-1', 'active', 'event', 'orders', null, ['kind' => 'created']);
    $flow = Flow::withoutTenancy()->findOrFail($activation->flow_id);
    $later = FlowVersion::withoutTenancy()->create([
        'tenant_id' => $flow->tenant_id,
        'flow_id' => $flow->id,
        'version' => 2,
        'graph' => triggeredExitGraph(),
        'content_hash' => 'later-version',
    ]);
    Flow::withoutTenancy()->whereKey($flow->id)->update(['current_version_id' => $later->id]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, 'nodeflow_trigger_activations')) {
            $queries[] = $query->sql;
        }
    });

    $snapshots = app(TriggerActivationRepository::class)->forDriverSource('event', 'orders');

    expect($queries)->toHaveCount(1)
        ->and($snapshots)->toHaveCount(1)
        ->and($snapshots[0]->flowVersionId)->toBe($activation->flow_version_id)
        ->and($snapshots[0]->flowVersionId)->not->toBe($later->id)
        ->and($snapshots[0]->triggerNodeId)->toBe('trigger')
        ->and($snapshots[0]->descriptor)->toBe(['kind' => 'created']);
});

it('looks up webhook snapshots only through a same-flow active webhook activation', function () {
    $webhook = makeRepositoryActivation('org-1', 'active', 'webhook', 'orders', null, ['mode' => 'signed']);
    WebhookEndpoint::create([
        'flow_id' => $webhook->flow_id,
        'token' => 'known-token',
        'signing_secret' => 'secret',
    ]);
    $event = makeRepositoryActivation('org-1', 'active', 'event', 'orders', null);
    WebhookEndpoint::create([
        'flow_id' => $event->flow_id,
        'token' => 'event-token',
        'signing_secret' => 'secret',
    ]);
    $paused = makeRepositoryActivation('org-1', 'paused', 'webhook', 'orders', null);
    WebhookEndpoint::create([
        'flow_id' => $paused->flow_id,
        'token' => 'paused-token',
        'signing_secret' => 'secret',
    ]);

    $repository = app(TriggerActivationRepository::class);
    $snapshot = $repository->forWebhookToken('known-token');

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->activationId)->toBe($webhook->id)
        ->and($snapshot->descriptor)->toBe(['mode' => 'signed'])
        ->and($repository->forWebhookToken('unknown-token'))->toBeNull()
        ->and($repository->forWebhookToken('event-token'))->toBeNull()
        ->and($repository->forWebhookToken('paused-token'))->toBeNull();
});

function makeRepositoryActivation(
    string $tenant,
    string $status,
    string $driver,
    string $source,
    ?string $qualifier,
    array $descriptor = [],
): TriggerActivation {
    return TenancyGuardSuspension::run(function () use ($tenant, $status, $driver, $source, $qualifier, $descriptor) {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => $tenant,
            'name' => "{$tenant} {$driver} flow",
            'status' => $status,
        ]);
        $version = FlowVersion::withoutTenancy()->create([
            'tenant_id' => $tenant,
            'flow_id' => $flow->id,
            'version' => 1,
            'graph' => triggeredExitGraph(),
            'content_hash' => "{$tenant}-{$driver}-{$qualifier}",
        ]);
        Flow::withoutTenancy()->whereKey($flow->id)->update(['current_version_id' => $version->id]);

        return TriggerActivation::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'flow_version_id' => $version->id,
            'tenant_id' => $tenant,
            'driver' => $driver,
            'source' => $source,
            'qualifier' => $qualifier,
            'trigger_node_id' => 'trigger',
            'descriptor' => $descriptor,
        ]);
    });
}
