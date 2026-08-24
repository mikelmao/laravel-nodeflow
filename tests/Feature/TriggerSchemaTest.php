<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\CrossTenantWriteException;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\InvalidTriggerActivationReferenceException;
use Nodeflow\Models\Run;
use Nodeflow\Models\TenancyUnresolvedException;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Models\WebhookEndpoint;

function makeTriggerSchemaFlow(string $name = 'Triggered flow'): array
{
    return makeTriggerSchemaFlowFor('org-1', $name);
}

function makeTriggerSchemaFlowFor(string $tenantId, string $name): array
{
    return TenancyGuardSuspension::run(function () use ($tenantId, $name) {
        $flow = Flow::withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'status' => 'active',
        ]);

        $version = FlowVersion::withoutTenancy()->create([
            'flow_id' => $flow->id,
            'tenant_id' => $tenantId,
            'version' => 1,
            'graph' => triggeredExitGraph(),
            'content_hash' => sha1($tenantId.$name),
        ]);

        return [$flow, $version];
    });
}

function bindTriggerSchemaTenant(?string $tenantId): void
{
    app()->bind(TenantResolver::class, fn () => new class($tenantId) implements TenantResolver
    {
        public function __construct(private ?string $tenantId) {}

        public function currentTenantId(): ?string
        {
            return $this->tenantId;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return true;
        }
    });
}

function createTriggerSchemaActivation(Flow $flow, FlowVersion $version, array $overrides = []): TriggerActivation
{
    return TriggerActivation::create(array_replace([
        'flow_id' => $flow->id,
        'flow_version_id' => $version->id,
        'tenant_id' => $flow->tenant_id,
        'driver' => 'webhook',
        'source' => 'order.created',
        'qualifier' => null,
        'trigger_node_id' => 'trigger',
        'descriptor' => [],
    ], $overrides));
}

it('stores trigger activations, webhook endpoints, and run origins in the base schema', function () {
    expect(Schema::hasTable('nodeflow_trigger_activations'))->toBeTrue()
        ->and(Schema::hasTable('nodeflow_webhook_endpoints'))->toBeTrue()
        ->and(Schema::hasColumns('nodeflow_trigger_activations', [
            'id', 'flow_id', 'flow_version_id', 'tenant_id', 'driver', 'source',
            'qualifier', 'trigger_node_id', 'descriptor', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('nodeflow_webhook_endpoints', [
            'id', 'flow_id', 'token', 'signing_secret', 'secret_rotated_at',
            'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('nodeflow_runs', [
            'started_via', 'trigger_node_id', 'trigger_data',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('nodeflow_flows', 'trigger_type'))->toBeFalse()
        ->and(Schema::hasColumn('nodeflow_flows', 'trigger_config'))->toBeFalse();

    $activationIndexes = collect(Schema::getIndexes('nodeflow_trigger_activations'));
    $endpointIndexes = collect(Schema::getIndexes('nodeflow_webhook_endpoints'));
    $runIndexes = collect(Schema::getIndexes('nodeflow_runs'));

    expect($activationIndexes->where('unique', true)->pluck('columns')->all())
        ->toContain(['flow_id'], ['flow_version_id'])
        ->and($activationIndexes->pluck('columns')->all())
        ->toContain(
            ['tenant_id'],
            ['driver'],
            ['source'],
            ['qualifier'],
            ['driver', 'source', 'qualifier'],
        )
        ->and($endpointIndexes->where('unique', true)->pluck('columns')->all())
        ->toContain(['flow_id'], ['token'])
        ->and($runIndexes->where('unique', true)->pluck('columns')->all())
        ->toContain(['flow_version_id', 'idempotency_key']);
});

it('casts activation, endpoint, and run origin values and exposes their relations', function () {
    [$flow, $version] = makeTriggerSchemaFlow();

    $activation = TriggerActivation::create([
        'flow_id' => $flow->id,
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'driver' => 'webhook',
        'source' => 'order.created',
        'qualifier' => 'premium',
        'trigger_node_id' => 'trigger',
        'descriptor' => ['path' => 'orders', 'method' => 'POST'],
    ]);
    $endpoint = WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => 'opaque-token',
        'signing_secret' => 'plain-secret',
        'secret_rotated_at' => '2026-08-24 12:30:00',
    ]);
    $run = Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
        'started_via' => 'webhook',
        'trigger_node_id' => 'trigger',
        'trigger_data' => ['delivery_id' => 'delivery-1'],
    ]);

    expect($activation->descriptor)->toBe(['path' => 'orders', 'method' => 'POST'])
        ->and($activation->flow->is($flow))->toBeTrue()
        ->and($activation->flowVersion->is($version))->toBeTrue()
        ->and($flow->activation->is($activation))->toBeTrue()
        ->and($version->activation->is($activation))->toBeTrue()
        ->and($endpoint->flow->is($flow))->toBeTrue()
        ->and($flow->webhookEndpoint->is($endpoint))->toBeTrue()
        ->and($endpoint->signing_secret)->toBe('plain-secret')
        ->and($endpoint->secret_rotated_at?->format('Y-m-d H:i:s'))->toBe('2026-08-24 12:30:00')
        ->and(DB::table('nodeflow_webhook_endpoints')->whereKey($endpoint->id)->value('signing_secret'))
        ->not->toBe('plain-secret')
        ->and($run->trigger_data)->toBe(['delivery_id' => 'delivery-1']);
});

it('freezes an activation snapshot after creation', function (
    string $attribute,
    mixed $replacement,
    string $exception,
    string $message,
) {
    [$flow, $version] = makeTriggerSchemaFlow($attribute);
    $activation = TriggerActivation::create([
        'flow_id' => $flow->id,
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'driver' => 'webhook',
        'source' => 'order.created',
        'qualifier' => 'premium',
        'trigger_node_id' => 'trigger',
        'descriptor' => ['path' => 'orders'],
    ]);

    expect(fn () => $activation->update([$attribute => $replacement]))
        ->toThrow($exception, $message);
})->with([
    'flow reference' => ['flow_id', 999999, \LogicException::class, 'immutable'],
    'version reference' => ['flow_version_id', 999999, \LogicException::class, 'immutable'],
    'tenant reference' => ['tenant_id', 'org-2', \RuntimeException::class, 'may not change after creation'],
    'driver routing key' => ['driver', 'event', \LogicException::class, 'immutable'],
    'source routing key' => ['source', 'invoice.created', \LogicException::class, 'immutable'],
    'qualifier routing key' => ['qualifier', 'standard', \LogicException::class, 'immutable'],
    'trigger node reference' => ['trigger_node_id', 'other-trigger', \LogicException::class, 'immutable'],
    'descriptor snapshot' => ['descriptor', ['path' => 'invoices'], \LogicException::class, 'immutable'],
]);

it('creates an activation only when its flow version and tenant references all match', function () {
    bindTriggerSchemaTenant('org-1');
    [$flow, $version] = makeTriggerSchemaFlow();

    $activation = TriggerActivation::create([
        'flow_id' => $flow->id,
        'flow_version_id' => $version->id,
        'driver' => 'webhook',
        'source' => 'order.created',
        'qualifier' => null,
        'trigger_node_id' => 'trigger',
        'descriptor' => [],
    ]);

    expect($activation->tenant_id)->toBe('org-1');
});

it('refuses a same-tenant version belonging to a different flow', function () {
    [$flow, $unusedVersion] = makeTriggerSchemaFlow('First flow');
    [$unusedFlow, $version] = makeTriggerSchemaFlow('Second flow');

    expect(fn () => createTriggerSchemaActivation($flow, $version))
        ->toThrow(InvalidTriggerActivationReferenceException::class, "does not belong to Flow [{$flow->id}]");
});

it('refuses an activation tenant that differs from its flow tenant', function () {
    [$flow, $version] = makeTriggerSchemaFlowFor('org-2', 'Foreign flow');

    expect(fn () => createTriggerSchemaActivation($flow, $version, ['tenant_id' => 'org-1']))
        ->toThrow(CrossTenantWriteException::class, "flow [{$flow->id}]");
});

it('refuses an activation tenant that differs from its version tenant', function () {
    [$flow, $unusedVersion] = makeTriggerSchemaFlow('Flow with corrupt version');
    $versionId = DB::table('nodeflow_flow_versions')->insertGetId([
        'flow_id' => $flow->id,
        'tenant_id' => 'org-2',
        'version' => 2,
        'graph' => json_encode(triggeredExitGraph(), JSON_THROW_ON_ERROR),
        'content_hash' => 'cross-tenant-version',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $version = FlowVersion::withoutTenancy()->findOrFail($versionId);

    expect(fn () => createTriggerSchemaActivation($flow, $version, ['tenant_id' => 'org-1']))
        ->toThrow(CrossTenantWriteException::class, 'flow_version_id');
});

it('refuses an activation whose flow reference is missing', function () {
    [$unusedFlow, $version] = makeTriggerSchemaFlow('Existing version');

    expect(fn () => createTriggerSchemaActivation(new Flow(['id' => 999999, 'tenant_id' => 'org-1']), $version))
        ->toThrow(InvalidTriggerActivationReferenceException::class, 'Flow [999999] does not exist');
});

it('refuses an activation whose version reference is missing', function () {
    [$flow, $unusedVersion] = makeTriggerSchemaFlow('Existing flow');

    expect(fn () => createTriggerSchemaActivation($flow, new FlowVersion(['id' => 999999])))
        ->toThrow(\Nodeflow\Models\InvalidFlowVersionReferenceException::class, '999999');
});

it('enforces one activation per flow with distinct matching versions', function () {
    [$flow, $version] = makeTriggerSchemaFlow('Flow uniqueness');
    $otherVersion = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 2,
        'graph' => triggeredExitGraph(),
        'content_hash' => 'flow-unique-version-2',
    ]);
    createTriggerSchemaActivation($flow, $version);

    expect(fn () => createTriggerSchemaActivation($flow, $otherVersion))
        ->toThrow(QueryException::class);
});

it('enforces one activation per version independently of flow uniqueness', function () {
    [$flow, $version] = makeTriggerSchemaFlow('Version uniqueness');
    [$otherFlow, $unusedOtherVersion] = makeTriggerSchemaFlow('Unused flow');
    createTriggerSchemaActivation($flow, $version);

    // Query-builder insertion deliberately bypasses the model's cross-flow
    // invariant so this probe violates only the flow_version_id unique key.
    expect(fn () => DB::table('nodeflow_trigger_activations')->insert([
        'flow_id' => $otherFlow->id,
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'driver' => 'event',
        'source' => 'invoice.created',
        'qualifier' => null,
        'trigger_node_id' => 'trigger',
        'descriptor' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('enforces one webhook endpoint per flow and unique webhook tokens', function () {
    [$flow, $version] = makeTriggerSchemaFlow('First endpoint flow');
    [$otherFlow, $otherVersion] = makeTriggerSchemaFlow('Second endpoint flow');
    WebhookEndpoint::create([
        'flow_id' => $flow->id, 'token' => 'shared-token', 'signing_secret' => 'secret',
    ]);

    expect(fn () => WebhookEndpoint::create([
        'flow_id' => $flow->id, 'token' => 'another-token', 'signing_secret' => 'secret',
    ]))->toThrow(QueryException::class)
        ->and(fn () => WebhookEndpoint::create([
            'flow_id' => $otherFlow->id, 'token' => 'shared-token', 'signing_secret' => 'secret',
        ]))->toThrow(QueryException::class);
});

it('refuses to bind a webhook endpoint to another tenants flow', function () {
    bindTriggerSchemaTenant('org-1');
    [$flow, $version] = makeTriggerSchemaFlowFor('org-2', 'Inaccessible flow');

    expect(fn () => WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => 'cross-tenant-token',
        'signing_secret' => 'secret',
    ]))->toThrow(ModelNotFoundException::class, Flow::class);
});

it('refuses endpoint creation when tenant resolution is unavailable', function () {
    [$flow, $version] = makeTriggerSchemaFlow('Unresolved flow');
    config()->set('nodeflow.tenancy', 'resolver');
    bindTriggerSchemaTenant(null);

    expect(fn () => WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => 'unresolved-token',
        'signing_secret' => 'secret',
    ]))->toThrow(TenancyUnresolvedException::class, Flow::class);
});

it('freezes webhook endpoint identity', function (string $attribute) {
    [$flow, $version] = makeTriggerSchemaFlow('Endpoint identity '.$attribute);
    [$otherFlow, $otherVersion] = makeTriggerSchemaFlow('Replacement endpoint flow '.$attribute);
    $endpoint = WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => 'immutable-token-'.$attribute,
        'signing_secret' => 'first-secret',
    ]);
    $replacement = $attribute === 'flow_id' ? $otherFlow->id : 'different-token';

    expect(fn () => $endpoint->update([$attribute => $replacement]))
        ->toThrow(\LogicException::class, 'immutable');
})->with([
    'flow reference' => ['flow_id'],
    'opaque token' => ['token'],
]);

it('rotates webhook secrets without exposing plaintext in serialization', function () {
    [$flow, $version] = makeTriggerSchemaFlow('Rotatable endpoint');
    $endpoint = WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => 'rotatable-token',
        'signing_secret' => 'first-secret',
    ]);

    $endpoint->update([
        'signing_secret' => 'rotated-secret',
        'secret_rotated_at' => '2026-08-24 15:00:00',
    ]);
    $endpoint = $endpoint->fresh();
    $flowArray = $flow->fresh()->load('webhookEndpoint')->toArray();

    expect($endpoint->signing_secret)->toBe('rotated-secret')
        ->and($endpoint->secret_rotated_at?->format('Y-m-d H:i:s'))->toBe('2026-08-24 15:00:00')
        ->and(DB::table('nodeflow_webhook_endpoints')->whereKey($endpoint->id)->value('signing_secret'))
        ->not->toBe('rotated-secret')
        ->and($endpoint->toArray())->not->toHaveKey('signing_secret')
        ->and(json_decode($endpoint->toJson(), true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('signing_secret')
        ->and($flowArray['webhook_endpoint'])->not->toHaveKey('signing_secret');
});

it('does not let trigger projections outlive their referenced records', function () {
    $activationForeignKeys = collect(Schema::getForeignKeys('nodeflow_trigger_activations'));
    $endpointForeignKeys = collect(Schema::getForeignKeys('nodeflow_webhook_endpoints'));

    expect($activationForeignKeys->map(fn (array $key) => Arr::only($key, ['columns', 'foreign_table', 'on_delete']))->all())
        ->toContain(
            ['columns' => ['flow_id'], 'foreign_table' => 'nodeflow_flows', 'on_delete' => 'cascade'],
            ['columns' => ['flow_version_id'], 'foreign_table' => 'nodeflow_flow_versions', 'on_delete' => 'cascade'],
        )
        ->and($endpointForeignKeys->map(fn (array $key) => Arr::only($key, ['columns', 'foreign_table', 'on_delete']))->all())
        ->toContain(
            ['columns' => ['flow_id'], 'foreign_table' => 'nodeflow_flows', 'on_delete' => 'cascade'],
        );
});

it('requires a run started_via origin', function () {
    [$flow, $version] = makeTriggerSchemaFlow();

    expect(fn () => DB::table('nodeflow_runs')->insert([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
        'trigger_node_id' => 'trigger',
    ]))->toThrow(QueryException::class);
});

it('requires a run trigger_node_id origin', function () {
    [$flow, $version] = makeTriggerSchemaFlow();

    expect(fn () => DB::table('nodeflow_runs')->insert([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
        'started_via' => 'manual',
    ]))->toThrow(QueryException::class);
});

it('retains run idempotency uniqueness per version', function () {
    [$flow, $version] = makeTriggerSchemaFlow();

    Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'idempotency_key' => 'same-key',
    ]);

    expect(fn () => Run::create([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
        'started_via' => 'manual',
        'trigger_node_id' => 'trigger',
        'trigger_data' => null,
        'idempotency_key' => 'same-key',
    ]))->toThrow(QueryException::class);
});
