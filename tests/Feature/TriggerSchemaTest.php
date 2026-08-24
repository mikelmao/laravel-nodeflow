<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nodeflow\Models\Flow;
use Nodeflow\Models\FlowVersion;
use Nodeflow\Models\Run;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Models\WebhookEndpoint;

function makeTriggerSchemaFlow(string $name = 'Triggered flow'): array
{
    $flow = Flow::create([
        'tenant_id' => 'org-1',
        'name' => $name,
        'status' => 'active',
    ]);

    $version = FlowVersion::create([
        'flow_id' => $flow->id,
        'version' => 1,
        'graph' => triggeredExitGraph(),
        'content_hash' => sha1($name),
    ]);

    return [$flow, $version];
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
        ->toContain(['tenant_id'], ['driver'], ['source'], ['qualifier'])
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

it('enforces one activation and webhook endpoint per flow and unique webhook tokens', function () {
    [$flow, $version] = makeTriggerSchemaFlow('First flow');
    [$otherFlow, $otherVersion] = makeTriggerSchemaFlow('Second flow');

    TriggerActivation::create([
        'flow_id' => $flow->id, 'flow_version_id' => $version->id, 'tenant_id' => 'org-1',
        'driver' => 'webhook', 'source' => 'order.created', 'qualifier' => null,
        'trigger_node_id' => 'trigger', 'descriptor' => [],
    ]);
    WebhookEndpoint::create([
        'flow_id' => $flow->id, 'token' => 'shared-token', 'signing_secret' => 'secret',
    ]);

    expect(fn () => TriggerActivation::create([
        'flow_id' => $flow->id, 'flow_version_id' => $otherVersion->id, 'tenant_id' => 'org-1',
        'driver' => 'event', 'source' => 'invoice.created', 'qualifier' => null,
        'trigger_node_id' => 'trigger', 'descriptor' => [],
    ]))->toThrow(QueryException::class)
        ->and(fn () => WebhookEndpoint::create([
            'flow_id' => $flow->id, 'token' => 'another-token', 'signing_secret' => 'secret',
        ]))->toThrow(QueryException::class)
        ->and(fn () => WebhookEndpoint::create([
            'flow_id' => $otherFlow->id, 'token' => 'shared-token', 'signing_secret' => 'secret',
        ]))->toThrow(QueryException::class);
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

it('requires run origins and retains idempotency uniqueness per version', function () {
    [$flow, $version] = makeTriggerSchemaFlow();

    expect(fn () => DB::table('nodeflow_runs')->insert([
        'flow_version_id' => $version->id,
        'tenant_id' => 'org-1',
        'strategy' => 'cohort',
        'status' => 'pending',
    ]))->toThrow(QueryException::class);

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
