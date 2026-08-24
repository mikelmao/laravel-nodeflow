<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Queue;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Nodeflow\Contracts\TenantResolver;
use Nodeflow\Contracts\TriggerSource;
use Nodeflow\Engine\FakeWorkflowEngine;
use Nodeflow\Engine\WorkflowEngine;
use Nodeflow\Models\Flow;
use Nodeflow\Models\Run;
use Nodeflow\Models\TriggerActivation;
use Nodeflow\Models\WebhookEndpoint;
use Nodeflow\Nodeflow;
use Nodeflow\Publishing\PublishFlow;
use Nodeflow\Schema\Field;
use Nodeflow\Schema\TriggerDefinition;
use Nodeflow\Triggers\TriggerMatch;
use Nodeflow\Triggers\TriggerOccurrence;
use Nodeflow\Triggers\Webhook\WebhookOccurrence;
use Nodeflow\Triggers\Webhook\WebhookCredentials;
use Nodeflow\Triggers\Webhook\WebhookSourceFailure;
use Nodeflow\Triggers\Webhook\WebhookSourceRejected;
use Nodeflow\Triggers\TriggerDriverRegistry;
use Nodeflow\Triggers\TriggerSourceRegistry;
use Nodeflow\Triggers\Webhook\WebhookTriggerSource;

class HttpContractWebhookSource implements WebhookTriggerSource
{
    public static ?Closure $resolver = null;

    public static int $calls = 0;

    public static array $lastConfig = [];

    public static function key(): string
    {
        return 'test.http-contract';
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('HTTP contract webhook')
            ->fields([Field::text('account')->required()]);
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        self::$calls++;
        self::$lastConfig = $config;

        if (self::$resolver !== null) {
            return (self::$resolver)($occurrence, $config);
        }

        expect($occurrence->payload)->toBeInstanceOf(WebhookOccurrence::class);

        return TriggerMatch::make()->forTenant(
            'org-1',
            'user',
            [(string) $occurrence->payload->payload['user_id']],
            ['account' => $config['account']],
            'source-controlled-id',
        );
    }
}

class IncompatibleHttpContractWebhookSource implements TriggerSource
{
    public static function key(): string
    {
        return HttpContractWebhookSource::key();
    }

    public static function driver(): string
    {
        return 'webhook';
    }

    public function definition(): TriggerDefinition
    {
        return TriggerDefinition::make('Incompatible webhook source');
    }

    public function resolve(TriggerOccurrence $occurrence, array $config): TriggerMatch
    {
        return TriggerMatch::make();
    }
}

beforeEach(function () {
    HttpContractWebhookSource::$resolver = null;
    HttpContractWebhookSource::$calls = 0;
    HttpContractWebhookSource::$lastConfig = [];
    $this->tenant = 'org-1';
    $this->unownedWebhookSubjects = [];
    Nodeflow::registerTriggerSources([HttpContractWebhookSource::class]);
    app()->bind(TenantResolver::class, fn () => new class($this) implements TenantResolver
    {
        public function __construct(private $test) {}

        public function currentTenantId(): ?string
        {
            return $this->test->tenant;
        }

        public function ownsSubject(string $tenantId, string $subjectType, string $subjectId): bool
        {
            return $tenantId === 'org-1'
                && ! in_array($subjectId, $this->test->unownedWebhookSubjects, true);
        }
    });

    Route::prefix('nodeflow')->group(fn () => Nodeflow::webhookRoutes());
    Route::getRoutes()->refreshNameLookups();
});

afterEach(function () {
    HttpContractWebhookSource::$resolver = null;
    HttpContractWebhookSource::$calls = 0;
    HttpContractWebhookSource::$lastConfig = [];
});

it('accepts a signed idempotent webhook and returns the same run on retry', function () {
    expect(Route::has('nodeflow.webhooks.receive'))->toBeTrue();
    [$url, $secret] = publishedHttpContractWebhook();
    expect($url)->toBeString();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $headers = signedWebhookHeaders($timestamp, $body, $secret, 'delivery-1');

    $first = $this->call('POST', $url, server: $headers, content: $body);
    $second = $this->call('POST', $url, server: $headers, content: $body);

    $first->assertAccepted()->assertJsonPath('duplicate', false);
    $second->assertAccepted()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('run_id', $first->json('run_id'));

    expect(Run::withoutTenancy()->sole()->trigger_data)->toBe(['account' => 'retail'])
        ->and(HttpContractWebhookSource::$lastConfig)->toBe(['account' => 'retail']);
});

it('uses the required delivery identity even when a source supplies its own occurrence id', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $first = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'delivery-a'),
        content: $body,
    )->assertAccepted();
    $second = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'delivery-b'),
        content: $body,
    )->assertAccepted();

    expect($second->json('run_id'))->not->toBe($first->json('run_id'))
        ->and(Run::withoutTenancy()->count())->toBe(2);
});

it('keeps one endpoint identity across webhook republication and trigger changes', function () {
    [$firstUrl, $firstSecret, $flow] = publishedHttpContractWebhook();
    $firstEndpoint = $flow->webhookEndpoint()->firstOrFail();

    $second = app(PublishFlow::class)->publish($flow->fresh(), webhookHttpContractGraph(['account' => 'wholesale']));
    $fake = app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph());
    $third = app(PublishFlow::class)->publish($flow->fresh(), webhookHttpContractGraph(['account' => 'enterprise']));
    $endpoint = $flow->webhookEndpoint()->firstOrFail();

    expect($firstSecret)->toHaveLength(64)
        ->and($firstUrl)->toContain($firstEndpoint->token)
        ->and($second->webhookUrl)->toBe($firstUrl)
        ->and($second->webhookSecret)->toBeNull()
        ->and($fake->webhookUrl)->toBeNull()
        ->and($fake->webhookSecret)->toBeNull()
        ->and($third->webhookUrl)->toBe($firstUrl)
        ->and($third->webhookSecret)->toBeNull()
        ->and($endpoint->id)->toBe($firstEndpoint->id)
        ->and($endpoint->token)->toBe($firstEndpoint->token)
        ->and($endpoint->signing_secret)->toBe($firstSecret);
});

it('returns 404 for unknown inactive and non-webhook tokens before source work', function () {
    [$url, $secret, $flow] = publishedHttpContractWebhook();
    $body = '{not-json';
    $timestamp = (string) now()->timestamp;

    $this->call('POST', '/nodeflow/hooks/'.str_repeat('f', 64), content: $body)->assertNotFound();

    $flow->update(['status' => 'paused']);
    $this->call('POST', $url, content: $body)->assertNotFound();

    $flow->update(['status' => 'active']);
    app(PublishFlow::class)->publish($flow->fresh(), triggeredExitGraph());
    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $body, $secret, 'ignored'), content: $body)
        ->assertNotFound();

    expect(HttpContractWebhookSource::$calls)->toBe(0);
});

it('rejects missing malformed expired and bad signatures before JSON decoding', function (array $headers) {
    [$url] = publishedHttpContractWebhook();

    $this->call('POST', $url, server: $headers, content: '{not-json')
        ->assertUnauthorized()
        ->assertJsonMissing(['message' => 'The webhook body must contain valid JSON.']);

    expect(HttpContractWebhookSource::$calls)->toBe(0);
})->with([
    'missing headers' => [[]],
    'malformed timestamp' => [[
        'HTTP_X_NODEFLOW_TIMESTAMP' => '12seconds',
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.str_repeat('a', 64),
    ]],
    'malformed digest' => [[
        'HTTP_X_NODEFLOW_TIMESTAMP' => '1',
        'HTTP_X_NODEFLOW_SIGNATURE' => 'md5='.str_repeat('a', 32),
    ]],
    'expired' => [[
        'HTTP_X_NODEFLOW_TIMESTAMP' => '1',
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.str_repeat('a', 64),
    ]],
    'bad digest' => [[
        'HTTP_X_NODEFLOW_TIMESTAMP' => (string) time(),
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.str_repeat('a', 64),
    ]],
]);

it('accepts both replay-window boundaries and rejects just beyond them', function (int $offset, int $status) {
    $this->travelTo('2026-08-24 12:00:00');
    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) (now()->timestamp + $offset);

    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $body, $secret, 'boundary-'.$offset), content: $body)
        ->assertStatus($status);
})->with([
    'past boundary' => [-300, 202],
    'future boundary' => [300, 202],
    'past beyond boundary' => [-301, 401],
    'future beyond boundary' => [301, 401],
]);

it('verifies the exact raw request bytes', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $raw = "{\n  \"user_id\": \"42\"\n}";
    $canonical = '{"user_id":"42"}';
    $timestamp = (string) now()->timestamp;

    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $raw, $secret, 'raw-1'), content: $raw)
        ->assertAccepted();
    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $canonical, $secret, 'raw-2'), content: $raw)
        ->assertUnauthorized();
});

it('rejects an oversized raw body before checking its digest', function () {
    [$url] = publishedHttpContractWebhook();
    config()->set('nodeflow.webhooks.max_body_bytes', 4);
    $body = '{"user_id":"42"}';

    $this->call('POST', $url, server: [
        'HTTP_X_NODEFLOW_TIMESTAMP' => (string) now()->timestamp,
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.str_repeat('0', 64),
        'HTTP_IDEMPOTENCY_KEY' => 'oversized-bad-digest',
    ], content: $body)->assertStatus(413);

    expect(HttpContractWebhookSource::$calls)->toBe(0);
});

it('rejects missing blank and oversized idempotency keys after signature verification', function (?string $key) {
    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $headers = signedWebhookHeaders($timestamp, $body, $secret, $key ?? 'placeholder');

    if ($key === null) {
        unset($headers['HTTP_IDEMPOTENCY_KEY']);
    } else {
        $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
    }

    $this->call('POST', $url, server: $headers, content: $body)->assertUnprocessable();
    expect(HttpContractWebhookSource::$calls)->toBe(0);
})->with([
    'missing' => [null],
    'blank' => ['   '],
    'oversized' => [str_repeat('x', 256)],
]);

it('rejects malformed JSON only after a valid signature', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $body = '{not-json';
    $timestamp = (string) now()->timestamp;

    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $body, $secret, 'bad-json'), content: $body)
        ->assertUnprocessable()
        ->assertJsonMissing(['message' => 'Invalid webhook signature.']);
    expect(HttpContractWebhookSource::$calls)->toBe(0);
});

it('normalizes surrounding idempotency-key whitespace before creating identity', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $first = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, '  delivery-normalized  '),
        content: $body,
    )->assertAccepted()->assertJsonPath('duplicate', false);
    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'delivery-normalized'),
        content: $body,
    )->assertAccepted()
        ->assertJsonPath('duplicate', true)
        ->assertJsonPath('run_id', $first->json('run_id'));
});

it('rejects oversized raw bodies and invalid webhook limits fail closed', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    config()->set('nodeflow.webhooks.max_body_bytes', 8);
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $body, $secret, 'too-large'), content: $body)
        ->assertStatus(413);

    config()->set('nodeflow.webhooks.max_body_bytes', '8');
    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, '{}', $secret, 'bad-config'), content: '{}')
        ->assertStatus(503);

    config()->set('nodeflow.webhooks.max_body_bytes', 100);
    config()->set('nodeflow.webhooks.replay_window_seconds', 0);
    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, '{}', $secret, 'bad-window'), content: '{}')
        ->assertStatus(503);
});

it('rejects invalid webhook source audience shapes without leaking source exceptions', function (Closure $resolver) {
    [$url, $secret] = publishedHttpContractWebhook();
    HttpContractWebhookSource::$resolver = $resolver;
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $response = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'bad-match'),
        content: $body,
    )->assertUnprocessable();

    expect($response->getContent())->not->toContain('sensitive-source-detail')
        ->and(Run::withoutTenancy()->count())->toBe(0);
})->with([
    'zero matches' => [fn () => TriggerMatch::make()],
    'wrong tenant' => [fn () => TriggerMatch::make()->forTenant('org-2', 'user', ['1'])],
    'multiple tenants' => [fn () => TriggerMatch::make()
        ->forTenant('org-1', 'user', ['1'])
        ->forTenant('org-2', 'user', ['2'])],
    'empty audience' => [fn () => TriggerMatch::make()->forTenant('org-1', 'user', [])],
    'explicit source rejection' => [fn () => throw new WebhookSourceRejected('sensitive-source-detail')],
]);

it('returns a retryable failure and recovers the same idempotent run after engine start fails', function () {
    Queue::fake();
    $engine = new class implements WorkflowEngine
    {
        public int $calls = 0;

        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            $this->calls++;

            if ($this->calls === 1) {
                throw new RuntimeException('engine unavailable');
            }

            return (string) $instanceId;
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}

        public function cancel(string $workflowId): void {}

        public function isRunning(string $workflowId): bool { return true; }
    };
    app()->instance(WorkflowEngine::class, $engine);
    app()->forgetInstance(\Nodeflow\Execution\CreateRun::class);
    app()->forgetInstance(\Nodeflow\Triggers\TriggerRunStarter::class);
    app()->forgetInstance(\Nodeflow\Triggers\Webhook\WebhookTriggerDriver::class);

    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;
    $headers = signedWebhookHeaders($timestamp, $body, $secret, 'engine-retry');

    $this->call('POST', $url, server: $headers, content: $body)->assertStatus(503);
    $run = Run::withoutTenancy()->sole();
    $this->call('POST', $url, server: $headers, content: $body)
        ->assertAccepted()
        ->assertJsonPath('run_id', $run->id)
        ->assertJsonPath('duplicate', true);

    expect(Run::withoutTenancy()->count())->toBe(1)
        ->and($engine->calls)->toBe(2);
});

it('preserves a nonempty lazy webhook audience after checking it for emptiness', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $replays = 0;
    HttpContractWebhookSource::$resolver = function () use (&$replays): TriggerMatch {
        return TriggerMatch::make()->forTenant('org-1', 'user', function () use (&$replays): array {
            $replays++;

            return [10, 20];
        });
    };
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'lazy-nonempty'),
        content: $body,
    )->assertAccepted();

    expect($replays)->toBe(2)
        ->and(Run::withoutTenancy()->sole()->subjects()->pluck('subject_id')->all())->toBe(['10', '20']);
});

it('rejects an empty lazy webhook audience', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    HttpContractWebhookSource::$resolver = fn (): TriggerMatch => TriggerMatch::make()
        ->forTenant('org-1', 'user', fn (): array => []);
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'lazy-empty'),
        content: $body,
    )->assertUnprocessable();

    expect(Run::withoutTenancy()->count())->toBe(0);
});

it('sanitizes a lazy webhook audience failure during the empty check', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $reported = new class implements ExceptionHandler
    {
        public array $exceptions = [];

        public function report(Throwable $e) { $this->exceptions[] = $e; }

        public function shouldReport(Throwable $e) { return true; }

        public function render($request, Throwable $e) { throw $e; }

        public function renderForConsole($output, Throwable $e): void {}
    };
    app()->instance(ExceptionHandler::class, $reported);
    HttpContractWebhookSource::$resolver = fn (): TriggerMatch => TriggerMatch::make()
        ->forTenant('org-1', 'user', fn (): array => throw new RuntimeException('raw-body:secret-before-yield'));
    $body = json_encode(['password' => 'secret-before-yield'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $response = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'lazy-before-yield'),
        content: $body,
    )->assertStatus(503)->assertJsonPath('message', 'The webhook run could not be started.');

    expect($response->getContent())->not->toContain('secret-before-yield', 'raw-body')
        ->and($reported->exceptions)->toHaveCount(1)
        ->and($reported->exceptions[0])->toBeInstanceOf(WebhookSourceFailure::class)
        ->and($reported->exceptions[0]->getPrevious())->toBeNull()
        ->and($reported->exceptions[0]->getMessage())->not->toContain('secret-before-yield', 'raw-body');
});

it('sanitizes a lazy webhook audience failure during run traversal', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $reported = new class implements ExceptionHandler
    {
        public array $exceptions = [];

        public function report(Throwable $e) { $this->exceptions[] = $e; }

        public function shouldReport(Throwable $e) { return true; }

        public function render($request, Throwable $e) { throw $e; }

        public function renderForConsole($output, Throwable $e): void {}
    };
    app()->instance(ExceptionHandler::class, $reported);
    HttpContractWebhookSource::$resolver = fn (): TriggerMatch => TriggerMatch::make()
        ->forTenant('org-1', 'user', function (): Generator {
            yield '10';

            throw new RuntimeException('raw-body:secret-after-yield');
        });
    $body = json_encode(['password' => 'secret-after-yield'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $response = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'lazy-after-yield'),
        content: $body,
    )->assertStatus(503)->assertJsonPath('message', 'The webhook run could not be started.');

    expect($response->getContent())->not->toContain('secret-after-yield', 'raw-body')
        ->and($reported->exceptions)->toHaveCount(1)
        ->and($reported->exceptions[0])->toBeInstanceOf(WebhookSourceFailure::class)
        ->and($reported->exceptions[0]->getPrevious())->toBeNull()
        ->and($reported->exceptions[0]->getMessage())->not->toContain('secret-after-yield', 'raw-body');
});

it('translates malformed lazy webhook audiences as source failures', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    HttpContractWebhookSource::$resolver = fn (): TriggerMatch => TriggerMatch::make()
        ->forTenant('org-1', 'user', fn (): array => ['  ']);
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $response = $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'lazy-malformed'),
        content: $body,
    )->assertStatus(503);

    expect($response->getContent())->not->toContain('blank subject ID')
        ->and(Run::withoutTenancy()->count())->toBe(0);
});

it('keeps a start-failure response retryable when the host reporter also fails', function () {
    Queue::fake();
    app()->instance(ExceptionHandler::class, new class implements ExceptionHandler
    {
        public function report(Throwable $e) { throw new RuntimeException('reporter unavailable'); }

        public function shouldReport(Throwable $e) { return true; }

        public function render($request, Throwable $e) { throw $e; }

        public function renderForConsole($output, Throwable $e): void {}
    });
    app()->instance(WorkflowEngine::class, new class implements WorkflowEngine
    {
        public function start(string $workflowClass, array $args, ?string $instanceId = null): string
        {
            throw new RuntimeException('engine unavailable');
        }

        public function signal(string $workflowId, string $method, array $args = []): void {}

        public function cancel(string $workflowId): void {}

        public function isRunning(string $workflowId): bool { return false; }
    });
    app()->forgetInstance(\Nodeflow\Execution\CreateRun::class);
    app()->forgetInstance(\Nodeflow\Triggers\TriggerRunStarter::class);
    app()->forgetInstance(\Nodeflow\Triggers\Webhook\WebhookTriggerDriver::class);
    [$url, $secret] = publishedHttpContractWebhook();
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'reporter-failure'),
        content: $body,
    )->assertStatus(503)->assertJsonPath('message', 'The webhook run could not be started.');
});

it('reports unexpected source failures as sanitized retryable infrastructure errors', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $reported = new class implements ExceptionHandler
    {
        public array $exceptions = [];

        public function report(Throwable $e) { $this->exceptions[] = $e; }

        public function shouldReport(Throwable $e) { return true; }

        public function render($request, Throwable $e) { throw $e; }

        public function renderForConsole($output, Throwable $e): void {}
    };
    app()->instance(ExceptionHandler::class, $reported);
    HttpContractWebhookSource::$resolver = fn () => throw new RuntimeException('raw-body:{"password":"secret"}');
    $body = json_encode(['password' => 'secret'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'source-report'),
        content: $body,
    )->assertStatus(503);

    expect($reported->exceptions)->toHaveCount(1)
        ->and($reported->exceptions[0]->getMessage())->toBe('Webhook source resolution failed.')
        ->and($reported->exceptions[0]->getPrevious())->toBeNull()
        ->and($reported->exceptions[0]->getMessage())->not->toContain('raw-body', 'password', 'secret');
});

it('treats a source registration removed after publication as retryable infrastructure drift', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $reported = new class implements ExceptionHandler
    {
        public array $exceptions = [];

        public function report(Throwable $e) { $this->exceptions[] = $e; }

        public function shouldReport(Throwable $e) { return true; }

        public function render($request, Throwable $e) { throw $e; }

        public function renderForConsole($output, Throwable $e): void {}
    };
    app()->instance(ExceptionHandler::class, $reported);
    app()->instance(TriggerSourceRegistry::class, new TriggerSourceRegistry(app(TriggerDriverRegistry::class)));
    app()->forgetInstance(\Nodeflow\Triggers\Webhook\WebhookTriggerDriver::class);
    $body = '{}';
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'missing-registration'),
        content: $body,
    )->assertStatus(503);

    expect($reported->exceptions)->toHaveCount(1)
        ->and($reported->exceptions[0])->toBeInstanceOf(RuntimeException::class)
        ->and($reported->exceptions[0]->getPrevious())->toBeNull()
        ->and($reported->exceptions[0]->getMessage())->not->toContain(HttpContractWebhookSource::key());
});

it('treats an incompatible replacement source as retryable infrastructure drift', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $sources = new TriggerSourceRegistry(app(TriggerDriverRegistry::class));
    $sources->register(IncompatibleHttpContractWebhookSource::class);
    app()->instance(TriggerSourceRegistry::class, $sources);
    app()->forgetInstance(\Nodeflow\Triggers\Webhook\WebhookTriggerDriver::class);
    $body = '{}';
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'incompatible-registration'),
        content: $body,
    )->assertStatus(503);
});

it('treats an unowned source audience as an invalid webhook match', function () {
    [$url, $secret] = publishedHttpContractWebhook();
    $this->unownedWebhookSubjects = ['forbidden'];
    HttpContractWebhookSource::$resolver = fn () => TriggerMatch::make()
        ->forTenant('org-1', 'user', ['forbidden']);
    $body = '{}';
    $timestamp = (string) now()->timestamp;

    $this->call(
        'POST',
        $url,
        server: signedWebhookHeaders($timestamp, $body, $secret, 'unowned-subject'),
        content: $body,
    )->assertUnprocessable();

    expect(Run::withoutTenancy()->count())->toBe(0);
});

it('pins a request to the activation resolved before concurrent republication', function () {
    [$url, $secret, $flow, $first] = publishedHttpContractWebhook();
    HttpContractWebhookSource::$resolver = function (TriggerOccurrence $occurrence) use ($flow) {
        app(PublishFlow::class)->publish($flow->fresh(), webhookHttpContractGraph(['account' => 'later']));

        return TriggerMatch::make()->forTenant(
            'org-1',
            'user',
            [(string) $occurrence->payload->payload['user_id']],
            ['snapshot' => 'first'],
        );
    };
    $body = json_encode(['user_id' => '42'], JSON_THROW_ON_ERROR);
    $timestamp = (string) now()->timestamp;

    $this->call('POST', $url, server: signedWebhookHeaders($timestamp, $body, $secret, 'pinned'), content: $body)
        ->assertAccepted();

    expect(Run::withoutTenancy()->sole()->flow_version_id)->toBe($first->version->id)
        ->and($flow->fresh()->current_version_id)->not->toBe($first->version->id);
});

it('does not register the public webhook with editor routes and respects host route groups', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    Route::middleware('web')->prefix('admin')->group(fn () => Nodeflow::routes());

    expect(collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'admin/hooks/{token}'))->toBeFalse();

    Route::domain('flows.example.test')->prefix('automations')->name('tenant.')->group(
        fn () => Nodeflow::webhookRoutes(),
    );
    Route::getRoutes()->refreshNameLookups();
    $route = Route::getRoutes()->getByName('tenant.nodeflow.webhooks.receive');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('automations/hooks/{token}')
        ->and($route->getDomain())->toBe('flows.example.test')
        ->and($route->gatherMiddleware())->toBe([]);
});

it('publishes safely without registered webhook routes and rolls endpoint creation back on publication failure', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    $flow = Flow::create(['name' => 'No route', 'status' => 'draft']);
    $result = app(PublishFlow::class)->publish($flow, webhookHttpContractGraph());

    expect($result->webhookUrl)->toBeNull()
        ->and($result->webhookSecret)->toHaveLength(64)
        ->and($flow->webhookEndpoint()->exists())->toBeTrue();

    $broken = Flow::create(['name' => 'Rollback endpoint', 'status' => 'draft']);
    \Nodeflow\Models\Flow::updating(function (Flow $saving) use ($broken) {
        if ($saving->is($broken) && $saving->status === 'active') {
            throw new RuntimeException('fail after endpoint');
        }
    });

    expect(fn () => app(PublishFlow::class)->publish($broken, webhookHttpContractGraph()))
        ->toThrow(RuntimeException::class, 'fail after endpoint')
        ->and(WebhookEndpoint::query()->where('flow_id', $broken->id)->exists())->toBeFalse();
});

it('creates webhook credentials from the trusted flow in a tenantless queue context', function () {
    $flow = Flow::create(['name' => 'Queued publication', 'status' => 'draft']);
    $this->tenant = null;
    config()->set('nodeflow.tenancy', 'resolver');

    $result = app(PublishFlow::class)->publish($flow, webhookHttpContractGraph());
    $endpoint = WebhookEndpoint::query()->where('flow_id', $flow->id)->sole();
    $activation = TriggerActivation::withoutTenancy()->where('flow_id', $flow->id)->sole();

    expect($endpoint->flow_id)->toBe($flow->id)
        ->and($endpoint->signing_secret)->toBe($result->webhookSecret)
        ->and($activation->flow_id)->toBe($flow->id)
        ->and($activation->flow_version_id)->toBe($result->version->id)
        ->and($activation->tenant_id)->toBe('org-1')
        ->and($result->version->tenant_id)->toBe('org-1');
});

it('publishes safely when a named webhook route needs host-owned parameters', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    Route::prefix('{workspace}/automations')->group(fn () => Nodeflow::webhookRoutes());
    Route::getRoutes()->refreshNameLookups();
    $flow = Flow::create(['name' => 'Parameterized host route', 'status' => 'draft']);

    $result = app(PublishFlow::class)->publish($flow, webhookHttpContractGraph());

    expect($result->webhookUrl)->toBeNull()
        ->and($result->webhookSecret)->toHaveLength(64)
        ->and($flow->webhookEndpoint()->exists())->toBeTrue();
});

it('does not misclassify an endpoint database outage as a token collision', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    $flow = Flow::create(['name' => 'Database outage', 'status' => 'draft']);
    $attempts = 0;
    WebhookEndpoint::creating(function () use (&$attempts) {
        $attempts++;
        \Illuminate\Support\Facades\DB::select('select * from nodeflow_missing_webhook_table');
    });

    expect(fn () => app(PublishFlow::class)->publish($flow, webhookHttpContractGraph()))
        ->toThrow(QueryException::class)
        ->and($attempts)->toBe(1)
        ->and(WebhookEndpoint::query()->where('flow_id', $flow->id)->exists())->toBeFalse();
});

it('retries a colliding endpoint token with fresh credentials', function () {
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);
    $occupiedFlow = Flow::create(['name' => 'Occupied token', 'status' => 'draft']);
    $collision = str_repeat('d', 64);
    WebhookEndpoint::create([
        'flow_id' => $occupiedFlow->id,
        'token' => $collision,
        'signing_secret' => str_repeat('e', 64),
    ]);
    $values = [str_repeat('1', 64), $collision, str_repeat('2', 64), str_repeat('3', 64)];
    app()->instance(WebhookCredentials::class, new WebhookCredentials(
        function () use (&$values): string {
            return array_shift($values);
        },
    ));
    $flow = Flow::create(['name' => 'Collision retry', 'status' => 'draft']);

    $result = app(PublishFlow::class)->publish($flow, webhookHttpContractGraph());
    $endpoint = $flow->webhookEndpoint()->firstOrFail();

    expect($endpoint->token)->toBe(str_repeat('3', 64))
        ->and($endpoint->signing_secret)->toBe(str_repeat('2', 64))
        ->and($result->webhookSecret)->toBe(str_repeat('2', 64));
});

function publishedHttpContractWebhook(array $config = []): array
{
    $flow = Flow::create(['name' => 'Webhook flow', 'status' => 'draft']);
    $result = app(PublishFlow::class)->publish($flow, webhookHttpContractGraph($config));

    return [$result->webhookUrl, $result->webhookSecret, $flow->fresh(), $result];
}

function webhookHttpContractGraph(array $config = []): array
{
    return [
        'start' => 'trigger',
        'nodes' => [
            [
                'id' => 'trigger',
                'type' => 'core.trigger.webhook',
                'config' => [
                    'source' => HttpContractWebhookSource::key(),
                    'account' => 'retail',
                    ...$config,
                ],
            ],
            ['id' => 'exit', 'type' => 'core.exit', 'config' => []],
        ],
        'edges' => [['from' => 'trigger', 'output' => 'started', 'to' => 'exit']],
    ];
}

function signedWebhookHeaders(string $timestamp, string $body, string $secret, string $key): array
{
    $digest = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return [
        'HTTP_X_NODEFLOW_TIMESTAMP' => $timestamp,
        'HTTP_X_NODEFLOW_SIGNATURE' => 'sha256='.$digest,
        'HTTP_IDEMPOTENCY_KEY' => $key,
        'CONTENT_TYPE' => 'application/json',
    ];
}
