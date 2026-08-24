<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Models\Concerns\TenancyGuardSuspension;
use Nodeflow\Models\Flow;
use Nodeflow\Models\WebhookEndpoint;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\Webhook\WebhookCredentials;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

class SecretManagementWebhookSource implements WebhookTriggerSource
{
    public static function key(): string
    {
        return 'test.secret-management';
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Secret management webhook');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make()->forTenant('org-1', 'user', ['42']);
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
    Route::middleware('web')->prefix('nodeflow')->group(fn () => Nodeflow::routes());
    Route::prefix('nodeflow')->group(fn () => Nodeflow::webhookRoutes());
    Route::getRoutes()->refreshNameLookups();
    Nodeflow::registerTriggerSources([SecretManagementWebhookSource::class]);

    $this->user = new User;
    $this->user->id = 1;
});

it('rotates a webhook secret and returns the plaintext only once', function () {
    Gate::define('nodeflow.update', fn () => true);
    $flow = Flow::create(['name' => 'Rotate secret', 'status' => 'draft']);
    $endpoint = WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => str_repeat('a', 64),
        'signing_secret' => 'old-secret',
    ]);

    $response = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$flow->id}/webhook-secret/rotate")
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonStructure(['secret', 'rotated_at']);

    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->json('secret'))->toHaveLength(64)
        ->and($endpoint->fresh()->signing_secret)->toBe($response->json('secret'))
        ->and($endpoint->fresh()->toArray())->not->toHaveKey('signing_secret');
});

it('rotates through a host parameterized domain route', function () {
    Gate::define('nodeflow.update', fn () => true);
    $flow = Flow::create(['name' => 'Domain rotation', 'status' => 'draft']);
    WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => str_repeat('d', 64),
        'signing_secret' => 'old-secret',
    ]);
    Route::setRoutes(new RouteCollection);
    Route::middleware('web')
        ->domain('{workspace}.example.test')
        ->prefix('admin')
        ->name('tenant.')
        ->group(fn () => Nodeflow::routes());

    $this->actingAs($this->user)
        ->postJson("http://acme.example.test/admin/flows/{$flow->id}/webhook-secret/rotate")
        ->assertOk()
        ->assertJsonStructure(['secret', 'rotated_at']);
});

it('denies unauthorized rotation and 404s cross-tenant or missing endpoints', function () {
    $flow = Flow::create(['name' => 'Denied', 'status' => 'draft']);
    WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => str_repeat('b', 64),
        'signing_secret' => 'secret',
    ]);

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$flow->id}/webhook-secret/rotate")
        ->assertForbidden();

    Gate::define('nodeflow.update', fn () => true);
    $missing = Flow::create(['name' => 'Missing endpoint', 'status' => 'draft']);
    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$missing->id}/webhook-secret/rotate")
        ->assertNotFound();

    $theirs = TenancyGuardSuspension::run(fn () => Flow::withoutTenancy()->create([
        'tenant_id' => 'org-2',
        'name' => 'Cross tenant',
        'status' => 'draft',
    ]));

    $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$theirs->id}/webhook-secret/rotate")
        ->assertNotFound();
});

it('immediately rejects the old secret after rotation and accepts the new one', function () {
    Gate::define('nodeflow.update', fn () => true);
    [$flow, $result] = publishedSecretManagementWebhook();
    $body = '{}';
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $result->webhookUrl,
        server: secretManagementHeaders($timestamp, $body, $result->webhookSecret, 'before-rotation'),
        content: $body,
    )->assertAccepted();

    $newSecret = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$flow->id}/webhook-secret/rotate")
        ->assertOk()
        ->json('secret');

    $this->call(
        'POST',
        $result->webhookUrl,
        server: secretManagementHeaders($timestamp, $body, $result->webhookSecret, 'old-secret'),
        content: $body,
    )->assertUnauthorized();
    $this->call(
        'POST',
        $result->webhookUrl,
        server: secretManagementHeaders($timestamp, $body, $newSecret, 'new-secret'),
        content: $body,
    )->assertAccepted();
});

it('returns webhook credentials only on first authenticated publication', function () {
    Gate::define('nodeflow.publish', fn () => true);
    $flow = Flow::create(['name' => 'HTTP publish', 'status' => 'draft']);
    $graph = secretManagementWebhookGraph();

    $first = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$flow->id}/publish", ['graph' => $graph, 'draft_revision' => 0])
        ->assertOk()
        ->assertHeader('Pragma', 'no-cache')
        ->assertJsonStructure(['webhook_url', 'webhook_secret']);
    $second = $this->actingAs($this->user)
        ->postJson("/nodeflow/flows/{$flow->id}/publish", ['graph' => $graph, 'draft_revision' => 0])
        ->assertOk()
        ->assertJsonStructure(['webhook_url'])
        ->assertJsonMissingPath('webhook_secret');

    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->json('webhook_secret'))->toHaveLength(64)
        ->and($second->json('webhook_url'))->toBe($first->json('webhook_url'))
        ->and($second->headers->get('Cache-Control'))->not->toContain('no-store')
        ->and($flow->webhookEndpoint()->firstOrFail()->toArray())->not->toHaveKey('signing_secret')
        ->and($flow->fresh()->toArray())->not->toHaveKey('webhook_secret');
});

it('returns secret-free webhook endpoint metadata and a named rotation URL to the editor', function () {
    Gate::define('nodeflow.update', fn () => true);
    [$flow, $result] = publishedSecretManagementWebhook();
    $endpoint = $flow->webhookEndpoint()->firstOrFail();

    $response = secretManagementEditPage($this, "/nodeflow/flows/{$flow->id}/edit")
        ->assertOk()
        ->assertJsonPath('props.webhook.endpoint_url', $result->webhookUrl)
        ->assertJsonPath('props.webhook.active', true)
        ->assertJsonPath('props.webhook.secret_rotated_at', $endpoint->secret_rotated_at?->toIso8601String())
        ->assertJsonPath(
            'props.urls.rotate_webhook_secret',
            "http://localhost/nodeflow/flows/{$flow->id}/webhook-secret/rotate",
        );

    expect(array_keys($response->json('props.webhook')))->toBe(['endpoint_url', 'active', 'secret_rotated_at'])
        ->and($response->getContent())->not->toContain('signing_secret', $result->webhookSecret);
});

it('marks retained webhook metadata inactive after publishing another trigger', function () {
    Gate::define('nodeflow.update', fn () => true);
    [$flow, $result] = publishedSecretManagementWebhook();
    app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph());

    secretManagementEditPage($this, "/nodeflow/flows/{$flow->id}/edit")
        ->assertOk()
        ->assertJsonPath('props.webhook.endpoint_url', $result->webhookUrl)
        ->assertJsonPath('props.webhook.active', false);
});

it('resolves prefixed rotation routes while safely omitting an unresolvable public URL', function () {
    Gate::define('nodeflow.update', fn () => true);
    [$flow] = publishedSecretManagementWebhook();
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    Route::middleware('web')->prefix('admin')->name('admin.')->group(fn () => Nodeflow::routes());
    Route::prefix('{workspace}/hooks')->group(fn () => Nodeflow::webhookRoutes());
    Route::getRoutes()->refreshNameLookups();

    secretManagementEditPage($this, "/admin/flows/{$flow->id}/edit")
        ->assertOk()
        ->assertJsonPath('props.webhook.endpoint_url', null)
        ->assertJsonPath(
            'props.urls.rotate_webhook_secret',
            "http://localhost/admin/flows/{$flow->id}/webhook-secret/rotate",
        );
});

it('rolls a secret rotation back when a post-write hook fails', function () {
    $flow = Flow::create(['name' => 'Rollback rotation', 'status' => 'draft']);
    $endpoint = WebhookEndpoint::create([
        'flow_id' => $flow->id,
        'token' => str_repeat('c', 64),
        'signing_secret' => 'original-secret',
    ]);
    WebhookEndpoint::updated(fn () => throw new RuntimeException('rotation hook failed'));

    expect(fn () => app(WebhookCredentials::class)->rotate($flow))
        ->toThrow(RuntimeException::class, 'rotation hook failed')
        ->and($endpoint->fresh()->signing_secret)->toBe('original-secret');
});

function publishedSecretManagementWebhook(): array
{
    $flow = Flow::create(['name' => 'Published secret flow', 'status' => 'draft']);
    $result = app(PublishFlow::class)->publish($flow, secretManagementWebhookGraph());

    return [$flow->fresh(), $result];
}

function secretManagementWebhookGraph(): array
{
    return [
        'start' => 'trigger',
        'nodes' => [
            ['id' => 'trigger', 'type' => 'core.trigger.webhook', 'config' => [
                'source' => SecretManagementWebhookSource::key(),
            ]],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'trigger', 'output' => 'started', 'to' => 'exit']],
    ];
}

function secretManagementHeaders(string $timestamp, string $body, string $secret, string $key): array
{
    return [
        'HTTP_X_NODEFLOW_TIMESTAMP' => $timestamp,
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        'HTTP_IDEMPOTENCY_KEY' => $key,
        'CONTENT_TYPE' => 'application/json',
    ];
}

function secretManagementEditPage($test, string $url)
{
    return $test->actingAs($test->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->get($url);
}
